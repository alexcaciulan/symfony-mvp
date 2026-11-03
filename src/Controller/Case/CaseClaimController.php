<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\DTO\Case\ClaimPositionInput;
use App\DTO\Case\ClaimRecalculationResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\ClaimItemRow;
use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Form\Case\ClaimDetailsType;
use App\Form\Case\ClaimExcludeType;
use App\Form\Case\ClaimInvoiceUploadType;
use App\Form\Case\ClaimPositionType;
use App\Message\ExtractDataMessage;
use App\Repository\ClaimItemRepository;
use App\Repository\DocumentRepository;
use App\Repository\LegalCaseRepository;
use App\Security\Voter\CaseVoter;
use App\Service\Case\ClaimDocumentPartyMatcher;
use App\Service\Case\ClaimEditRefused;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimPositionEditor;
use App\Service\Case\ClaimRecalculator;
use App\Service\Case\OverviewContextBuilder;
use App\Service\Document\DocumentUploadService;
use App\Service\Document\UploadDeduplicator;
use App\Service\Document\UploadRateLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editing the claim from the case page while the case is amiable: positions
 * added by hand or from an uploaded invoice, excluded or reinstated, the
 * details rewritten, a proposed court accepted. Every answer is a Turbo
 * Stream, success and refusal alike, so the dialog the lawyer typed in is
 * never lost to a reload.
 */
#[IsGranted('ROLE_USER')]
#[Route('/case/{id}/claim', requirements: ['id' => '\d+'])]
final class CaseClaimController extends AbstractController
{
    public const MODAL_ADD = 'hs-modal-claim-add';
    public const MODAL_EXCLUDE = 'hs-modal-claim-exclude';
    public const MODAL_DETAILS = 'hs-modal-claim-details';

    private const MAX_CAPPED_ATTACHMENTS = 10;

    /** Refusals that ask for an explicit confirmation inside the dialog, not just a toast. */
    private const CONFIRMABLE_REFUSALS = ['other_contract', 'clause_unconfirmed'];

    public function __construct(
        private readonly LegalCaseRepository $cases,
        private readonly ClaimItemRepository $claimItems,
        private readonly DocumentRepository $documents,
        private readonly ClaimPositionEditor $editor,
        private readonly ClaimRecalculator $recalculator,
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly ClaimDocumentPartyMatcher $partyMatcher,
        private readonly DocumentUploadService $uploadService,
        private readonly UploadDeduplicator $deduplicator,
        private readonly UploadRateLimiter $uploadRateLimiter,
        private readonly MessageBusInterface $bus,
        private readonly OverviewContextBuilder $contextBuilder,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/add', name: 'case_claim_add_manual', methods: ['POST'])]
    public function addManual(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $form = $this->createForm(ClaimPositionType::class, new ClaimPositionInput());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->firstErrorKey($form, 'case.claim.edit.error.invalid'));
        }

        $principalBefore = $case->getAmount();
        try {
            $result = $this->editor->addManual($case, $form->getData(), $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, 'manual');
        }

        return $this->respondChange($request, $case, $result, $principalBefore, 'case.claim.edit.flash.added', self::MODAL_ADD);
    }

    #[Route('/invoice/{documentId}/add', name: 'case_claim_add_from_document', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function addFromDocument(Request $request, int $id, int $documentId, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $document = $this->ownedInvoice($case, $documentId, $user);
        $form = $this->createForm(ClaimPositionType::class, new ClaimPositionInput());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->firstErrorKey($form, 'case.claim.edit.error.invalid'));
        }

        $principalBefore = $case->getAmount();
        try {
            $result = $this->editor->addFromDocument($case, $document, $form->getData(), $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, 'document');
        }

        return $this->respondChange($request, $case, $result, $principalBefore, 'case.claim.edit.flash.added', self::MODAL_ADD);
    }

    /**
     * Stores one invoice on the case and sends it to be read. The same ceilings
     * as the wizard apply: the traffic budget before the file is hashed, the
     * attachment cap, the fingerprint against the case's own documents, and the
     * extraction budget before anything is stored.
     */
    #[Route('/invoice', name: 'case_claim_invoice_upload', methods: ['POST'])]
    public function uploadInvoice(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $form = $this->createForm(ClaimInvoiceUploadType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->firstErrorKey($form, 'document.upload.error'));
        }

        $identifier = $user->getUserIdentifier();
        if (!$this->uploadRateLimiter->acceptsBatch($identifier, 1)) {
            return $this->respondToast($request, $case, 'warning', 'rate_limit.document_upload');
        }
        if ($this->documents->countCappedAttachments($case) >= self::MAX_CAPPED_ATTACHMENTS) {
            return $this->respondToast($request, $case, 'error', 'document.upload.max_files_reached');
        }

        $file = $form->get('file')->getData();
        $partition = $this->deduplicator->partition([$file], $this->documents->findByCase($case));
        if ($partition['files'] === []) {
            return $this->respondToast($request, $case, 'warning', 'case.claim.edit.error.duplicate_document', ['%file%' => $file->getClientOriginalName()]);
        }
        if (!$this->uploadRateLimiter->acceptsExtractions($identifier, 1)) {
            return $this->respondToast($request, $case, 'warning', 'rate_limit.document_upload');
        }

        try {
            $document = $this->uploadService->upload($case, $file, DocumentType::FACTURA, $user);
        } catch (\InvalidArgumentException) {
            return $this->respondToast($request, $case, 'error', 'document.upload.invalid_type');
        }
        $this->bus->dispatch(new ExtractDataMessage((int) $document->getId()));

        if (!$this->wantsStream($request)) {
            $this->addFlash('success', 'case.claim.edit.flash.invoice_uploaded');

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return $this->streamResponse($this->renderView('case/overview/_claim_invoice_upload_turbo_stream.html.twig', [
            'case' => $case,
            'status' => $this->invoiceStatusContext($case, $document, $user),
        ]));
    }

    /** The reading of an uploaded invoice, polled until it ends and then shown as a form to correct. */
    #[Route('/invoice/{documentId}', name: 'case_claim_invoice_status', requirements: ['documentId' => '\d+'], methods: ['GET'])]
    public function invoiceStatus(int $id, int $documentId, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $document = $this->ownedInvoice($case, $documentId, $user);

        return $this->render('case/overview/_claim_invoice_status.html.twig', [
            'case' => $case,
            'status' => $this->invoiceStatusContext($case, $document, $user),
        ]);
    }

    #[Route('/item/{itemId}/exclude', name: 'case_claim_exclude', requirements: ['itemId' => '\d+'], methods: ['POST'])]
    public function exclude(Request $request, int $id, int $itemId, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $item = $this->ownedItem($case, $itemId);
        $form = $this->createForm(ClaimExcludeType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->firstErrorKey($form, 'case.claim.edit.error.invalid'));
        }

        $data = $form->getData();
        $principalBefore = $case->getAmount();
        try {
            $result = $this->editor->exclude($case, $item, $data['reason'], $data['note'] ?? null, $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, null);
        }

        return $this->respondChange($request, $case, $result, $principalBefore, 'case.claim.edit.flash.excluded', self::MODAL_EXCLUDE);
    }

    #[Route('/item/{itemId}/reinclude', name: 'case_claim_reinclude', requirements: ['itemId' => '\d+'], methods: ['POST'])]
    public function reinclude(Request $request, int $id, int $itemId, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $item = $this->ownedItem($case, $itemId);
        if (!$this->isCsrfTokenValid('claim_reinclude_' . $itemId, $request->getPayload()->getString('_token'))) {
            return $this->respondToast($request, $case, 'error', 'case.claim.edit.error.invalid_csrf');
        }

        $principalBefore = $case->getAmount();
        try {
            $result = $this->editor->reinclude($case, $item, $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, null);
        }

        return $this->respondChange($request, $case, $result, $principalBefore, 'case.claim.edit.flash.reincluded', null);
    }

    #[Route('/details', name: 'case_claim_details', methods: ['POST'])]
    public function details(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $form = $this->createForm(ClaimDetailsType::class, new ClaimDetailsInput());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->respondToast($request, $case, 'error', $this->firstErrorKey($form, 'case.claim.edit.error.invalid'));
        }

        try {
            $result = $this->editor->updateDetails($case, $form->getData(), $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, null);
        }

        return $this->respondChange($request, $case, $result, $case->getAmount(), 'case.claim.edit.flash.details_saved', self::MODAL_DETAILS);
    }

    /**
     * Accepts the court the rules propose. The resolver runs again on the server
     * and the posted id must be its answer, mirroring the wizard's own check.
     */
    #[Route('/court/accept', name: 'case_claim_court_accept', methods: ['POST'])]
    public function acceptCourt(Request $request, int $id, #[CurrentUser] User $user): Response
    {
        $case = $this->editableCase($id);
        $payload = $request->getPayload();
        if (!$this->isCsrfTokenValid('claim_court_accept_' . $id, $payload->getString('_token'))) {
            return $this->respondToast($request, $case, 'error', 'case.claim.edit.error.invalid_csrf');
        }

        try {
            $court = $this->editor->acceptCourt($case, $payload->getInt('court_id'), $user);
        } catch (ClaimEditRefused $refusal) {
            return $this->respondRefusal($request, $case, $refusal, null);
        }

        return $this->respondChange($request, $case, null, $case->getAmount(), 'case.claim.edit.flash.court_accepted', null, ['%court%' => $court->getName()]);
    }

    private function editableCase(int $id): LegalCase
    {
        $case = $this->cases->findWithOverviewRelations($id);
        if ($case === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(CaseVoter::CLAIM_EDIT, $case);

        return $case;
    }

    private function ownedItem(LegalCase $case, int $itemId): ClaimItem
    {
        $item = $this->claimItems->find($itemId);
        if ($item === null || $item->getLegalCase()->getId() !== $case->getId()) {
            throw $this->createNotFoundException();
        }

        return $item;
    }

    /** An invoice of this case, uploaded by this lawyer: the reading endpoint shows its content. */
    private function ownedInvoice(LegalCase $case, int $documentId, User $user): Document
    {
        $document = $this->documents->find($documentId);
        if ($document === null
            || $document->getLegalCase()?->getId() !== $case->getId()
            || $document->getUploadedBy()->getId() !== $user->getId()
            || $document->getDocumentType() !== DocumentType::FACTURA) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    /**
     * What the dialog shows for an uploaded invoice: the wait, the reading as a
     * form to correct, a failed reading as an empty form, or the refusal of an
     * invoice between other parties. Read-only: the refused file stays on file
     * and can be deleted from the documents tab.
     *
     * @return array<string, mixed>
     */
    private function invoiceStatusContext(LegalCase $case, Document $document, User $user): array
    {
        $status = $document->getExtractionStatus();
        $base = [
            'document' => $document,
            'document_id' => $document->getId(),
            'filename' => $document->getOriginalFilename(),
            'file_size' => $document->getFileSize(),
            'values' => [],
            'detected' => [],
            'warning_keys' => [],
            'party_check' => null,
        ];

        if ($status === ExtractionStatus::COMPLETED) {
            $match = $this->partyMatcher->match($document, $case);
            if ($match->isCertainMismatch()) {
                return ['state' => 'party_mismatch', 'party_check' => $match] + $base;
            }
            $rows = $this->claimItemFactory->rowsFromDocuments([(int) $document->getId()]);
            $row = $rows[0] ?? null;
            if ($row === null) {
                return ['state' => 'no_claim', 'party_check' => $match, 'warning_keys' => $match->warningKeys()] + $base;
            }

            return [
                'state' => 'ready',
                'party_check' => $match,
                'values' => $this->valuesFromRow($row),
                'detected' => array_keys(array_filter($this->valuesFromRow($row), static fn ($v): bool => $v !== null && $v !== '')),
                'warning_keys' => [...$row->warningKeys, ...$match->warningKeys()],
            ] + $base;
        }
        if ($status === ExtractionStatus::FAILED || $status === ExtractionStatus::SKIPPED_BY_POLICY) {
            return ['state' => $status === ExtractionStatus::FAILED ? 'failed' : 'skipped'] + $base;
        }

        return ['state' => 'processing'] + $base;
    }

    /** @return array<string, ?string> */
    private function valuesFromRow(ClaimItemRow $row): array
    {
        return [
            'documentNumber' => $row->documentNumber,
            'documentDate' => $row->documentDate?->format('Y-m-d'),
            'dueDate' => $row->dueDate?->format('Y-m-d'),
            'amount' => number_format(abs($row->amount), 2, '.', ''),
            'currency' => $row->currency,
            'causeReference' => $row->causeReference,
            'description' => $row->description,
        ];
    }

    /**
     * The regions the edit changed, the summary of what moved and the dialog
     * closed, in one stream; a redirect with the summary as flashes otherwise.
     *
     * @param array<string, string> $toastParams
     */
    private function respondChange(
        Request $request,
        LegalCase $case,
        ?ClaimRecalculationResult $result,
        ?string $principalBefore,
        string $toastKey,
        ?string $closeModalId,
        array $toastParams = [],
    ): Response {
        $summary = $result !== null ? $this->summaryLines($case, $result, $principalBefore) : [];
        $warnings = $result !== null ? $this->warningLines($result) : [];
        if ($case->getPaymentNoticeDate() !== null) {
            $warnings[] = $this->translator->trans('case.claim.edit.changed.summons_outdated');
        }

        if (!$this->wantsStream($request)) {
            $this->addFlash('success', $this->translator->trans($toastKey, $toastParams));
            foreach ($warnings as $warning) {
                $this->addFlash('warning', $warning);
            }

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        $context = $this->contextBuilder->build($case, [
            'claim_change_summary' => ['lines' => $summary, 'warnings' => $warnings],
        ]);
        $context['toast_variant'] = 'success';
        $context['toast_message'] = $this->translator->trans($toastKey, $toastParams);
        $context['toast_details'] = $warnings;
        $context['close_modal_id'] = $closeModalId;

        return $this->streamResponse($this->renderView('case/overview/_claim_actions_turbo_stream.html.twig', $context));
    }

    private function respondRefusal(Request $request, LegalCase $case, ClaimEditRefused $refusal, ?string $scope): Response
    {
        $message = $this->translator->trans($refusal->messageKey(), $refusal->params);
        if (!$this->wantsStream($request)) {
            $this->addFlash('error', $message);

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        if ($scope !== null && in_array($refusal->reasonCode, self::CONFIRMABLE_REFUSALS, true)) {
            return $this->streamResponse($this->renderView('case/overview/_claim_confirmation_turbo_stream.html.twig', [
                'case' => $case,
                'scope' => $scope,
                'refusal_code' => $refusal->reasonCode,
                'message' => $message,
                'contractual' => $case->getPenaltyType()?->value === 'CONTRACTUAL',
            ]));
        }

        return $this->streamResponse($this->renderView('case/overview/_toast_turbo_stream.html.twig', [
            'message' => $message,
            'variant' => $refusal->reasonCode === 'duplicate_excluded' ? 'warning' : 'error',
        ]));
    }

    /** @param array<string, string> $params */
    private function respondToast(Request $request, LegalCase $case, string $variant, string $messageKey, array $params = []): Response
    {
        $message = $this->translator->trans($messageKey, $params);
        if (!$this->wantsStream($request)) {
            $this->addFlash($variant, $message);

            return $this->redirectToRoute('case_overview', ['id' => $case->getId()]);
        }

        return $this->streamResponse($this->renderView('case/overview/_toast_turbo_stream.html.twig', [
            'message' => $message,
            'variant' => $variant,
        ]));
    }

    /**
     * What the edit changed, line by line: the principal, the accessory at its
     * reference date, the court the rules now name, the limitation terms.
     *
     * @return list<string>
     */
    private function summaryLines(LegalCase $case, ClaimRecalculationResult $result, ?string $principalBefore): array
    {
        $lines = [];
        $principalAfter = $case->getAmount();
        if ($principalBefore !== $principalAfter) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.amount', [
                '%from%' => $this->money($principalBefore),
                '%to%' => $this->money($principalAfter),
            ]);
        }
        $reference = $result->referenceDate->format('d.m.Y');
        if ($result->accessoryChange !== null) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.accessory', [
                '%from%' => $this->money($result->accessoryChange['from']),
                '%to%' => $this->money($result->accessoryChange['to']),
                '%date%' => $reference,
            ]);
        } else {
            $lines[] = $this->translator->trans('case.claim.edit.changed.accessory_reference', ['%date%' => $reference]);
        }
        $proposal = $result->courtProposal;
        if ($proposal !== null && $proposal->isChange()) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.court_proposed', [
                '%court%' => (string) $proposal->proposed?->getName(),
                '%current%' => $proposal->current?->getName() ?? $this->translator->trans('case_overview.court.no_court'),
            ]);
        } elseif ($proposal !== null && $proposal->isUnresolved()) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.court_unresolved', [
                '%reason%' => $proposal->explanationKey !== null ? $this->translator->trans($proposal->explanationKey, $proposal->explanationParams) : '',
            ]);
        }
        if ($result->deadlinesCreated !== []) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.deadlines_created', [
                '%count%' => count($result->deadlinesCreated),
                '%dates%' => implode(', ', array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('d.m.Y'), $result->deadlinesCreated)),
            ]);
        }
        if ($result->deadlinesRemoved !== []) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.deadlines_removed', ['%count%' => count($result->deadlinesRemoved)]);
        }
        if ($result->deadlinesUnmatched !== []) {
            $lines[] = $this->translator->trans('case.claim.edit.changed.deadlines_unmatched', [
                '%count%' => count($result->deadlinesUnmatched),
                '%dates%' => implode(', ', array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('d.m.Y'), $result->deadlinesUnmatched)),
            ]);
        }

        return $lines;
    }

    /**
     * The admissibility and computability issues the claim now carries, worded
     * for the edit: a future due date blocks the summons, a prescribed invoice
     * is the lawyer's call, a cutoff before a due date leaves no accessory.
     *
     * @return list<string>
     */
    private function warningLines(ClaimRecalculationResult $result): array
    {
        $lines = [];
        foreach ([...$result->issues['errors'], ...$result->issues['warnings']] as $issue) {
            $lines[] = $this->issueLine($issue);
        }

        return array_values(array_unique($lines));
    }

    private function issueLine(AdmissibilityIssue $issue): string
    {
        $key = match ($issue->code) {
            'OP_ITEM_NOT_YET_DUE' => 'case.claim.edit.warning.not_yet_due',
            'OP_ITEM_PRESCRIBED' => 'case.claim.edit.warning.prescribed',
            ClaimRecalculator::CODE_CUTOFF_BEFORE_DUE_DATE => 'case.claim.edit.warning.cutoff_before_due',
            default => $issue->messageKey,
        };

        return $this->translator->trans($key, $issue->params);
    }

    private function money(?string $amount): string
    {
        return number_format((float) ($amount ?? '0'), 2, ',', '.') . ' RON';
    }

    private function firstErrorKey(FormInterface $form, string $fallback): string
    {
        foreach ($form->getErrors(true) as $error) {
            return $error->getMessage();
        }

        return $fallback;
    }

    private function wantsStream(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html');
    }

    private function streamResponse(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html; charset=utf-8']);
    }
}
