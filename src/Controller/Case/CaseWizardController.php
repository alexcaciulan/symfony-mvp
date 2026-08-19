<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Court\CourtResolveResult;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Extraction\WizardPrefillResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\ClaimItemRow;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\DTO\Wizard\Step3ClaimData;
use App\DTO\Wizard\Step4ConfirmationData;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ConflictScope;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\IssueSeverity;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Form\Wizard\Step0DocumentsType;
use App\Form\Wizard\Step1CreditorType;
use App\Form\Wizard\Step2DebtorsType;
use App\Form\Wizard\Step3ClaimType;
use App\Form\Wizard\Step4ConfirmationType;
use App\Message\ExtractDataMessage;
use App\Repository\CourtRepository;
use App\Repository\CreditorRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimTotalsService;
use App\Util\ClaimRowLiveMapper;
use App\Util\RomanianAmountParser;
use App\Util\StringCapper;
use App\Service\Court\CompetentCourtResolver;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\UploadDeduplicator;
use App\Service\Document\UploadRateLimiter;
use App\Service\Extraction\ConflictChoiceApplier;
use App\Service\Extraction\ConflictResolutionService;
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
use Symfony\Component\Validator\Validator\ValidatorInterface;
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

    /**
     * Fallback for `EXTRACTION_REVIEW_THRESHOLD`. Below it, a position was read
     * with too little confidence to be waved through by the table-wide
     * confirmation and needs the lawyer to tick it individually.
     */
    private const DEFAULT_REVIEW_THRESHOLD = 0.6;

    public function __construct(
        private readonly DocumentUploadService $uploadService,
        private readonly UploadDeduplicator $deduplicator,
        private readonly UploadRateLimiter $uploadRateLimiter,
        private readonly PrefillFromExtractionService $prefill,
        private readonly ConflictResolutionService $conflictResolutions,
        private readonly ConflictChoiceApplier $conflictChoices,
        private readonly ValidatorInterface $validator,
        private readonly DocumentRepository $documents,
        private readonly CreditorRepository $creditors,
        private readonly CourtRepository $courts,
        private readonly EntityManagerInterface $em,
        private readonly OpAdmissibilityValidator $admissibility,
        private readonly InterestCalculatorService $interestCalculator,
        private readonly ContractualPenaltyCalculator $penaltyCalculator,
        private readonly CurrencyConverter $currencyConverter,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly CompetentCourtResolver $courtResolver,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly ClaimTotalsService $claimTotals,
        private readonly ClaimInterestAggregator $accessoryAggregator,
        private readonly AuditLogService $auditLog,
        private readonly MessageBusInterface $bus,
        private readonly float $confidenceThreshold = self::DEFAULT_REVIEW_THRESHOLD,
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

            // A declared type routes the batch to the prompt written for it.
            // Left on the placeholder, the extractor classifies each file.
            $declaredType = DocumentType::tryFrom((string) $form->get('documentType')->getData())
                ?? DocumentType::ALT_DOCUMENT;

            $identifier = $user->getUserIdentifier();
            $accepted = [];
            $duplicates = [];
            $uploadErrors = [];
            $storedCount = 0;

            // The traffic ceiling is charged before the batch is read, so an
            // over-limit user cannot still force the server to hash 10 files.
            $rateLimitHit = !$this->uploadRateLimiter->acceptsBatch($identifier, count($uploaded));

            if (!$rateLimitHit) {
                // Duplicates are recognised before anything is stored, against
                // the documents of this draft only (see UploadDeduplicator for
                // why the scope is not global).
                $partition = $this->deduplicator->partition($uploaded, $this->loadOwnedDocuments($bag['documentIds'], $user));
                $accepted = $partition['files'];
                $duplicates = $partition['duplicates'];

                // Only files that will really be stored and extracted cost
                // extraction budget: a known file costs no LLM call.
                $rateLimitHit = !$this->uploadRateLimiter->acceptsExtractions($identifier, count($accepted));
            }

            if (!$rateLimitHit) {
                foreach ($accepted as $file) {
                    try {
                        $document = $this->uploadService->upload(null, $file, $declaredType, $user);
                    } catch (\InvalidArgumentException) {
                        $this->logger->warning('wizard.step0.upload.mime_rejected', [
                            'userId' => $user->getId(),
                            'filename' => $file->getClientOriginalName(),
                        ]);
                        $uploadErrors[] = 'wizard.step0.error.invalid_mime';
                        continue;
                    }
                    $bag['documentIds'][] = $document->getId();
                    ++$storedCount;
                    $this->bus->dispatch(new ExtractDataMessage($document->getId()));
                }
                $this->saveBag($session, $bag);
            }

            if ($isTurboStreamRequest || $isTurboFrameRequest) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $documents = $this->loadOwnedDocuments($bag['documentIds'], $user);
                $bag['documentIds'] = array_map(static fn (Document $d) => $d->getId(), $documents);
                $this->saveBag($session, $bag);

                // One toast per outcome, so a mixed batch reports the skipped
                // file AND confirms the stored one, exactly like the flash bag
                // of the non-Turbo path below.
                $toasts = [];
                if ($rateLimitHit) {
                    $toasts[] = ['message' => 'rate_limit.document_upload', 'variant' => 'warning', 'params' => []];
                } else {
                    foreach ($uploadErrors as $uploadError) {
                        $toasts[] = ['message' => $uploadError, 'variant' => 'error', 'params' => []];
                    }
                    if ($duplicates !== []) {
                        $toasts[] = [
                            'message' => 'wizard.step0.toast.duplicate_skipped',
                            'variant' => 'warning',
                            'params' => ['{files}' => implode(', ', $duplicates)],
                        ];
                    }
                    if ($storedCount > 0) {
                        $toasts[] = ['message' => 'wizard.flash.uploaded', 'variant' => 'success', 'params' => []];
                    }
                }

                // One aggregation for the whole side card: the three previews
                // have to agree with each other, and three passes over the same
                // documents is three chances for them not to.
                $preview = $this->prefill->aggregate($bag['documentIds'], $bag['conflictResolutions']);

                return $this->render('case/_step0_upload_stream.html.twig', [
                    'documents' => $documents,
                    'creditor_preview' => $preview->creditor,
                    'debtor_preview' => $preview->debtors->debtors[0],
                    'claim_preview' => $preview->claim,
            'claim_positions_preview' => $this->positionsPreview($bag['documentIds']),
                    'prefill_conflicts' => $preview->conflicts,
                    'prefill_conflict_documents' => $this->conflictDocumentNames($bag['documentIds']),
                    'all_terminal' => $this->allTerminal($documents),
                    'document_types' => DocumentType::uploadableTypes(),
                    'toasts' => $toasts,
                ]);
            }

            if ($rateLimitHit) {
                $this->addFlash('warning', 'rate_limit.document_upload');
            } else {
                foreach ($uploadErrors as $err) {
                    $this->addFlash('error', $err);
                }
                if ($duplicates !== []) {
                    $this->addFlash('warning', $this->trans('wizard.step0.toast.duplicate_skipped', [
                        '{files}' => implode(', ', $duplicates),
                    ]));
                }
                if ($storedCount > 0) {
                    $this->addFlash('success', 'wizard.flash.uploaded');
                }
            }

            return $this->redirectToRoute('case_wizard_documents');
        }

        $documents = $this->loadOwnedDocuments($bag['documentIds'], $user);
        $bag['documentIds'] = array_map(static fn (Document $d) => $d->getId(), $documents);
        $this->saveBag($session, $bag);

        $preview = $this->prefill->aggregate($bag['documentIds'], $bag['conflictResolutions']);
        $viewVars = [
            'current_step' => 0,
            'form' => $form,
            'documents' => $documents,
            'creditor_preview' => $preview->creditor,
            'debtor_preview' => $preview->debtors->debtors[0],
            'claim_preview' => $preview->claim,
            'claim_positions_preview' => $this->positionsPreview($bag['documentIds']),
            // Shown here as soon as the documents disagree, read only: the
            // extraction of the other files may still be running, so the set is
            // not final and the decision belongs on the step that owns the
            // field. Seeing it now is what stops the lawyer filling three steps
            // on a party two documents describe differently.
            'prefill_conflicts' => $preview->conflicts,
            'prefill_conflict_documents' => $this->conflictDocumentNames($bag['documentIds']),
            'all_terminal' => $this->allTerminal($documents),
            // Choices for the per-document type correction shown on each card.
            'document_types' => DocumentType::uploadableTypes(),
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

    /**
     * Re-queues extraction for one document. Offered only on transient failure
     * reasons: retrying a file that is too large or a mime the model cannot read
     * would fail identically and just cost another call.
     */
    #[Route('/documents/{id}/retry', name: 'documents_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retryExtraction(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('wizard_step0_retry_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $bag = $this->loadBag($request->getSession());
        if (!in_array($id, $bag['documentIds'], true)) {
            throw $this->createNotFoundException('Document is not part of the current wizard session');
        }

        $documents = $this->loadOwnedDocuments([$id], $user);
        $document = $documents[0] ?? null;
        if ($document === null) {
            throw $this->createNotFoundException('Document not found');
        }

        $reason = $document->getExtractionFailureReason();
        if ($reason === null || !$reason->isTransient()) {
            $this->addFlash('warning', 'wizard.step0.retry.not_retriable');

            return $this->redirectToRoute('case_wizard_documents');
        }

        $document->setExtractionStatus(ExtractionStatus::PENDING);
        $document->setExtractionFailureReason(null);
        $this->em->flush();
        $this->bus->dispatch(new ExtractDataMessage($document->getId()));

        $this->addFlash('success', 'wizard.step0.retry.queued');

        return $this->redirectToRoute('case_wizard_documents');
    }

    /**
     * Corrects the type of an already-uploaded document and re-runs extraction
     * with the prompt written for that type.
     *
     * Reprocessing is deliberate rather than automatic: re-reading a document
     * means resending the file, which is the expensive half of the call, so it
     * happens when a human says the first reading was of the wrong kind of
     * document, not every time the classifier has an opinion.
     */
    #[Route('/documents/{id}/type', name: 'documents_type', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function correctDocumentType(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('wizard_step0_type_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $bag = $this->loadBag($request->getSession());
        if (!in_array($id, $bag['documentIds'], true)) {
            throw $this->createNotFoundException('Document is not part of the current wizard session');
        }

        $documents = $this->loadOwnedDocuments([$id], $user);
        $document = $documents[0] ?? null;
        if ($document === null) {
            throw $this->createNotFoundException('Document not found');
        }

        $type = DocumentType::tryFrom((string) $request->request->get('documentType'));
        // Validated against the same list the dropdown is built from, so the
        // accepted set and the offered set cannot drift apart. It excludes the
        // types the platform generates itself (accepting one would let a form
        // post relabel evidence as a filing) and the ones with a dedicated
        // upload flow (the stamp-duty proof carries payment state this route
        // knows nothing about).
        if ($type === null || !in_array($type, DocumentType::uploadableTypes(), true)) {
            $this->addFlash('error', 'wizard.step0.document_type.invalid');

            return $this->redirectToRoute('case_wizard_documents');
        }

        $document->setDocumentType($type);
        $document->setExtractionStatus(ExtractionStatus::PENDING);
        $document->setExtractionFailureReason(null);
        $this->em->flush();
        $this->bus->dispatch(new ExtractDataMessage($document->getId()));

        $this->addFlash('success', 'wizard.step0.document_type.requeued');

        return $this->redirectToRoute('case_wizard_documents');
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

        [$prefill, $conflicts] = $this->prefillWithConflicts($bag, [ConflictScope::CREDITOR]);
        $dto = $bag['creditor'] ?? $prefill->creditor;
        // Taken before the form binds, because the form writes into this very
        // object: it is what the page put on screen, which is how a field the
        // lawyer edited is told from one they left as the aggregation had it.
        $rendered = $this->snapshotDto($dto);
        $form = $this->createForm(Step1CreditorType::class, $dto);
        $form->handleRequest($request);

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            // Settled on every submission, valid form or not: a step refused
            // over a mistyped field would otherwise make the lawyer choose
            // between the documents all over again, which is the most ordinary
            // way to reach this page twice.
            $acknowledged = $this->settleConflicts($request, $bag, 'creditor', $conflicts);
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag['conflictResolutions'],
            );
            $stands = $this->applyChosenValues($bag, ConflictScope::CREDITOR, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        if ($submitted && $acknowledged) {
            $bag['creditor'] = $form->getData();
            $this->saveBag($session, $bag);

            return $this->redirectToRoute('case_wizard_debtor');
        }
        $this->saveBag($session, $bag);

        return $this->render('case/_step1_creditor_content.html.twig', [
            'current_step' => 1,
            'form' => $form,
            'dto' => $dto,
            ...$this->conflictViewVars($bag, $conflicts, 'creditor', $rejected),
        ], $this->stepRejected($acknowledged));
    }

    #[Route('/debtor', name: 'debtor', methods: ['GET', 'POST'])]
    public function debtor(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        [$prefill, $conflicts] = $this->prefillWithConflicts($bag, [ConflictScope::DEBTOR, ConflictScope::DEBTOR_SET]);
        $dto = $bag['debtors'] ?? $prefill->debtors;

        // Pas 3.3 — add/remove debtor happens through Step2DebtorsLiveComponent
        // (LiveActions on the component re-render only its template). The
        // controller now owns ONLY the page entry + final submit. The form
        // is rebuilt here from the request data so server-side validation
        // catches a debtor list mutated past the cap or with empty entries.
        $rendered = $this->snapshotDto($dto);
        $form = $this->createForm(Step2DebtorsType::class, $dto);
        $form->handleRequest($request);

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            $acknowledged = $this->settleConflicts($request, $bag, 'debtors', $conflicts);
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag['conflictResolutions'],
            );
            $stands = $this->applyChosenValues($bag, ConflictScope::DEBTOR, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        if ($submitted && $acknowledged) {
            $bag['debtors'] = $form->getData();
            $this->saveBag($session, $bag);

            return $this->redirectToRoute('case_wizard_claim');
        }
        $this->saveBag($session, $bag);

        return $this->render('case/_step2_debtor_content.html.twig', [
            'current_step' => 2,
            'form' => $form,
            'dto' => $form->getData() ?? $dto,
            ...$this->conflictViewVars($bag, $conflicts, 'debtors', $rejected),
        ], $this->stepRejected($acknowledged));
    }

    #[Route('/claim', name: 'claim', methods: ['GET', 'POST'])]
    public function claim(Request $request): Response
    {
        $session = $request->getSession();
        $bag = $this->loadBag($session);

        [$prefill, $claimConflicts] = $this->prefillWithConflicts($bag, [ConflictScope::CLAIM]);
        $collected = $this->claimItemFactory->collectRows($bag['documentIds']);
        $conflicts = array_values([...$claimConflicts, ...$collected->conflicts]);
        $bag['conflictResolutions'] = $this->conflictResolutions->reconcile($bag['conflictResolutions'], $conflicts);
        $dto = $bag['claim'] ?? $this->claimWithPositionAwareDescription($prefill->claim, $collected->rows);
        $rendered = $this->snapshotDto($dto);
        $form = $this->createForm(Step3ClaimType::class, $dto);
        $form->handleRequest($request);

        $rows = $bag['claimItems'] ?? $collected->rows;
        $tableConfirmed = $bag['claimItemsTableConfirmed'];
        $rowErrors = [];

        $submitted = $form->isSubmitted() && $form->isValid();
        $acknowledged = true;
        $rejected = [];
        if ($form->isSubmitted()) {
            $acknowledged = $this->settleConflicts($request, $bag, 'claim', $conflicts);
            $rejected = $this->conflictResolutions->rejectedChoices(
                $request->request->all('prefill_conflict'),
                $conflicts,
                $bag['conflictResolutions'],
            );
            $stands = $this->applyChosenValues($bag, ConflictScope::CLAIM, $rendered, $form->getData(), $rejected);
            $acknowledged = $acknowledged && $stands;
        }
        // Applied after the decisions of this request are in: a sum chosen now
        // has to reach the table now, not on the next render.
        $this->claimItemFactory->applyResolutions($rows, $bag['conflictResolutions']);
        if ($submitted && $acknowledged) {
            if ($rows !== []) {
                $tableConfirmed = $request->request->getBoolean('claim_items_table_confirmed');
                $this->applyPostedRows($rows, $request->request->all('claim_items'), $tableConfirmed);
                $rowErrors = $this->validateRows($rows, $tableConfirmed);
            }

            if ($rowErrors === []) {
                $bag['claim'] = $form->getData();
                $bag['claimItems'] = $rows !== [] ? $rows : null;
                $bag['claimItemsTableConfirmed'] = $tableConfirmed;
                $this->saveBag($session, $bag);

                return $this->redirectToRoute('case_wizard_confirmation');
            }

            foreach ($rowErrors as $errorKey) {
                $this->addFlash('error', $errorKey);
            }
        }

        // Pas 3.3 — the sidebar AND the positions table are owned by
        // `Step3ClaimLiveComponent`, which recomputes interest and totals from
        // its `rows` prop on every debounced edit. The controller only hands it
        // the initial rows; Step 4 uses {@see safeComputeForSidebar()} for the
        // final figures.
        $this->saveBag($session, $bag);

        return $this->render('case/_step3_claim_content.html.twig', [
            'current_step' => 3,
            'form' => $form,
            'dto' => $dto,
            'claim_items' => $rows,
            'claim_items_live' => ClaimRowLiveMapper::toArrays($rows),
            'claim_items_table_confirmed' => $tableConfirmed,
            'claim_items_review_threshold' => $this->confidenceThreshold,
            'claim_items_errors' => $rowErrors,
            ...$this->conflictViewVars($bag, $conflicts, 'claim', $rejected),
        ], $this->stepRejected($acknowledged));
    }

    /**
     * Writes the lawyer's decisions from the posted table back onto the rows.
     *
     * Matched on the row's dedup key, never on its position in the table: the
     * rows are re-derived from the documents on POST, and an exclusion that
     * landed on a different invoice than the one it was ticked against would be
     * a decision the lawyer never made.
     *
     * The single table-wide checkbox confirms every row that carries no risk;
     * a row that does (see {@see ClaimItemRow::requiresIndividualConfirmation()})
     * only counts as confirmed when it was ticked on its own line. An excluded
     * row keeps its trace instead of disappearing.
     *
     * @param list<ClaimItemRow>   $rows
     * @param array<string, mixed> $posted
     */
    /**
     * With several invoices the aggregated description is one invoice's wording,
     * which reads as if the claim were only about that one. The per-invoice
     * wording is what the petition itemises and stays on the rows; this field is
     * the object of the whole claim, so it names all of them.
     *
     * @param list<ClaimItemRow> $rows
     */
    private function claimWithPositionAwareDescription(Step3ClaimData $claim, array $rows): Step3ClaimData
    {
        if (count($rows) < 2) {
            return $claim;
        }

        $numbered = array_values(array_filter(array_map(
            static fn (ClaimItemRow $row) => $row->documentNumber,
            $rows,
        )));

        $translator = $this->container->get('translator');
        $claim->description = $numbered === []
            ? $translator->trans('wizard.step3.derived_from_positions.description_generic', ['%count%' => count($rows)])
            : $translator->trans('wizard.step3.derived_from_positions.description', [
                '%count%' => count($rows),
                '%numbers%' => implode(', ', $numbered),
            ]);

        return $claim;
    }

    /**
     * What the uploaded invoices add up to, for the step 0 card. The aggregated
     * claim carries one invoice worth of figures by construction, so showing it
     * alone next to several invoices reads as if the rest were missed.
     *
     * @param list<int> $documentIds
     * @return array{count: int, principal: float, currency: string, earliestDueDate: ?\DateTimeImmutable}|null
     */
    private function positionsPreview(array $documentIds): ?array
    {
        if ($documentIds === []) {
            return null;
        }

        $rows = $this->claimItemFactory->rowsFromDocuments($documentIds);
        if (count($rows) < 2) {
            // One position says nothing the claim card does not already show.
            return null;
        }

        $principal = 0.0;
        $earliest = null;
        foreach ($rows as $row) {
            $principal += $row->signedAmountRon() ?? 0.0;
            if ($row->dueDate !== null && ($earliest === null || $row->dueDate < $earliest)) {
                $earliest = $row->dueDate;
            }
        }

        return [
            'count' => count($rows),
            'principal' => round($principal, 2),
            'currency' => 'RON',
            'earliestDueDate' => $earliest,
        ];
    }

    /**
     * Romanian keyboards and Romanian invoices both use the comma as the
     * decimal mark, and thousands arrive dotted. Anything that does not read as
     * a positive number is ignored so the extracted figure survives.
     */
    private function postedAmount(mixed $raw): ?float
    {
        return is_string($raw) ? RomanianAmountParser::parse($raw) : null;
    }

    private function postedDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $parsed !== false ? $parsed : null;
    }

    private function applyPostedRows(array $rows, array $posted, bool $tableConfirmed): void
    {
        $byKey = [];
        foreach ($posted as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $entry['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $byKey[$key] = $entry;
            }
        }

        foreach ($rows as $row) {
            $entry = $byKey[$row->dedupKey] ?? null;
            if ($entry === null) {
                // The row was not on the table the lawyer submitted, so no
                // decision of theirs covers it. It stays unconfirmed.
                $row->confirmed = false;

                continue;
            }

            $row->excluded = (bool) ($entry['excluded'] ?? false);
            $individual = (bool) ($entry['confirmed'] ?? false);

            // A misread figure has to be correctable here, or the lawyer cannot
            // file at all. A blank or unparsable entry leaves the extracted
            // value standing rather than zeroing the position.
            $amount = $this->postedAmount($entry['amount'] ?? null);
            if ($amount !== null && abs($amount - $row->amount) > 0.001) {
                $row->amount = $amount;
                if ($row->currency === 'RON') {
                    $row->amountRon = $amount;
                } else {
                    // The stored rate belongs to the old figure; recomputing it
                    // is the factory's job, and it runs on the next render.
                    $row->amountRon = $row->exchangeRate !== null ? round($amount * $row->exchangeRate, 2) : null;
                }
            }

            $dueDate = $this->postedDate($entry['dueDate'] ?? null);
            if ($dueDate !== null) {
                $row->dueDate = $dueDate;
            }

            $cause = $entry['cause'] ?? null;
            if (is_string($cause)) {
                $trimmed = trim($cause);
                $row->causeReference = $trimmed === '' ? null : $trimmed;
            }

            $row->confirmed = $row->requiresIndividualConfirmation($this->confidenceThreshold)
                ? $individual
                : ($tableConfirmed || $individual);
        }
    }

    /**
     * @param  list<ClaimItemRow> $rows
     * @return list<string>       translation keys of what blocks the step
     */
    private function validateRows(array $rows, bool $tableConfirmed): array
    {
        $errors = [];
        $active = array_values(array_filter($rows, static fn (ClaimItemRow $row): bool => !$row->excluded));

        if ($active === []) {
            return ['wizard.step3.claim_items.error.all_excluded'];
        }

        if (!$tableConfirmed) {
            $errors[] = 'wizard.step3.claim_items.error.table_not_confirmed';
        }

        foreach ($active as $row) {
            if ($row->requiresIndividualConfirmation($this->confidenceThreshold) && !$row->confirmed) {
                $errors[] = 'wizard.step3.claim_items.error.individual_confirmation';
                break;
            }
        }

        // Every position awaiting a manual rate means nothing enters the claim,
        // and the case would silently fall back to the single-sum path on a
        // foreign-currency figure the calculators refuse. Historical EUR files
        // land here as a matter of course, so say so rather than proceed.
        if ($errors === [] && !$this->anyRowCounts($rows)) {
            $errors[] = 'wizard.step3.claim_items.error.no_countable_row';
        }

        return $errors;
    }

    /**
     * @param list<ClaimItemRow> $rows
     */
    private function anyRowCounts(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->counts()) {
                return true;
            }
        }

        return false;
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

        // Everything the documents disagree about, checked once more here. A
        // blocking conflict still open at this point was walked past rather than
        // decided, and this is the last screen before a filing is created.
        $prefill = $this->prefill->aggregate($bag['documentIds'], $bag['conflictResolutions']);
        $conflicts = array_values([
            ...$prefill->conflicts,
            ...$this->claimItemFactory->collectRows($bag['documentIds'])->conflicts,
        ]);
        // Every disagreement of the file is on this screen, so a decision with
        // no conflict left to match is about documents that are no longer here
        // and has to go rather than wait for its key to mean something else.
        $bag['conflictResolutions'] = $this->conflictResolutions->reconcile($bag['conflictResolutions'], $conflicts, true);
        // What the audit will say was retained is what the filing has to carry.
        // The steps already moved these values into the forms; doing it once
        // more here is what makes the two impossible to pull apart, whichever
        // way the bag was reached.
        $this->conflictChoices->applyToCreditor($creditorDto, null, $prefill->creditor, $bag['conflictResolutions']);
        $this->conflictChoices->applyToDebtors($debtorsDto, null, $prefill->debtors, $bag['conflictResolutions']);
        $this->conflictChoices->applyToClaim($claimDto, null, $prefill->claim, $bag['conflictResolutions']);
        $this->saveBag($session, $bag);
        $unsettled = $this->unsettledConflicts($bag, $conflicts);
        $conflictVars = [
            'prefill_conflicts' => $conflicts,
            'prefill_conflict_resolutions' => $bag['conflictResolutions'],
            'prefill_conflict_documents' => $this->conflictDocumentNames($bag['documentIds']),
            'prefill_conflicts_unsettled' => $unsettled,
        ];

        $now = new \DateTimeImmutable();
        $conversion = $this->resolveConversion($claimDto);
        $claimRows = $this->resolveClaimRows($bag, $claimDto);
        $this->claimItemFactory->applyResolutions($claimRows, $bag['conflictResolutions']);
        $skeleton = $this->buildLegalCaseSkeleton($user, $creditorDto, $debtorsDto, $claimDto, $conversion, $claimRows);
        $issues = $this->admissibility->validate($skeleton, $now);
        ['errors' => $errors, 'warnings' => $warnings] = $this->splitIssues($issues);
        $hasErrors = $errors !== [];
        $hasWarnings = $warnings !== [];

        $calculations = $this->safeComputeForSidebar($claimDto, $debtorsDto->debtors[0] ?? null, $conversion, $skeleton);
        $sessionDocuments = $this->loadOwnedDocuments($bag['documentIds'], $user);

        $confirmation = new Step4ConfirmationData();
        $form = $this->createForm(Step4ConfirmationType::class, $confirmation, [
            'validation_groups' => $hasWarnings ? ['Default', 'with_warnings'] : ['Default'],
        ]);
        // Preselect the auto-resolved court id; the user may override it (or pick
        // one when resolution is ambiguous / county-unknown). On POST handleRequest
        // replaces this with the submitted id.
        $resolvedCourt = $calculations['court']?->court;
        if ($resolvedCourt !== null) {
            $form->get('court')->setData((string) $resolvedCourt->getId());
        }
        $form->handleRequest($request);

        // Court shown in the picker on (re)render: the submitted/preselected id if
        // it loads to an active court, else the auto-resolved one.
        $courtIdData = $form->get('court')->getData();
        $preselectedCourt = (is_string($courtIdData) && $courtIdData !== '')
            ? $this->courts->find($courtIdData)
            : null;
        if ($preselectedCourt === null || !$preselectedCourt->isActive()) {
            $preselectedCourt = $resolvedCourt;
        }

        $autoFilledIndex = $this->aggregateAutoFilledFields($creditorDto, $debtorsDto, $claimDto);

        if ($request->isMethod('POST')) {
            if ($unsettled !== []) {
                $this->addFlash('error', 'wizard.conflict.unsettled_at_confirmation');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
            }

            if ($hasErrors) {
                $this->addFlash('error', 'wizard.step4.flash.errors_blocking');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
            }

            if (!$form->isValid()) {
                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
            }

            // A foreign-currency claim cannot be persisted without a resolved BNR
            // rate, otherwise the case lands in an inconsistent state (currency
            // EUR with no conversion, which the calculator guard rejects at
            // document time). Block with a clear message instead.
            if ($claimDto->currency !== 'RON' && $conversion === null) {
                $this->addFlash('error', 'wizard.step4.flash.exchange_rate_missing');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
            }

            $limiter = $caseCreationLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('warning', 'rate_limit.case_creation');

                return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
            }

            // Court precedence: the submitted id (auto-resolved preselection kept,
            // or manual override) wins when it loads to an active court; otherwise
            // fall back to the auto-resolved court. Loading + active check guards
            // against a tampered hidden id.
            $selectedId = $form->get('court')->getData();
            $selectedCourt = (is_string($selectedId) && $selectedId !== '')
                ? $this->courts->find($selectedId)
                : null;
            if ($selectedCourt !== null && !$selectedCourt->isActive()) {
                $selectedCourt = null;
            }
            $chosenCourt = $selectedCourt ?? $resolvedCourt;
            $courtResolution = match (true) {
                $chosenCourt === null => 'none',
                $resolvedCourt !== null && $chosenCourt->getId() === $resolvedCourt->getId() => 'auto',
                default => 'manual',
            };

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
                $chosenCourt,
                $courtResolution,
                $conversion,
                $claimRows,
                $this->conflictResolutions->auditEntries(
                    $conflicts,
                    $bag['conflictResolutions'],
                    $this->conflictDocumentDescriptors($bag['documentIds']),
                    $this->acknowledgements($bag, $conflicts),
                ),
            );

            $session->set(self::SESSION_KEY, $this->emptyBag());

            if ($creditorOutcome['wasReused']) {
                $this->addFlash('info', 'wizard.step4.flash.creditor_reused');
            }

            // Sumar bogat pentru toast pe overview: număr dosar (titlu) +
            // 2 detalii (nr debitori, total cerere). Total = principal +
            // dobândă (dacă există) + taxă timbru. Fallback la calculator-ul
            // injectat (OUG 80/2013 art. 6 alin 2 — 200 RON fix); evită magic
            // numbers in cod cand cleanest source-of-truth e DI param-ul.
            $totalAmount = (float) $persisted->getAmount()
                + ($calculations['accessoryTotal'] ?? 0.0)
                + ($calculations['stampDuty']?->amount ?? $this->stampDutyCalculator->calculate()->amount);

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

        return $this->renderConfirmation($form, $creditorDto, $debtorsDto, $claimDto, $errors, $warnings, $calculations, $autoFilledIndex, $sessionDocuments, $preselectedCourt, $conflictVars);
    }

    /**
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param list<AdmissibilityIssue> $errors
     * @param list<AdmissibilityIssue> $warnings
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<Document> $sessionDocuments
     * @param array<string, mixed> $conflictVars what the read-only conflicts panel needs
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
        ?Court $preselectedCourt = null,
        array $conflictVars = [],
    ): Response {
        return $this->render('case/_step4_confirmation_content.html.twig', [
            ...$conflictVars,
            'current_step' => 4,
            'form' => $form,
            'preselected_court' => $preselectedCourt,
            'creditor' => $creditor,
            'debtors' => $debtors,
            'claim' => $claim,
            'documents' => $sessionDocuments,
            'errors' => $errors,
            'warnings' => $warnings,
            'has_errors' => $errors !== [],
            'has_warnings' => $warnings !== [],
            'interest' => $calculations['interest'],
            'penalty' => $calculations['penalty'],
            'accessory_total' => $calculations['accessoryTotal'] ?? 0.0,
            'stamp_duty' => $calculations['stampDuty'],
            'court' => $calculations['court'],
            'conversion' => $calculations['conversion'] ?? null,
            // The positions are the claim, so the screen the lawyer signs shows
            // them and the principal they add up to, not the scalar from step 3.
            'claim_items' => $calculations['claimItems'] ?? [],
            'claim_item_accessories' => $calculations['accessoryPerItem'] ?? null,
            'claim_items_principal' => $calculations['principal'] ?? null,
            'auto_filled' => $autoFilledIndex,
        ]);
    }

    /**
     * @param list<int> $documentIds
     * @param array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult} $calculations
     * @param array{auto: list<string>, manual: list<string>} $autoFilledIndex
     * @param list<AdmissibilityIssue> $warnings
     * @param list<ClaimItemRow> $claimRows
     * @param list<array<string, mixed>> $conflictResolutions
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
        ?Court $court,
        string $courtResolution,
        ?CurrencyConversionResult $conversion,
        array $claimRows = [],
        array $conflictResolutions = [],
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
            $court,
            $courtResolution,
            $conversion,
            $claimRows,
            $conflictResolutions,
        ): LegalCase {
            $creditor = $this->reuseOrCreateCreditor($user, $creditorDto, $creditorOutcome);

            $case = new LegalCase();
            $case->setUser($user);
            $case->setCreditor($creditor);
            // Foreign currency is stored converted to RON; the original values
            // are kept for transparency in the document (art. 9 audit trail).
            if ($conversion !== null) {
                $case->setAmount(sprintf('%.2f', $conversion->ronAmount));
                $case->setCurrency('RON');
                $case->setOriginalAmount(sprintf('%.2f', $conversion->originalAmount));
                $case->setOriginalCurrency($conversion->originalCurrency);
                $case->setExchangeRate(sprintf('%.4f', $conversion->rate));
                $case->setExchangeRateDate($conversion->rateDate);
            } else {
                $case->setAmount(sprintf('%.2f', $claimDto->amount ?? 0.0));
                $case->setCurrency($claimDto->currency);
            }
            // LegalCase.dueDate is DATE_MUTABLE in Doctrine — the DTO holds an
            // immutable; Doctrine's DateType rejects DateTimeImmutable. Convert
            // at the persistence boundary.
            $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
            $case->setRelationshipType($claimDto->relationshipType);
            $this->applyAccessoryFields($case, $claimDto);

            if (($calculations['accessoryTotal'] ?? 0.0) > 0.0) {
                $case->setCalculatedInterest(sprintf('%.2f', $calculations['accessoryTotal']));
            }
            if ($calculations['stampDuty'] !== null) {
                $case->setStampDuty(sprintf('%.2f', $calculations['stampDuty']->amount));
            }
            if ($court !== null) {
                $case->setCourt($court);
            }

            foreach ($debtorsDto->debtors as $entry) {
                $debtor = $this->buildDebtor($entry);
                $debtor->setLegalCase($case);
                $case->addDebtor($debtor);
            }

            // The positions are the claim; the case scalars are their total,
            // written here and nowhere else.
            if ($claimRows !== []) {
                $this->claimItemFactory->materialize($case, $claimRows);
                $this->claimTotals->recalculate($case);
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
                    'court_resolution' => $courtResolution,
                    'court_id' => $court?->getId(),
                    // What the lawyer signed off on, position by position: the
                    // table-wide tick is only as good as the record of what it
                    // covered, and an exclusion has to stay provable.
                    'claim_items' => $this->auditClaimItems($claimRows),
                    // Why one value was retained and not the other the documents
                    // also stated. Months later the file has to answer that, and
                    // only the record of the choice can.
                    'conflict_resolutions' => $conflictResolutions,
                ],
                category: AuditLogService::CATEGORY_WIZARD_SUBMIT,
            );

            $this->em->flush();

            return $case;
        });
    }

    /**
     * @param  list<ClaimItemRow>        $rows
     * @return list<array<string, mixed>>
     */
    private function auditClaimItems(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'dedupKey' => $row->dedupKey,
                'documentNumber' => $row->documentNumber,
                'dueDate' => $row->dueDate?->format('Y-m-d'),
                'amount' => $row->amount,
                'currency' => $row->currency,
                'amountRon' => $row->amountRon,
                'sourceDocumentId' => $row->sourceDocumentId,
                'confirmation' => match (true) {
                    $row->excluded => 'excluded',
                    !$row->confirmed => 'unconfirmed',
                    $row->requiresIndividualConfirmation($this->confidenceThreshold) => 'individual',
                    default => 'table',
                },
                'needsManualFx' => $row->needsManualFx,
            ];
        }

        return $entries;
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
                $this->backfillCreditorLocation($existing, $dto);

                return $existing;
            }
        }

        if ($dto->cui !== null && $dto->cui !== '') {
            $byCui = $this->creditors->findOneBy(['user' => $user, 'cui' => $dto->cui]);
            if ($byCui !== null) {
                $outcome['wasReused'] = true;
                $this->refreshCreditorFromDto($byCui, $dto);

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
        $creditor->setAddressCounty($dto->addressCounty);
        $creditor->setAddressLocality($dto->addressLocality);
        $creditor->setAnafCheckedAt($this->parseAnafCheckedAt($dto->anafCheckedAt));
        $creditor->setCui($dto->cui);
        $creditor->setPersonalId($dto->personalId);
        $creditor->setOnrcNumber($dto->onrcNumber);
        $creditor->setEmail($dto->email);
        $creditor->setPhone($dto->phone);
        $creditor->setIban($dto->iban);
        $creditor->setBankName($dto->bankName);
        $creditor->setLegalRepresentative($dto->legalRepresentative);

        $this->em->persist($creditor);

        return $creditor;
    }

    /**
     * Creditors saved before the stamp-duty work have no structured location, so a
     * case on a reused creditor could never resolve its payment UAT. Fill the gap
     * when the ANAF lookup supplies it, without overwriting a value the lawyer
     * already curated.
     *
     * Deliberately weaker than {@see refreshCreditorFromDto}: this path runs when
     * the lawyer picked a creditor from the library, which hides the manual
     * fields, so the DTO carries no fresh input to write. Treating an unsubmitted
     * field as an intentional value would blank the library record.
     */
    private function backfillCreditorLocation(Creditor $creditor, Step1CreditorData $dto): void
    {
        if ($creditor->getAddressCounty() === null && $dto->addressCounty !== null && $dto->addressCounty !== '') {
            $creditor->setAddressCounty($dto->addressCounty);
        }

        if ($creditor->getAddressLocality() === null && $dto->addressLocality !== null && $dto->addressLocality !== '') {
            $creditor->setAddressLocality($dto->addressLocality);
            $creditor->setAnafCheckedAt($this->parseAnafCheckedAt($dto->anafCheckedAt));
        }
    }

    /**
     * The lawyer filled the form by hand or synced it from ANAF, and only then
     * did the CUI turn out to match a creditor already in the library. Before,
     * everything but a missing county/locality was silently dropped: the wizard
     * showed the fresh data, the saved case kept the stale address, and the
     * somaţie went out to the old registered office.
     *
     * Only non-empty values that actually differ are written, and the change is
     * audited: this creditor is shared with the lawyer's other cases, so their
     * display changes too.
     */
    private function refreshCreditorFromDto(Creditor $creditor, Step1CreditorData $dto): void
    {
        $updatable = [
            'Name' => $dto->name,
            'Address' => $dto->address,
            'AddressCounty' => $dto->addressCounty,
            'AddressLocality' => $dto->addressLocality,
            'OnrcNumber' => $dto->onrcNumber,
            'LegalRepresentative' => $dto->legalRepresentative,
            'Email' => $dto->email,
            'Phone' => $dto->phone,
            'Iban' => $dto->iban,
            'BankName' => $dto->bankName,
        ];

        $changes = [];

        foreach ($updatable as $property => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            $current = $creditor->{'get' . $property}();
            if ($current === $value) {
                continue;
            }

            $creditor->{'set' . $property}($value);
            $changes[lcfirst($property)] = ['from' => $current, 'to' => $value];
        }

        $checkedAt = $this->parseAnafCheckedAt($dto->anafCheckedAt);
        if ($checkedAt !== null) {
            $creditor->setAnafCheckedAt($checkedAt);
        }

        if ($changes === []) {
            return;
        }

        $this->auditLog->log(
            action: 'creditor_refreshed',
            entityType: Creditor::class,
            entityId: (string) $creditor->getId(),
            newData: $changes,
            category: AuditLogService::CATEGORY_WIZARD_SUBMIT,
        );
    }

    private function parseAnafCheckedAt(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function buildDebtor(Step2DebtorEntry $entry): Debtor
    {
        $debtor = new Debtor();
        // The DTO NotNull/NotBlank on personType/name/address has already
        // fired by the time we reach persistWizard; trust the contract.
        $debtor->setPersonType($entry->personType);
        $debtor->setName($entry->name);
        $debtor->setAddress($entry->address);
        $debtor->setAddressCounty($entry->addressCounty);
        $debtor->setAddressLocality($entry->addressLocality);
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

    /**
     * Copy the summons accessory configuration (penalty type, contractual rate,
     * invoice/contract metadata, legal costs) from the claim DTO onto the case.
     * The document recomputes the actual accessory amounts from these at render.
     */
    private function applyAccessoryFields(LegalCase $case, Step3ClaimData $claimDto): void
    {
        $case->setPenaltyType($claimDto->penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE);
        $case->setContractualPenaltyRate(
            $claimDto->contractualPenaltyRate !== null ? sprintf('%.3f', $claimDto->contractualPenaltyRate) : null
        );
        // AI extraction can return a longer string than a column holds (a
        // contract's object phrase lands in contractReference, say), so cap
        // each to its column length rather than let the save 500.
        $case->setContractReference(StringCapper::cap($claimDto->contractReference, 255));
        $case->setClaimDescription($claimDto->description);
        $case->setInvoiceNumber(StringCapper::cap($claimDto->invoiceNumber, 100));
        $case->setInvoiceDate($claimDto->invoiceDate !== null ? \DateTime::createFromImmutable($claimDto->invoiceDate) : null);
        $case->setContractNumber(StringCapper::cap($claimDto->contractNumber, 100));
        $case->setContractDate($claimDto->contractDate !== null ? \DateTime::createFromImmutable($claimDto->contractDate) : null);
        $case->setLegalCostsFixed($claimDto->legalCostsFixed !== null ? sprintf('%.2f', $claimDto->legalCostsFixed) : null);
        $case->setLegalCostsCurrency($claimDto->legalCostsCurrency);
        $case->setLegalCostsSuccessPercent(
            $claimDto->legalCostsSuccessPercent !== null ? sprintf('%.2f', $claimDto->legalCostsSuccessPercent) : null
        );
    }

    /**
     * FX conversion for a foreign-currency claim, at the BNR rate of the invoice
     * emission date. Null when the claim is already in RON, when required inputs
     * are missing, or when no BNR rate is available (logged, surfaced upstream).
     */
    private function resolveConversion(Step3ClaimData $claim): ?CurrencyConversionResult
    {
        if ($claim->amount === null || $claim->currency === 'RON' || $claim->invoiceDate === null) {
            return null;
        }

        try {
            return $this->currencyConverter->convertToRon($claim->amount, $claim->currency, $claim->invoiceDate);
        } catch (\RuntimeException $e) {
            $this->logger->info('wizard.calc.fx_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The claim amount every calculation runs on: the RON-converted value for a
     * foreign currency, or the raw amount for RON. Null signals "cannot compute"
     * (foreign currency without a resolvable rate).
     */
    private function ronAmount(Step3ClaimData $claim, ?CurrencyConversionResult $conversion): ?float
    {
        if ($claim->currency === 'RON') {
            return $claim->amount;
        }

        return $conversion?->ronAmount;
    }

    /**
     * The positions the case will carry: the ones read from the documents and
     * confirmed on the step 3 table, or the single one implied by a manually
     * filled claim, which the lawyer confirmed by submitting the form.
     *
     * @param  array{claimItems: ?list<ClaimItemRow>, ...} $bag
     * @return list<ClaimItemRow>
     */
    private function resolveClaimRows(array $bag, Step3ClaimData $claimDto): array
    {
        $rows = $bag['claimItems'] ?? null;
        if ($rows !== null && $rows !== []) {
            return $rows;
        }

        $row = $this->claimItemFactory->rowFromClaim($claimDto);
        if ($row === null) {
            return [];
        }
        $row->confirmed = true;

        return [$row];
    }

    /**
     * @param list<ClaimItemRow> $claimRows
     */
    private function buildLegalCaseSkeleton(
        User $user,
        Step1CreditorData $creditorDto,
        Step2DebtorsData $debtorsDto,
        Step3ClaimData $claimDto,
        ?CurrencyConversionResult $conversion = null,
        array $claimRows = [],
    ): LegalCase {
        $case = new LegalCase();
        $case->setUser($user);
        $ronAmount = $this->ronAmount($claimDto, $conversion);
        $case->setAmount($ronAmount !== null ? sprintf('%.2f', $ronAmount) : null);
        $case->setCurrency($conversion !== null ? 'RON' : $claimDto->currency);
        $case->setDueDate($claimDto->dueDate !== null ? \DateTime::createFromImmutable($claimDto->dueDate) : null);
        $case->setRelationshipType($claimDto->relationshipType);
        $this->applyAccessoryFields($case, $claimDto);

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

        // Transient positions: never persisted from here, but enough for the
        // admissibility check, the accessories and the competence rule to run on
        // exactly what will be saved at submit.
        if ($claimRows !== []) {
            $this->claimItemFactory->materialize($case, $claimRows);
            $this->claimTotals->recalculate($case);
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
     * @return array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult, conversion: ?CurrencyConversionResult, accessoryPerItem: ?AggregatedAccessoryResult, claimItems: list<ClaimItem>, principal: ?float}
     */
    private function safeComputeForSidebar(
        Step3ClaimData $claim,
        ?Step2DebtorEntry $primaryDebtor,
        ?CurrencyConversionResult $conversion,
        ?LegalCase $skeleton = null,
    ): array {
        $items = $skeleton?->getCountingClaimItems() ?? [];
        if ($items !== [] && $claim->relationshipType !== null) {
            return $this->computeFromItems($items, $claim, $primaryDebtor, $conversion);
        }

        // Everything downstream runs on RON: convert a foreign-currency claim
        // first. A foreign currency with no resolvable rate yields a null
        // principal, so we bail to placeholders (and the template shows the FX
        // error via `conversion`).
        $principal = $this->ronAmount($claim, $conversion);
        if ($principal === null || $principal <= 0.0 || $claim->dueDate === null || $claim->relationshipType === null) {
            return ['interest' => null, 'penalty' => null, 'accessoryTotal' => 0.0, 'stampDuty' => $this->safeStampDuty(), 'court' => null, 'conversion' => $conversion, 'accessoryPerItem' => null, 'claimItems' => [], 'principal' => null];
        }

        $now = new \DateTimeImmutable();

        // Accessory routing: contractual penalty (daily rate) vs. legal penalty
        // interest (OG 13/2011, BNR + 8). Both feed the same `calculatedInterest`
        // slot at persist; the document recomputes from penaltyType at render.
        $interest = null;
        $penalty = null;
        $accessoryTotal = 0.0;

        if ($claim->penaltyType === PenaltyType::CONTRACTUAL && $claim->contractualPenaltyRate !== null) {
            $penalty = $this->penaltyCalculator->calculate(
                $principal,
                $claim->contractualPenaltyRate,
                $claim->dueDate,
                $now,
            );
            $accessoryTotal = $penalty->total;
        } else {
            try {
                $interest = $this->interestCalculator->calculate(
                    $principal,
                    $claim->dueDate,
                    $now,
                    $claim->relationshipType,
                );
                $accessoryTotal = $interest->total;
            } catch (\DomainException|\RuntimeException $e) {
                $this->logger->info('wizard.calc.interest_failed', ['reason' => $e->getMessage()]);
                $interest = null;
            }
        }

        // County + locality come from the (structured) primary debtor entry —
        // ANAF lookup, AI extraction, or manual. When absent the resolver
        // returns court=null with `court.resolver.county_unknown` and the user
        // picks the court manually at step 4 (per spec C5, submit still proceeds).
        //
        // Competence is decided on the principal alone (CPC art. 98 alin. 2, accessories
        // excluded); the accessory amount below feeds only the displayed breakdown / petit.
        $isContractual = $claim->penaltyType === PenaltyType::CONTRACTUAL;
        try {
            $court = $this->courtResolver->resolve(
                $principal,
                $claim->dueDate,
                $now,
                $claim->relationshipType,
                debtorCounty: $primaryDebtor?->addressCounty,
                debtorLocality: $primaryDebtor?->addressLocality,
                scadentPenalties: $isContractual ? $accessoryTotal : 0.0,
                computeLegalInterest: !$isContractual,
            );
        } catch (\DomainException|\RuntimeException $e) {
            $this->logger->info('wizard.calc.court_failed', ['reason' => $e->getMessage()]);
            $court = null;
        }

        return ['interest' => $interest, 'penalty' => $penalty, 'accessoryTotal' => $accessoryTotal, 'stampDuty' => $this->safeStampDuty(), 'court' => $court, 'conversion' => $conversion, 'accessoryPerItem' => null, 'claimItems' => [], 'principal' => $principal];
    }

    /**
     * The same figures, computed from the claim positions: each accrues from its
     * own due date, and the competent court follows CPC art. 99 over the causes
     * the positions rest on.
     *
     * @param  list<ClaimItem> $items
     * @return array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult, conversion: ?CurrencyConversionResult, accessoryPerItem: ?AggregatedAccessoryResult, claimItems: list<ClaimItem>, principal: ?float}
     */
    private function computeFromItems(
        array $items,
        Step3ClaimData $claim,
        ?Step2DebtorEntry $primaryDebtor,
        ?CurrencyConversionResult $conversion,
    ): array {
        $now = new \DateTimeImmutable();
        $relationshipType = $claim->relationshipType ?? RelationshipType::COMERCIAL;
        $penaltyType = $claim->penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE;

        try {
            $accessory = $this->accessoryAggregator->aggregate(
                items: $items,
                referenceDate: $now,
                relationshipType: $relationshipType,
                penaltyType: $penaltyType,
                contractualDailyRate: $claim->contractualPenaltyRate,
            );
        } catch (\DomainException | \RuntimeException $e) {
            // An unsupported claim type reaches here now instead of turning into
            // a zero; the step renders placeholders rather than a wrong figure.
            $this->logger->info('wizard.calc.items_accessory_failed', ['reason' => $e->getMessage()]);
            $accessory = new AggregatedAccessoryResult(0.0);
        }

        // A single position is the case as it always was, so the sidebar still
        // gets the one result it knows how to break down by period.
        $interest = null;
        $penalty = null;
        if (count($items) === 1) {
            $only = $accessory->forItem($items[0]->getId() ?? -1);
            $interest = $only instanceof InterestResult ? $only : null;
            $penalty = $only instanceof PenaltyResult ? $only : null;
        }

        try {
            $court = $this->courtResolver->resolveForItems(
                items: $items,
                referenceDate: $now,
                relationshipType: $relationshipType,
                debtorCounty: $primaryDebtor?->addressCounty,
                debtorLocality: $primaryDebtor?->addressLocality,
                penaltyType: $penaltyType,
                contractualDailyRate: $claim->contractualPenaltyRate,
            );
        } catch (\DomainException | \RuntimeException $e) {
            $this->logger->info('wizard.calc.court_failed', ['reason' => $e->getMessage()]);
            $court = null;
        }

        return [
            'interest' => $interest,
            'penalty' => $penalty,
            'accessoryTotal' => $accessory->total,
            'stampDuty' => $this->safeStampDuty(),
            'court' => $court,
            'conversion' => $conversion,
            'accessoryPerItem' => $accessory,
            'claimItems' => $items,
            // What the positions actually add up to. The step 3 scalar is one
            // document's figure; persisting is done from this one, so the
            // confirmation screen has to show this one.
            'principal' => $this->itemsPrincipal($items),
        ];
    }

    /**
     * @param list<ClaimItem> $items
     */
    private function itemsPrincipal(array $items): float
    {
        $principal = 0.0;
        foreach ($items as $item) {
            $principal += $item->signedAmountRon() ?? 0.0;
        }

        return round($principal, 2);
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
     * @return array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData, claimItems: ?list<ClaimItemRow>, claimItemsTableConfirmed: bool, conflictResolutions: array<string, ConflictResolution>}
     */
    private function emptyBag(): array
    {
        return [
            'documentIds' => [],
            'creditor' => null,
            'debtors' => null,
            'claim' => null,
            'claimItems' => null,
            'claimItemsTableConfirmed' => false,
            'conflictResolutions' => [],
            'conflictsAcknowledged' => [],
        ];
    }

    /**
     * @return array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData, claimItems: ?list<ClaimItemRow>, claimItemsTableConfirmed: bool, conflictResolutions: array<string, ConflictResolution>}
     */
    /**
     * The disagreements between documents that this step is responsible for
     * showing.
     *
     * @param list<ConflictScope> $scopes
     * @return list<PrefillConflict>
     */
    private function conflictsInScope(WizardPrefillResult $prefill, array $scopes): array
    {
        return array_values(array_filter(
            $prefill->conflicts,
            static fn (PrefillConflict $c): bool => in_array($c->scope, $scopes, true),
        ));
    }

    /**
     * The prefill and the disagreements one step is responsible for, with stale
     * decisions dropped.
     *
     * A choice is stored against a position in the conflict's option list, and
     * the documents of a session can change between two visits to a step. A pick
     * that quietly slid onto another value would be a decision nobody made, so
     * it is dropped and the conflict is put again. Re-aggregating after that is
     * what keeps the form and the panel describing the same state.
     *
     * @param array<string, mixed> $bag
     * @param list<ConflictScope> $scopes
     * @return array{0: WizardPrefillResult, 1: list<PrefillConflict>}
     */
    private function prefillWithConflicts(array &$bag, array $scopes): array
    {
        $prefill = $this->prefill->aggregate($bag['documentIds'], $bag['conflictResolutions']);
        $conflicts = $this->conflictsInScope($prefill, $scopes);

        $reconciled = $this->conflictResolutions->reconcile($bag['conflictResolutions'], $conflicts);
        if ($reconciled !== $bag['conflictResolutions']) {
            $bag['conflictResolutions'] = $reconciled;
            $prefill = $this->prefill->aggregate($bag['documentIds'], $reconciled);
            $conflicts = $this->conflictsInScope($prefill, $scopes);
        }

        return [$prefill, $conflicts];
    }

    /**
     * Moves what the lawyer chose into the data the step is about to keep.
     *
     * The panel posts alongside the form, so the fields were rendered before the
     * choice existed and the submission carries the value the ranking preferred.
     * Keeping that would make the audit entry state a value the filing
     * contradicts, which is worse than never offering the choice.
     *
     * A chosen value goes into the petition exactly like a typed one, so it has
     * to stand up to the same constraints; where it does not, the step refuses
     * rather than filing something the form would have rejected.
     *
     * @param array<string, mixed> $bag
     * @param mixed $rendered the step data as the page had drawn it
     * @param list<string> $rejected conflicts whose answer could not be kept
     */
    private function applyChosenValues(array &$bag, ConflictScope $scope, mixed $rendered, mixed $dto, array &$rejected): bool
    {
        $resolutions = $bag['conflictResolutions'];
        if ($this->conflictResolutions->ofScope($resolutions, $scope) === []) {
            return true;
        }

        $chosen = $this->prefill->aggregate($bag['documentIds'], $resolutions);
        $moved = match (true) {
            $dto instanceof Step1CreditorData => $this->conflictChoices->applyToCreditor($dto, $rendered instanceof Step1CreditorData ? $rendered : null, $chosen->creditor, $resolutions),
            $dto instanceof Step2DebtorsData => $this->conflictChoices->applyToDebtors($dto, $rendered instanceof Step2DebtorsData ? $rendered : null, $chosen->debtors, $resolutions),
            $dto instanceof Step3ClaimData => $this->conflictChoices->applyToClaim($dto, $rendered instanceof Step3ClaimData ? $rendered : null, $chosen->claim, $resolutions),
            default => [],
        };

        // Each moved value against the constraints of the field it landed on. A
        // field that merely came along with the chosen document is put back the
        // way it was submitted: the choice was about another field and does not
        // justify filing a value the form refuses. A chosen field that cannot
        // stand is a decision that cannot be carried out, so it is undone and
        // put to the lawyer again rather than half applied.
        $stands = true;
        foreach ($moved as $change) {
            if (count($this->validator->validateProperty($change['target'], $change['property'])) === 0) {
                continue;
            }
            $change['target']->{$change['property']} = $change['previous'];
            if ($change['conflictKey'] === null) {
                continue;
            }
            unset($bag['conflictResolutions'][$change['conflictKey']]);
            $rejected[] = $change['conflictKey'];
            $stands = false;
        }

        return $stands;
    }

    /**
     * The step data as it was rendered, detached from the object the form is
     * about to write into.
     */
    private function snapshotDto(mixed $dto): mixed
    {
        if ($dto instanceof Step2DebtorsData) {
            return new Step2DebtorsData(array_map(
                static fn (Step2DebtorEntry $entry): Step2DebtorEntry => clone $entry,
                $dto->debtors,
            ));
        }

        return is_object($dto) ? clone $dto : $dto;
    }

    /**
     * Whether the step may advance past what the documents disagree about.
     *
     * A blocking conflict would put a wrong party, a wrong sum or a wrong court
     * in the filing, so the wizard does not settle it by ranking. Where the
     * documents offer values to choose between, the step waits for the choice
     * itself; where they offer none, because the answer is whether this is one
     * file or two, all the lawyer can do is state they have read it. Both are
     * kept in the bag, so walking back to the step does not ask again.
     *
     * @param array<string, mixed> $bag
     * @param list<PrefillConflict> $conflicts
     */
    private function settleConflicts(Request $request, array &$bag, string $step, array $conflicts): bool
    {
        $bag['conflictResolutions'] = $this->conflictResolutions->collect(
            $request->request->all('prefill_conflict'),
            $request->request->all('prefill_conflict_manual'),
            $conflicts,
            $bag['conflictResolutions'],
        );

        if ($this->conflictResolutions->pendingChoices($conflicts, $bag['conflictResolutions']) !== []) {
            $this->addFlash('error', 'wizard.conflict.choice_required');

            return false;
        }

        $pending = $this->conflictResolutions->pendingAcknowledgement($conflicts);
        if ($pending === []) {
            return true;
        }

        // Bound to the disagreements it was made about. A document uploaded
        // afterwards can raise another one, and a flag that stayed true would
        // carry the lawyer's statement onto something they never saw.
        $fingerprint = $this->conflictResolutions->acknowledgementFingerprint($pending);
        if (($bag['conflictsAcknowledged'][$step]['fingerprint'] ?? null) === $fingerprint) {
            return true;
        }
        if ($request->request->getBoolean('prefill_conflicts_acknowledged')) {
            $bag['conflictsAcknowledged'][$step] = [
                'fingerprint' => $fingerprint,
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            return true;
        }

        $this->addFlash('error', 'wizard.conflict.acknowledge_required');

        return false;
    }

    /**
     * What the lawyer stated they had read, by conflict, for the record.
     *
     * Only the blocking disagreements that offer nothing to choose between get
     * one: everywhere else the record is the choice itself.
     *
     * @param array<string, mixed> $bag
     * @param list<PrefillConflict> $conflicts
     * @return array<string, array{at: string, fingerprint: string}>
     */
    private function acknowledgements(array $bag, array $conflicts): array
    {
        $entries = [];
        foreach ($this->byStep($this->conflictResolutions->pendingAcknowledgement($conflicts)) as $step => $group) {
            $record = $bag['conflictsAcknowledged'][$step] ?? null;
            if (!is_array($record)) {
                continue;
            }
            if (($record['fingerprint'] ?? null) !== $this->conflictResolutions->acknowledgementFingerprint($group)) {
                continue;
            }
            foreach ($group as $conflict) {
                $entries[$conflict->key()] = ['at' => (string) $record['at'], 'fingerprint' => (string) $record['fingerprint']];
            }
        }

        return $entries;
    }

    /**
     * @param list<PrefillConflict> $conflicts
     * @return array<string, list<PrefillConflict>>
     */
    private function byStep(array $conflicts): array
    {
        $grouped = [];
        foreach ($conflicts as $conflict) {
            $grouped[$this->stepOwning($conflict->scope)][] = $conflict;
        }

        return $grouped;
    }

    /**
     * The blocking disagreements nobody has settled, whichever step owns them.
     *
     * @param array<string, mixed> $bag
     * @param list<PrefillConflict> $conflicts
     * @return list<PrefillConflict>
     */
    private function unsettledConflicts(array $bag, array $conflicts): array
    {
        $unsettled = $this->conflictResolutions->pendingChoices($conflicts, $bag['conflictResolutions']);
        $acknowledged = $this->acknowledgements($bag, $conflicts);
        foreach ($this->conflictResolutions->pendingAcknowledgement($conflicts) as $conflict) {
            if (!isset($acknowledged[$conflict->key()])) {
                $unsettled[] = $conflict;
            }
        }

        return $unsettled;
    }

    /**
     * The step that shows a scope, which is also the step whose acknowledgement
     * counts for it.
     */
    private function stepOwning(ConflictScope $scope): string
    {
        return match ($scope) {
            ConflictScope::CREDITOR => 'creditor',
            ConflictScope::DEBTOR, ConflictScope::DEBTOR_SET => 'debtors',
            ConflictScope::CLAIM, ConflictScope::CLAIM_ITEM => 'claim',
        };
    }

    /**
     * What the conflicts panel needs to render: the disagreements, the decisions
     * already taken, and the file name behind each value.
     *
     * The names are resolved here rather than carried on the option, because the
     * option is built by the aggregation from extraction payloads and has no
     * business loading document rows.
     *
     * @param array<string, mixed> $bag
     * @param list<PrefillConflict> $conflicts
     * @param list<string> $rejected keys whose answer could not be kept
     * @return array<string, mixed>
     */
    private function conflictViewVars(array $bag, array $conflicts, string $step, array $rejected = []): array
    {
        $pending = array_values(array_filter(
            $this->conflictResolutions->pendingAcknowledgement($conflicts),
            fn (PrefillConflict $c): bool => $this->stepOwning($c->scope) === $step,
        ));

        return [
            'prefill_conflicts' => $conflicts,
            'prefill_conflicts_acknowledged' => $pending !== []
                && ($bag['conflictsAcknowledged'][$step]['fingerprint'] ?? null) === $this->conflictResolutions->acknowledgementFingerprint($pending),
            'prefill_conflict_resolutions' => $bag['conflictResolutions'],
            'prefill_conflict_documents' => $this->conflictDocumentNames($bag['documentIds']),
            'prefill_conflict_rejected' => $rejected,
        ];
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, string>
     */
    private function conflictDocumentNames(array $documentIds): array
    {
        return array_map(
            static fn (array $descriptor): string => (string) $descriptor['filename'],
            $this->conflictDocumentDescriptors($documentIds),
        );
    }

    /**
     * How each source file can be named in the record: what the lawyer called it
     * and what it actually contains.
     *
     * The hash is what ties a decision to the file that ended up in the filing
     * bundle. An id points at a row that can be deleted, and a name can be
     * reused; neither answers, two years on, which document was read.
     *
     * @param list<int> $documentIds
     * @return array<int, array{filename: ?string, contentHash: ?string}>
     */
    private function conflictDocumentDescriptors(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $descriptors = [];
        foreach ($this->documents->findBy(['id' => $documentIds]) as $document) {
            $id = $document->getId();
            if ($id !== null) {
                $descriptors[$id] = [
                    'filename' => $document->getOriginalFilename(),
                    'contentHash' => $document->getContentHash(),
                ];
            }
        }

        return $descriptors;
    }

    /**
     * The response for a step that refuses to advance.
     *
     * A submission held back by an unacknowledged conflict is well formed and
     * still unprocessable, which is the same status Symfony gives a re-rendered
     * invalid form. Sharing it keeps every rejected wizard POST answering the
     * same way, so Turbo renders the panel instead of taking a 200 for a step
     * the lawyer never completed.
     */
    private function stepRejected(bool $acknowledged): ?Response
    {
        return $acknowledged ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

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

        $claimItems = $raw['claimItems'] ?? null;
        if (is_array($claimItems)) {
            $claimItems = array_values(array_filter(
                $claimItems,
                static fn (mixed $row): bool => $row instanceof ClaimItemRow,
            ));
        } else {
            $claimItems = null;
        }

        $acknowledged = [];
        foreach (is_array($raw['conflictsAcknowledged'] ?? null) ? $raw['conflictsAcknowledged'] : [] as $step => $record) {
            if (!is_string($step) || !is_array($record)) {
                continue;
            }
            if (!is_string($record['fingerprint'] ?? null) || !is_string($record['at'] ?? null)) {
                continue;
            }
            $acknowledged[$step] = ['fingerprint' => $record['fingerprint'], 'at' => $record['at']];
        }

        $resolutions = [];
        foreach (is_array($raw['conflictResolutions'] ?? null) ? $raw['conflictResolutions'] : [] as $key => $resolution) {
            if (is_string($key) && $resolution instanceof ConflictResolution) {
                $resolutions[$key] = $resolution;
            }
        }

        return [
            'documentIds' => $ids,
            'creditor' => $creditor,
            'debtors' => $debtors,
            'claim' => $claim,
            'claimItems' => $claimItems,
            'claimItemsTableConfirmed' => (bool) ($raw['claimItemsTableConfirmed'] ?? false),
            'conflictResolutions' => $resolutions,
            'conflictsAcknowledged' => $acknowledged,
        ];
    }

    /**
     * @param array{documentIds: list<int>, creditor: ?Step1CreditorData, debtors: ?Step2DebtorsData, claim: ?Step3ClaimData, claimItems: ?list<ClaimItemRow>, claimItemsTableConfirmed: bool, conflictResolutions: array<string, ConflictResolution>} $bag
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
            if (!$document->getExtractionStatus()->isTerminal()) {
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
