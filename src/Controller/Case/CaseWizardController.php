<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Court\CourtResolveResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\Step4ConfirmationData;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\IssueSeverity;
use App\Form\Wizard\Step0DocumentsType;
use App\Form\Wizard\Step1CreditorType;
use App\Form\Wizard\Step2DebtorsType;
use App\Form\Wizard\Step3ClaimType;
use App\Form\Wizard\Step4ConfirmationType;
use App\Message\ExtractDataMessage;
use App\Repository\CreditorRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Court\CompetentCourtResolver;
use App\Service\Document\DocumentUploadService;
use App\Service\Extraction\PrefillFromExtractionService;
use App\Service\Validation\OpAdmissibilityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Pas 3.0 — step 0 (Documente sursă) + Pas 3.2 — step 1-4 (Creditor / Debitor / Creanță / Confirmare).
 *
 * Routes are mounted at /case/new. Session bag `case_wizard_data` carries
 * cross-step state with four keys: `documentIds` (set in step 0), `creditor`
 * (Step1CreditorData), `debtors` (Step2DebtorsData), `claim` (Step3ClaimData).
 *
 * Step 0 documents have legal_case_id = NULL until step 4 submit attaches
 * them to the freshly persisted LegalCase. The whole submit is run inside
 * `EntityManager::wrapInTransaction` so a half-built case + orphan debtors
 * never reach the DB.
 */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class CaseWizardController extends AbstractController
{
    private const SESSION_KEY = 'case_wizard_data';

    public function __construct(
        private readonly DocumentUploadService $uploadService,
        private readonly PrefillFromExtractionService $prefill,
        private readonly DocumentRepository $documents,
        private readonly CreditorRepository $creditors,
        private readonly EntityManagerInterface $em,
        private readonly OpAdmissibilityValidator $admissibility,
        private readonly InterestCalculatorService $interestCalculator,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly CompetentCourtResolver $courtResolver,
        private readonly AuditLogService $auditLog,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Route('', name: 'start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        // Hard reset on every "Dosar nou" click: clicking the topbar CTA after a
        // previous abandoned wizard run must NOT resurrect the old drop-zone
        // state. We drop the session bag so the user lands on an empty wizard.
        // The orphan Document rows (legal_case_id IS NULL) stay on disk until a
        // background cleanup command sweeps them — that's tracked as a post-MVP
        // backlog item (`app:cleanup-pending-uploads`); deleting them here would
        // make a fresh start synchronously delete N files + emit N audit log
        // entries, which is too eager for a navigation click.
        $session = $request->getSession();
        $session->set(self::SESSION_KEY, $this->emptyBag());

        return $this->redirectToRoute('case_wizard_documents');
    }

    #[Route('/documents', name: 'documents', methods: ['GET', 'POST'])]
    public function documents(
        Request $request,
        #[CurrentUser] User $user,
        RateLimiterFactory $documentUploadLimiter,
    ): Response {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        $form = $this->createForm(Step0DocumentsType::class);
        $form->handleRequest($request);

        $isTurboFrameRequest = $request->headers->has('Turbo-Frame');
        $isTurboStreamRequest = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<UploadedFile> $uploaded */
            $uploaded = $form->get('documents')->getData();

            $limiter = $documentUploadLimiter->create($user->getUserIdentifier());
            $rateLimitHit = !$limiter->consume(count($uploaded))->isAccepted();

            $uploadErrors = [];
            if (!$rateLimitHit) {
                foreach ($uploaded as $file) {
                    try {
                        $document = $this->uploadService->upload(null, $file, DocumentType::ALT_DOCUMENT, $user);
                    } catch (\InvalidArgumentException) {
                        $this->logger->warning('wizard.step0.upload.mime_rejected', [
                            'userId' => $user->getId(),
                            'filename' => $file->getClientOriginalName(),
                        ]);
                        $uploadErrors[] = 'wizard.step0.error.invalid_mime';
                        continue;
                    }
                    $bag['documentIds'][] = $document->getId();
                    $this->bus->dispatch(new ExtractDataMessage($document->getId()));
                }
                $this->saveBag($session, $bag);
            }

            if ($isTurboStreamRequest || $isTurboFrameRequest) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $documents = $this->loadOwnedDocuments($bag['documentIds'], $user);
                $bag['documentIds'] = array_map(static fn (Document $d) => $d->getId(), $documents);
                $this->saveBag($session, $bag);

                if ($rateLimitHit) {
                    $toastMessage = 'rate_limit.document_upload';
                    $toastVariant = 'warning';
                } elseif ($uploadErrors !== []) {
                    $toastMessage = $uploadErrors[0];
                    $toastVariant = 'error';
                } else {
                    $toastMessage = 'wizard.flash.uploaded';
                    $toastVariant = 'success';
                }

                return $this->render('case/_step0_upload_stream.html.twig', [
                    'documents' => $documents,
                    'creditor_preview' => $this->prefill->aggregateForCreditor($bag['documentIds']),
                    'debtor_preview' => $this->prefill->aggregateForDebtor($bag['documentIds']),
                    'claim_preview' => $this->prefill->aggregateForClaim($bag['documentIds']),
                    'all_terminal' => $this->allTerminal($documents),
                    'toast_message' => $toastMessage,
                    'toast_variant' => $toastVariant,
                ]);
            }

            if ($rateLimitHit) {
                $this->addFlash('warning', 'rate_limit.document_upload');
            } else {
                foreach ($uploadErrors as $err) {
                    $this->addFlash('error', $err);
                }
                if ($bag['documentIds'] !== []) {
                    $this->addFlash('success', 'wizard.flash.uploaded');
                }
            }

            return $this->redirectToRoute('case_wizard_documents');
        }

        $documents = $this->loadOwnedDocuments($bag['documentIds'], $user);
        $bag['documentIds'] = array_map(static fn (Document $d) => $d->getId(), $documents);
        $this->saveBag($session, $bag);

        $viewVars = [
            'current_step' => 0,
            'form' => $form,
            'documents' => $documents,
            'creditor_preview' => $this->prefill->aggregateForCreditor($bag['documentIds']),
            'debtor_preview' => $this->prefill->aggregateForDebtor($bag['documentIds']),
            'claim_preview' => $this->prefill->aggregateForClaim($bag['documentIds']),
            'all_terminal' => $this->allTerminal($documents),
            'document_topics' => array_map(
                static fn (Document $d) => sprintf('document/%d/extraction-status', $d->getId()),
                $documents,
            ),
        ];

        if ($isTurboFrameRequest) {
            $frame = (string) $request->headers->get('Turbo-Frame');
            if ($frame === 'step0-dynamic') {
                return $this->render('case/_step0_dynamic_partial.html.twig', $viewVars);
            }
            if ($frame === 'step0-sidecard') {
                return $this->render('case/_step0_sidecard_partial.html.twig', $viewVars);
            }
        }

        return $this->render('case/_step0_documents_content.html.twig', $viewVars);
    }

    #[Route('/documents/status', name: 'documents_status', methods: ['GET'])]
    public function documentsStatus(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $bag = $this->loadBag($request->getSession());
        $sessionIds = array_flip($bag['documentIds']);

        $idsParam = $request->query->all('ids');
        $askedIds = array_values(array_unique(array_map('intval', is_array($idsParam) ? $idsParam : [])));

        $documents = $this->loadOwnedDocuments($askedIds, $user);

        $payload = [];
        foreach ($documents as $document) {
            if (!isset($sessionIds[$document->getId()])) {
                continue;
            }
            $confidence = $document->getExtractionConfidence();
            $payload[] = [
                'documentId' => $document->getId(),
                'status' => $document->getExtractionStatus()->value,
                'confidence' => $confidence === null ? 0.0 : (float) $confidence,
            ];
        }

        return new JsonResponse($payload);
    }

    #[Route('/skip', name: 'skip', methods: ['POST'])]
    public function skip(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('wizard_step0_skip', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'wizard.step0.error.invalid_csrf');

            return $this->redirectToRoute('case_wizard_documents');
        }

        $session = $request->getSession();
        $bag = $this->loadBag($session);
        $bag['documentIds'] = [];
        $this->saveBag($session, $bag);

        $this->addFlash('info', 'wizard.flash.skipped');

        return $this->redirectToRoute('case_wizard_creditor');
    }

    #[Route('/creditor', name: 'creditor', methods: ['GET', 'POST'])]
    public function creditor(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        $dto = $bag['creditor'] ?? $this->prefill->aggregateForCreditor($bag['documentIds']);
        $form = $this->createForm(Step1CreditorType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bag['creditor'] = $form->getData();
            $this->saveBag($session, $bag);

            return $this->redirectToRoute('case_wizard_debtor');
        }

        return $this->render('case/_step1_creditor_content.html.twig', [
            'current_step' => 1,
            'form' => $form,
            'dto' => $dto,
        ]);
    }

    #[Route('/debtor', name: 'debtor', methods: ['GET', 'POST'])]
    public function debtor(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        $dto = $bag['debtors'] ?? $this->prefill->aggregateForDebtors($bag['documentIds']);

        // Pas 3.3 — add/remove debtor happens through Step2DebtorsLiveComponent
        // (LiveActions on the component re-render only its template). The
        // controller now owns ONLY the page entry + final submit. The form
        // is rebuilt here from the request data so server-side validation
        // catches a debtor list mutated past the cap or with empty entries.
        $form = $this->createForm(Step2DebtorsType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bag['debtors'] = $form->getData();
            $this->saveBag($session, $bag);

            return $this->redirectToRoute('case_wizard_claim');
        }

        return $this->render('case/_step2_debtor_content.html.twig', [
            'current_step' => 2,
            'form' => $form,
            'dto' => $form->getData() ?? $dto,
        ]);
    }

    #[Route('/claim', name: 'claim', methods: ['GET', 'POST'])]
    public function claim(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        $dto = $bag['claim'] ?? $this->prefill->aggregateForClaim($bag['documentIds']);
        $form = $this->createForm(Step3ClaimType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bag['claim'] = $form->getData();
            $this->saveBag($session, $bag);

            return $this->redirectToRoute('case_wizard_confirmation');
        }

        // Pas 3.3 — sidebar calc is owned by `Step3ClaimLiveComponent`. The
        // controller no longer pre-renders interest / stamp duty / court for
        // the page template; the Live Component recomputes from props on
        // every debounced input change. Step 4 still uses
        // {@see safeComputeForSidebar()} to materialize the final figures.
        return $this->render('case/_step3_claim_content.html.twig', [
            'current_step' => 3,
            'form' => $form,
            'dto' => $dto,
        ]);
    }

    #[Route('/confirmation', name: 'confirmation', methods: ['GET', 'POST'])]
    public function confirmation(
        Request $request,
        #[CurrentUser] User $user,
        RateLimiterFactory $caseCreationLimiter,
    ): Response {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        $creditorDto = $bag['creditor'] ?? null;
        $debtorsDto = $bag['debtors'] ?? null;
        $claimDto = $bag['claim'] ?? null;

        // Soft guard — incomplete wizard state means the user navigated
        // directly to step 4 without filling earlier steps. Redirect to the
        // first missing step.
        if ($creditorDto === null) {
            return $this->redirectToRoute('case_wizard_creditor');
        }
        if ($debtorsDto === null) {
            return $this->redirectToRoute('case_wizard_debtor');
        }
        if ($claimDto === null) {
            return $this->redirectToRoute('case_wizard_claim');
        }

        $now = new \DateTimeImmutable();
        $skeleton = $this->buildLegalCaseSkeleton($user, $creditorDto, $debtorsDto, $claimDto);
        $issues = $this->admissibility->validate($skeleton, $now);
        ['errors' => $errors, 'warnings' => $warnings] = $this->splitIssues($issues);
        $hasErrors = $errors !== [];
        $hasWarnings = $warnings !== [];

        $calculations = $this->safeComputeForSidebar($claimDto, $debtorsDto->debtors[0] ?? null);
        $sessionDocuments = $this->loadOwnedDocuments($bag['documentIds'], $user);

        $confirmation = new Step4ConfirmationData();
        $form = $this->createForm(Step4ConfirmationType::class, $confirmation, [
            'validation_groups' => $hasWarnings ? ['Default', 'with_warnings'] : ['Default'],
        ]);
        $form->handleRequest($request);

        $autoFilledIndex = $this->aggregateAutoFilledFields($creditorDto, $debtorsDto, $claimDto);

        if ($request->isMethod('POST')) {
            if ($hasErrors) {
                $this->addFlash('error', 'wizard.step4.flash.errors_blocking');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments);
            }

            if (!$form->isValid()) {
                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments);
            }

            $limiter = $caseCreationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('warning', 'rate_limit.case_creation');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments);
            }

            $creditorOutcome = ['wasReused' => false];
            $persisted = $this->persistWizard(
                $user,
                $creditorDto,
                $debtorsDto,
                $claimDto,
                $bag['documentIds'],
                $calculations,
                $autoFilledIndex,
                $warnings,
                $creditorOutcome,
            );

            $session->set(self::SESSION_KEY, $this->emptyBag());

            if ($creditorOutcome['wasReused']) {
                $this->addFlash('info', 'wizard.step4.flash.creditor_reused');
            }

            // Sumar bogat pentru toast pe overview: număr dosar (titlu) +
            // 2 detalii (nr debitori, total cerere). Total = principal +
            // dobândă (dacă există) + taxă timbru fixă 200 RON.
            $totalAmount = (float) $persisted->getAmount()
                + ($calculations['interest']?->total ?? 0.0)
                + ($calculations['stampDuty']?->amount ?? 200.0);

            $this->addFlash('toast.success', [
                'key' => 'wizard.step4.flash.success_toast',
                'params' => ['%caseNumber%' => $persisted->getCaseNumber()],
                'details' => [
                    ['key' => 'wizard.step4.flash.detail.debtors', 'params' => ['%count%' => count($debtorsDto->debtors)]],
                    ['key' => 'wizard.step4.flash.detail.total', 'params' => ['%total%' => number_format($totalAmount, 2, ',', '.')]],
                ],
            ]);
            // One-time hint pe overview: badge „Următorul pas" + pulse pe CTA
            // „Generează cerere OP". Consumat la primul render de overview.
            $this->addFlash('case_just_created', '1');

            return $this->redirectToRoute('case_overview', ['id' => $persisted->getId()]);
        }

        return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments);
    }

    /**
     * @param array{interest: ?InterestResult, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param list<AdmissibilityIssue> $errors
     * @param list<AdmissibilityIssue> $warnings
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<Document> $sessionDocuments
     */
    private function renderConfirmation(
        FormInterface $form,
        Step1CreditorData $creditor,
        Step2DebtorsData $debtors,
        Step3ClaimData $claim,
        array $errors,
        array $warnings,
        array $calculations,
        array $autoFilledIndex,
        array $sessionDocuments = [],
    ): Response {
        return $this->render('case/_step4_confirmation_content.html.twig', [
            'current_step' => 4,
            'form' => $form,
            'creditor' => $creditor,
            'debtors' => $debtors,
            'claim' => $claim,
            'documents' => $sessionDocuments,
            'errors' => $errors,
            'warnings' => $warnings,
            'has_errors' => $errors !== [],
            'has_warnings' => $warnings !== [],
            'interest' => $calculations['interest'],
            'stamp_duty' => $calculations['stampDuty'],
            'court' => $calculations['court'],
            'auto_filled' => $autoFilledIndex,
        ]);
    }

    /**
     * @param list<int> $documentIds
     * @param array{interest: ?InterestResult, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<AdmissibilityIssue> $warnings
     */
    private function persistWizard(
        User $user,
        Step1CreditorData $creditorDto,
        Step2DebtorsData $debtorsDto,
        Step3ClaimData $claimDto,
        array $documentIds,
        array $calculations,
        array $autoFilledIndex,
        array $warnings,
        array &$creditorOutcome,
    ): LegalCase {
        return $this->em->wrapInTransaction(function () use (
            $user,
            $creditorDto,
            $debtorsDto,
            $claimDto,
            $documentIds,
            $calculations,
            $autoFilledIndex,
            $warnings,
            &$creditorOutcome,
        ): LegalCase {
            $creditor = $this->reuseOrCreateCreditor($user, $creditorDto, $creditorOutcome);

            $case = new LegalCase();
            $case->setUser($user);
            $case->setCreditor($creditor);
            $case->setAmount(sprintf('%.2f', $claimDto->amount ?? 0.0));
            $case->setCurrency($claimDto->currency);
            // LegalCase.dueDate is DATE_MUTABLE in Doctrine — the DTO holds an
            // immutable; Doctrine's DateType rejects DateTimeImmutable. Convert
            // at the persistence boundary.
            $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
            $case->setRelationshipType($claimDto->relationshipType);

            if ($calculations['interest'] !== null) {
                $case->setCalculatedInterest(sprintf('%.2f', $calculations['interest']->total));
            }
            if ($calculations['stampDuty'] !== null) {
                $case->setStampDuty(sprintf('%.2f', $calculations['stampDuty']->amount));
            }
            if ($calculations['court'] !== null && $calculations['court']->court !== null) {
                $case->setCourt($calculations['court']->court);
            }

            foreach ($debtorsDto->debtors as $entry) {
                $debtor = $this->buildDebtor($entry);
                $debtor->setLegalCase($case);
                $case->addDebtor($debtor);
            }

            $this->em->persist($case);
            $this->em->flush();

            if ($documentIds !== []) {
                $this->documents->attachToCase($documentIds, $case);
            }

            $this->auditLog->log(
                action: 'wizard_submit',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'fields_auto' => $autoFilledIndex['auto'],
                    'fields_manual' => $autoFilledIndex['manual'],
                    'extractedDocIds' => $documentIds,
                    'admissibility_warnings' => array_map(static fn (AdmissibilityIssue $i) => $i->code, $warnings),
                ],
                category: AuditLogService::CATEGORY_WIZARD_SUBMIT,
            );

            $this->em->flush();

            return $case;
        });
    }

    /**
     * @param array{wasReused: bool} $outcome — out-param flag signaling reuse so the
     *        caller can emit a flash AFTER the transaction commits (emitting it
     *        inside `wrapInTransaction` would leak the message even when the
     *        outer commit fails and rolls back).
     */
    private function reuseOrCreateCreditor(User $user, Step1CreditorData $dto, array &$outcome): Creditor
    {
        $outcome = ['wasReused' => false];

        if ($dto->creditorId !== null) {
            $existing = $this->creditors->find($dto->creditorId);
            if ($existing !== null && $existing->getUser()->getId() === $user->getId()) {
                $outcome['wasReused'] = true;

                return $existing;
            }
        }

        if ($dto->cui !== null && $dto->cui !== '') {
            $byCui = $this->creditors->findOneBy(['user' => $user, 'cui' => $dto->cui]);
            if ($byCui !== null) {
                $outcome['wasReused'] = true;

                return $byCui;
            }
        }

        $creditor = new Creditor();
        $creditor->setUser($user);
        // The DTO Expression invariant guarantees personType/name/address are
        // non-null whenever creditorId is null (manual fill path).
        $creditor->setPersonType($dto->personType);
        $creditor->setName($dto->name);
        $creditor->setAddress($dto->address);
        $creditor->setCui($dto->cui);
        $creditor->setPersonalId($dto->personalId);
        $creditor->setOnrcNumber($dto->onrcNumber);
        $creditor->setEmail($dto->email);
        $creditor->setPhone($dto->phone);
        $creditor->setIban($dto->iban);
        $creditor->setLegalRepresentative($dto->legalRepresentative);

        $this->em->persist($creditor);

        return $creditor;
    }

    private function buildDebtor(Step2DebtorEntry $entry): Debtor
    {
        $debtor = new Debtor();
        // The DTO NotNull/NotBlank on personType/name/address has already
        // fired by the time we reach persistWizard; trust the contract.
        $debtor->setPersonType($entry->personType);
        $debtor->setName($entry->name);
        $debtor->setAddress($entry->address);
        $debtor->setCui($entry->cui);
        $debtor->setPersonalId($entry->personalId);
        $debtor->setOnrcNumber($entry->onrcNumber);
        $debtor->setEmail($entry->email);
        $debtor->setPhone($entry->phone);
        $debtor->setIban($entry->iban);
        $debtor->setAdministrator($entry->administrator);
        $debtor->setAnafStatus($entry->anafStatus);
        $debtor->setAnafCheckedAt($entry->anafCheckedAt);
        $debtor->setInInsolvency($entry->inInsolvency);
        $debtor->setInsolvencyCheckedAt($entry->insolvencyCheckedAt);

        return $debtor;
    }

    private function buildLegalCaseSkeleton(
        User $user,
        Step1CreditorData $creditorDto,
        Step2DebtorsData $debtorsDto,
        Step3ClaimData $claimDto,
    ): LegalCase {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setAmount($claimDto->amount !== null ? sprintf('%.2f', $claimDto->amount) : null);
        $case->setCurrency($claimDto->currency);
        $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
        $case->setRelationshipType($claimDto->relationshipType);

        // We attach the creditor only when picked from the catalog — the
        // skeleton is for OpAdmissibilityValidator which only looks at debtors,
        // so an unloaded creditor doesn't break anything.
        if ($creditorDto->creditorId !== null) {
            $existing = $this->creditors->find($creditorDto->creditorId);
            if ($existing !== null && $existing->getUser()->getId() === $user->getId()) {
                $case->setCreditor($existing);
            }
        }

        foreach ($debtorsDto->debtors as $entry) {
            // Skip empty rows so the validator doesn't fire NotNull errors on
            // entries the user added then never filled out.
            if ($entry->personType === null && ($entry->name === null || $entry->name === '')) {
                continue;
            }
            $debtor = $this->buildDebtor($entry);
            $debtor->setLegalCase($case);
            $case->addDebtor($debtor);
        }

        return $case;
    }

    /**
     * @param list<AdmissibilityIssue> $issues
     * @return array{errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>}
     */
    private function splitIssues(array $issues): array
    {
        $errors = [];
        $warnings = [];
        foreach ($issues as $issue) {
            if ($issue->severity === IssueSeverity::ERROR) {
                $errors[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Compute calculations defensively — returns nulls on any calculation
     * failure (missing BNR rate, county_unknown, DomainException). The
     * template renders placeholders instead of crashing the page when the
     * user is on step 3/4 with incomplete data.
     *
     * @return array{interest: ?InterestResult, stampDuty: ?StampDutyResult, court: ?CourtResolveResult}
     */
    private function safeComputeForSidebar(Step3ClaimData $claim, ?Step2DebtorEntry $primaryDebtor): array
    {
        if ($claim->amount === null || $claim->amount <= 0.0 || $claim->dueDate === null || $claim->relationshipType === null) {
            return ['interest' => null, 'stampDuty' => $this->safeStampDuty(), 'court' => null];
        }

        $now = new \DateTimeImmutable();

        try {
            $interest = $this->interestCalculator->calculate(
                $claim->amount,
                $claim->dueDate,
                $now,
                $claim->relationshipType,
            );
        } catch (\DomainException|\RuntimeException $e) {
            $this->logger->info('wizard.calc.interest_failed', ['reason' => $e->getMessage()]);
            $interest = null;
        }

        // County extraction from unstructured Debtor.address is out of scope
        // for Pas 3.2 — Pas 3.3 ANAF lookup populates a structured county
        // field on the entry. Until then we pass null and let the resolver
        // return court=null with `court.resolver.county_unknown` (per spec C5,
        // submit can still proceed; template shows "Instanță needeterminată").
        try {
            $court = $this->courtResolver->resolve(
                $claim->amount,
                $claim->dueDate,
                $now,
                $claim->relationshipType,
                debtorCounty: null,
                debtorLocality: null,
            );
        } catch (\DomainException|\RuntimeException $e) {
            $this->logger->info('wizard.calc.court_failed', ['reason' => $e->getMessage()]);
            $court = null;
        }

        return ['interest' => $interest, 'stampDuty' => $this->safeStampDuty(), 'court' => $court];
    }

    private function safeStampDuty(): ?StampDutyResult
    {
        try {
            return $this->stampDutyCalculator->calculate();
        } catch (\Throwable $e) {
            $this->logger->info('wizard.calc.stamp_duty_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Walk every DTO field and split into `auto` (was prefilled from
     * extraction) vs `manual` (everything else the user touched). Used as
     * `newData` payload on the wizard_submit audit log so a future investigator
     * can answer "which fields on this case came from AI extraction?".
     *
     * @return array{auto: list<string>, manual: list<string>}
     */
    private function aggregateAutoFilledFields(
        Step1CreditorData $creditor,
        Step2DebtorsData $debtors,
        Step3ClaimData $claim,
    ): array {
        $auto = [];
        $manual = [];

        foreach ($creditor->autoFilled as $field) {
            $auto[] = 'creditor.' . $field;
        }
        foreach ($this->dtoFieldNames($creditor) as $field) {
            if (!in_array($field, $creditor->autoFilled, true)) {
                $manual[] = 'creditor.' . $field;
            }
        }

        foreach ($debtors->debtors as $index => $entry) {
            foreach ($entry->autoFilled as $field) {
                $auto[] = sprintf('debtor[%d].%s', $index, $field);
            }
            foreach ($this->dtoFieldNames($entry) as $field) {
                if (!in_array($field, $entry->autoFilled, true)) {
                    $manual[] = sprintf('debtor[%d].%s', $index, $field);
                }
            }
        }

        foreach ($claim->autoFilled as $field) {
            $auto[] = 'claim.' . $field;
        }
        foreach ($this->dtoFieldNames($claim) as $field) {
            if (!in_array($field, $claim->autoFilled, true)) {
                $manual[] = 'claim.' . $field;
            }
        }

        return ['auto' => $auto, 'manual' => $manual];
    }

    /**
     * @return list<string>
     */
    private function dtoFieldNames(object $dto): array
    {
        $names = [];
        foreach ((new \ReflectionObject($dto))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getName() === 'autoFilled') {
                continue;
            }
            $names[] = $prop->getName();
        }

        return $names;
    }

    /**
     * @return array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData}
     */
    private function emptyBag(): array
    {
        return [
            'documentIds' => [],
            'creditor' => null,
            'debtors' => null,
            'claim' => null,
        ];
    }

    /**
     * @return array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData}
     */
    private function loadBag(SessionInterface $session): array
    {
        $raw = $session->get(self::SESSION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $ids = $raw['documentIds'] ?? [];
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];

        $creditor = $raw['creditor'] ?? null;
        if (!$creditor instanceof Step1CreditorData) {
            $creditor = null;
        }

        $debtors = $raw['debtors'] ?? null;
        if (!$debtors instanceof Step2DebtorsData) {
            $debtors = null;
        }

        $claim = $raw['claim'] ?? null;
        if (!$claim instanceof Step3ClaimData) {
            $claim = null;
        }

        return [
            'documentIds' => $ids,
            'creditor' => $creditor,
            'debtors' => $debtors,
            'claim' => $claim,
        ];
    }

    /**
     * @param array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData} $bag
     */
    private function saveBag(SessionInterface $session, array $bag): void
    {
        $session->set(self::SESSION_KEY, $bag);
    }

    /**
     * @param list<int> $documentIds
     * @return list<Document>
     */
    private function loadOwnedDocuments(array $documentIds, User $user): array
    {
        if ($documentIds === []) {
            return [];
        }
        $found = $this->documents->findBy(['id' => $documentIds]);
        $owned = array_values(array_filter(
            $found,
            static fn (Document $d) => $d->getUploadedBy()->getId() === $user->getId(),
        ));

        $byId = [];
        foreach ($owned as $d) {
            $byId[$d->getId()] = $d;
        }
        $ordered = [];
        foreach ($documentIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<Document> $documents
     */
    private function allTerminal(array $documents): bool
    {
        if ($documents === []) {
            return false;
        }
        foreach ($documents as $document) {
            $status = $document->getExtractionStatus();
            if ($status !== ExtractionStatus::COMPLETED && $status !== ExtractionStatus::FAILED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Translation helper for flash messages that need parameter interpolation
     * — `addFlash()` itself only stores the raw key + params separately
     * (would need a custom Twig filter to interpolate later), so we translate
     * here and pass the already-rendered string.
     *
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        $translator = $this->container->get('translator');

        return $translator->trans($key, $params);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'translator' => TranslatorInterface::class,
        ]);
    }
}
