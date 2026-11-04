<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\DTO\Wizard\DocumentRemovalPlan;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\RemovalStepOutcome;
use App\Form\Wizard\Step0DocumentsType;
use App\Message\ExtractDataMessage;
use App\Service\Case\ClaimItemFactory;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\UploadDeduplicator;
use App\Service\Document\UploadRateLimiter;
use App\Service\Extraction\DetectedDataPreviewBuilder;
use App\Service\Extraction\DocumentRemovalPlanner;
use App\Service\Extraction\ExtractionReuseService;
use App\Service\Extraction\PrefillFromExtractionService;
use App\Service\Wizard\CaseDraftLimit;
use App\Service\Wizard\CaseDraftListBuilder;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardDocumentReader;
use App\Service\Wizard\WizardStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Wizard step 0: the entry of "Dosar nou" and the source documents.
 *
 * Documents uploaded here have legal_case_id = NULL until the confirmation
 * step attaches them to the freshly persisted LegalCase; the bag keeps their
 * ids under `documentIds`.
 */
#[Route('/case/new', name: 'case_wizard_')]
#[IsGranted('ROLE_USER')]
final class Step0DocumentsController extends AbstractWizardStepController
{
    public function __construct(
        WizardStateStore $stateStore,
        TranslatorInterface $translator,
        private readonly DocumentUploadService $uploadService,
        private readonly UploadDeduplicator $deduplicator,
        private readonly UploadRateLimiter $uploadRateLimiter,
        private readonly PrefillFromExtractionService $prefill,
        private readonly EntityManagerInterface $em,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly DetectedDataPreviewBuilder $detectedDataPreview,
        private readonly MessageBusInterface $bus,
        private readonly ExtractionReuseService $extractionReuse,
        private readonly CaseDraftLimit $draftLimit,
        private readonly WizardDocumentReader $documentReader,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($stateStore, $translator);
    }

    /**
     * The entry of "Dosar nou". With no unfinished run on record the wizard
     * opens on step 0; otherwise the user chooses between continuing one of
     * them and starting another, so a click never buries work in progress.
     */
    #[Route('', name: 'start', methods: ['GET'])]
    public function start(Request $request, #[CurrentUser] User $user, CaseDraftListBuilder $draftList): Response
    {
        $drafts = $draftList->forUser($user, $request->getSession());
        if ($drafts === []) {
            return $this->redirectToRoute('case_wizard_documents');
        }

        return $this->render('case/wizard_drafts.html.twig', [
            'drafts' => $drafts,
            'draft_limit' => CaseDraftLimit::MAX_PER_USER,
            'limit_reached' => $this->draftLimit->isReached($user),
        ]);
    }

    /**
     * Starts a run from an empty bag and leaves the existing drafts as they
     * are. Past the cap the user is sent back to the list to continue or drop
     * one of them.
     */
    #[Route('/draft/new', name: 'draft_new', methods: ['POST'])]
    public function newDraft(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('case_wizard_draft_new', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        if ($this->draftLimit->isReached($user)) {
            $this->addFlash('warning', $this->trans('case.drafts.limit_reached', ['%limit%' => CaseDraftLimit::MAX_PER_USER]));

            return $this->redirectToRoute('case_wizard_start');
        }

        $this->stateStore->detach($request->getSession());

        return $this->redirectToRoute('case_wizard_documents');
    }

    #[Route('/documents', name: 'documents', methods: ['GET', 'POST'])]
    public function documents(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $session = $request->getSession();
        if ($request->isMethod('GET') && $this->draftLimit->blocksNewRun($user, $session)) {
            $this->addFlash('warning', $this->trans('case.drafts.limit_reached', ['%limit%' => CaseDraftLimit::MAX_PER_USER]));

            return $this->redirectToRoute('case_wizard_start');
        }
        $bag = $this->stateStore->load($session);

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

            $outcome = $this->storeBatch($bag, $session, $user, $uploaded, $declaredType);

            if ($isTurboStreamRequest || $isTurboFrameRequest) {
                return $this->streamResponse($request, $bag, $session, $user, $outcome);
            }

            $this->flashOutcome($outcome);

            return $this->redirectToRoute('case_wizard_documents');
        }

        $documents = $this->documentReader->loadOwnedDocuments($bag->documentIds, $user);
        $bag->documentIds = array_map(static fn (Document $d) => $d->getId(), $documents);
        $this->stateStore->save($session, $bag);

        $viewVars = [
            'current_step' => 0,
            'form' => $form,
            ...$this->previewVars($bag, $documents),
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

    /**
     * Rate-limits, deduplicates, reuses known extractions, stores and dispatches
     * one uploaded batch.
     *
     * @param list<UploadedFile> $uploaded
     *
     * @return array{rateLimitHit: bool, duplicates: list<string>, uploadErrors: list<string>, storedCount: int}
     */
    private function storeBatch(WizardBag $bag, SessionInterface $session, User $user, array $uploaded, DocumentType $declaredType): array
    {
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
            $partition = $this->deduplicator->partition($uploaded, $this->documentReader->loadOwnedDocuments($bag->documentIds, $user));
            $accepted = $partition['files'];
            $duplicates = $partition['duplicates'];

            // A file this lawyer already had read once, in another run or
            // case, keeps that reading instead of being read again.
            $reusable = [];
            foreach ($accepted as $index => $file) {
                $hash = $partition['hashes'][$index] ?? null;
                $source = $hash !== null ? $this->extractionReuse->findReusable($user, $hash, $declaredType) : null;
                if ($source !== null) {
                    $reusable[$index] = $source;
                }
            }

            // Only files that will really be stored and extracted cost
            // extraction budget: a known file costs no LLM call.
            $rateLimitHit = !$this->uploadRateLimiter->acceptsExtractions($identifier, count($accepted) - count($reusable));
        }

        if (!$rateLimitHit) {
            foreach ($accepted as $index => $file) {
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
                $bag->documentIds[] = $document->getId();
                ++$storedCount;
                if (isset($reusable[$index])) {
                    $this->extractionReuse->copyInto($reusable[$index], $document);
                    continue;
                }
                $this->bus->dispatch(new ExtractDataMessage($document->getId()));
            }
            $this->stateStore->save($session, $bag);
        }

        return [
            'rateLimitHit' => $rateLimitHit,
            'duplicates' => $duplicates,
            'uploadErrors' => $uploadErrors,
            'storedCount' => $storedCount,
        ];
    }

    /**
     * @param array{rateLimitHit: bool, duplicates: list<string>, uploadErrors: list<string>, storedCount: int} $outcome
     */
    private function streamResponse(Request $request, WizardBag $bag, SessionInterface $session, User $user, array $outcome): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        $documents = $this->documentReader->loadOwnedDocuments($bag->documentIds, $user);
        $bag->documentIds = array_map(static fn (Document $d) => $d->getId(), $documents);
        $this->stateStore->save($session, $bag);

        return $this->render('case/_step0_upload_stream.html.twig', [
            ...$this->previewVars($bag, $documents),
            'toasts' => $this->uploadToasts($outcome),
        ]);
    }

    /**
     * One toast per outcome, so a mixed batch reports the skipped file AND
     * confirms the stored one, exactly like the flash bag of the non-Turbo path.
     *
     * @param array{rateLimitHit: bool, duplicates: list<string>, uploadErrors: list<string>, storedCount: int} $outcome
     *
     * @return list<array{message: string, variant: string, params: array<string, string>}>
     */
    private function uploadToasts(array $outcome): array
    {
        $toasts = [];
        if ($outcome['rateLimitHit']) {
            $toasts[] = ['message' => 'rate_limit.document_upload', 'variant' => 'warning', 'params' => []];

            return $toasts;
        }
        foreach ($outcome['uploadErrors'] as $uploadError) {
            $toasts[] = ['message' => $uploadError, 'variant' => 'error', 'params' => []];
        }
        if ($outcome['duplicates'] !== []) {
            $toasts[] = [
                'message' => 'wizard.step0.toast.duplicate_skipped',
                'variant' => 'warning',
                'params' => ['{files}' => implode(', ', $outcome['duplicates'])],
            ];
        }
        if ($outcome['storedCount'] > 0) {
            $toasts[] = ['message' => 'wizard.flash.uploaded', 'variant' => 'success', 'params' => []];
        }

        return $toasts;
    }

    /**
     * @param array{rateLimitHit: bool, duplicates: list<string>, uploadErrors: list<string>, storedCount: int} $outcome
     */
    private function flashOutcome(array $outcome): void
    {
        if ($outcome['rateLimitHit']) {
            $this->addFlash('warning', 'rate_limit.document_upload');

            return;
        }
        foreach ($outcome['uploadErrors'] as $err) {
            $this->addFlash('error', $err);
        }
        if ($outcome['duplicates'] !== []) {
            $this->addFlash('warning', $this->trans('wizard.step0.toast.duplicate_skipped', [
                '{files}' => implode(', ', $outcome['duplicates']),
            ]));
        }
        if ($outcome['storedCount'] > 0) {
            $this->addFlash('success', 'wizard.flash.uploaded');
        }
    }

    /**
     * Variables shared by the full page, its Turbo frames and the upload stream.
     * One aggregation feeds the whole side card: the previews have to agree
     * with each other, and several passes over the same documents are several
     * chances for them not to.
     *
     * @param list<Document> $documents
     *
     * @return array<string, mixed>
     */
    private function previewVars(WizardBag $bag, array $documents): array
    {
        $preview = $this->prefill->aggregate($bag->documentIds, $bag->conflictResolutions);
        $collected = $this->claimItemFactory->collectRows($bag->documentIds);
        $positions = $this->documentReader->positionsPreview($collected);
        // Two documents giving one invoice different sums or due dates is said
        // here too, so the card does not show one of them as settled.
        $step0Conflicts = [...$preview->conflicts, ...$collected->conflicts];

        return [
            'documents' => $documents,
            'creditor_preview' => $preview->creditor,
            'debtor_preview' => $preview->debtors->debtors[0],
            'claim_preview' => $preview->claim,
            'claim_positions_preview' => $positions,
            'detected_sections' => $this->detectedDataPreview->build($preview->creditor, $preview->debtors->debtors[0], $preview->claim, $step0Conflicts, $positions, $bag->conflictResolutions),
            // Shown here as soon as the documents disagree, read only: the
            // extraction of the other files may still be running, so the set is
            // not final and the decision belongs on the step that owns the
            // field. Seeing it now is what stops the lawyer filling three steps
            // on a party two documents describe differently.
            'prefill_conflicts' => $step0Conflicts,
            'prefill_conflict_resolutions' => $bag->conflictResolutions,
            'prefill_conflict_documents' => $this->documentReader->conflictDocumentNames($bag->documentIds),
            'all_terminal' => $this->documentReader->allTerminal($documents),
            'consumer_debtor' => $this->documentReader->consumerDebtorName($preview),
            // Choices for the per-document type correction shown on each card.
            'document_types' => DocumentType::uploadableTypes(),
        ];
    }

    #[Route('/documents/status', name: 'documents_status', methods: ['GET'])]
    public function documentsStatus(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $bag = $this->stateStore->load($request->getSession());
        $sessionIds = array_flip($bag->documentIds);

        $idsParam = $request->query->all('ids');
        $askedIds = array_values(array_unique(array_map('intval', is_array($idsParam) ? $idsParam : [])));

        $documents = $this->documentReader->loadOwnedDocuments($askedIds, $user);

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

        $bag = $this->stateStore->load($request->getSession());
        if (!in_array($id, $bag->documentIds, true)) {
            throw $this->createNotFoundException('Document is not part of the current wizard session');
        }

        $documents = $this->documentReader->loadOwnedDocuments([$id], $user);
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
     * The confirmation dialog: what removing this document does to the steps
     * already saved, worked out before anything is deleted.
     */
    #[Route('/documents/{id}/remove/preview', name: 'documents_remove_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function removeDocumentPreview(Request $request, int $id, #[CurrentUser] User $user, DocumentRemovalPlanner $planner): Response
    {
        $bag = $this->stateStore->load($request->getSession());
        $document = $this->removableDocument($bag, $id, $user);

        return $this->render('case/_step0_remove_dialog.html.twig', [
            'document' => $document,
            'plan' => $planner->plan($bag->toArray(), $id),
        ]);
    }

    /**
     * Removes a document uploaded by mistake and refills the saved steps the
     * plan says it fed. A document still being read is left alone.
     */
    #[Route('/documents/{id}/remove', name: 'documents_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeDocument(Request $request, int $id, #[CurrentUser] User $user, DocumentRemovalPlanner $planner): Response
    {
        if (!$this->isCsrfTokenValid('wizard_step0_remove_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        $document = $this->removableDocument($bag, $id, $user);
        if ($document->getExtractionStatus() === ExtractionStatus::PROCESSING) {
            $this->addFlash('warning', 'wizard.step0.remove.processing');

            return $this->redirectToRoute('case_wizard_documents');
        }

        $state = $bag->toArray();
        $plan = $planner->plan($state, $id);
        $planner->apply($state, $id, $plan);
        $this->stateStore->save($session, WizardBag::fromArray($state));
        $this->uploadService->delete($document);

        $this->addFlash('success', $this->removalMessage($plan));

        return $this->redirectToRoute('case_wizard_documents');
    }

    private function removableDocument(WizardBag $bag, int $id, User $user): Document
    {
        if (!in_array($id, $bag->documentIds, true)) {
            throw $this->createNotFoundException('Document is not part of the current wizard session');
        }
        $document = $this->documentReader->loadOwnedDocuments([$id], $user)[0] ?? null;
        if ($document === null || $document->getLegalCase() !== null) {
            throw $this->createNotFoundException('Document not found');
        }

        return $document;
    }

    /** What the removal changed, said after the fact as the dialog said it before. */
    private function removalMessage(DocumentRemovalPlan $plan): string
    {
        $parts = [$this->trans('wizard.step0.remove.done')];
        if ($plan->creditor === RemovalStepOutcome::REFILLED) {
            $parts[] = $this->trans('wizard.step0.remove.done_creditor_refilled');
        }
        if ($plan->debtor === RemovalStepOutcome::REFILLED) {
            $parts[] = $this->trans('wizard.step0.remove.done_debtor_refilled');
        }
        if ($plan->claimRefreshed) {
            $parts[] = $this->trans('wizard.step0.remove.done_claim_refreshed');
        }

        return implode(' ', $parts);
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

        $bag = $this->stateStore->load($request->getSession());
        if (!in_array($id, $bag->documentIds, true)) {
            throw $this->createNotFoundException('Document is not part of the current wizard session');
        }

        $documents = $this->documentReader->loadOwnedDocuments([$id], $user);
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

    /**
     * Goes on without documents. What was uploaded so far is deleted now: the
     * lawyer chose to do without it, and a file kept nowhere would only wait
     * for the sweeper. A document still being read is left to it instead.
     */
    #[Route('/skip', name: 'skip', methods: ['POST'])]
    public function skip(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('wizard_step0_skip', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'wizard.step0.error.invalid_csrf');

            return $this->redirectToRoute('case_wizard_documents');
        }

        $session = $request->getSession();
        $bag = $this->stateStore->load($session);
        foreach ($this->documentReader->loadOwnedDocuments($bag->documentIds, $user) as $document) {
            if ($document->getLegalCase() !== null || $document->getExtractionStatus() === ExtractionStatus::PROCESSING) {
                continue;
            }
            $this->uploadService->delete($document, $user);
        }
        $bag->documentIds = [];
        $this->stateStore->save($session, $bag);

        $this->addFlash('info', 'wizard.flash.skipped');

        return $this->redirectToRoute('case_wizard_creditor');
    }
}
