<?php

declare(strict_types=1);

namespace App\Controller\Case;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Form\Wizard\Step0DocumentsType;
use App\Message\ExtractDataMessage;
use App\Repository\DocumentRepository;
use App\Service\Document\DocumentUploadService;
use App\Service\Extraction\PrefillFromExtractionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pas 3.0 wizard — step 0 (Documente sursă).
 *
 * Routes are mounted at /case/new. Pas 3.0 wires step 0 (documents upload +
 * async extraction + side-card preview) plus a placeholder for step 1
 * (creditor) that returns 501 until Pas 3.1/3.2 implement it.
 *
 * Session bag `case_wizard_data` carries cross-step state. Pas 3.0 only writes
 * the `documentIds` key; later steps add `creditor`, `debtors`, `claim`.
 *
 * Documents uploaded here have legal_case_id = NULL (per the Pas 3.0 migration).
 * Pas 3.2 step-4 submit attaches them to the freshly created LegalCase.
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
        $session->set(self::SESSION_KEY, ['documentIds' => []]);

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

            // Rate limit consumed BEFORE iterating uploads — the wizard shares
            // the same `document_upload` bucket as the per-case DocumentController
            // (20/h sliding_window). We charge one token per file submitted in
            // this request; if the user splices the bucket they can retry.
            $limiter = $documentUploadLimiter->create($user->getUserIdentifier());
            $rateLimitHit = !$limiter->consume(count($uploaded))->isAccepted();

            $uploadErrors = [];
            if (!$rateLimitHit) {
                foreach ($uploaded as $file) {
                    try {
                        $document = $this->uploadService->upload(null, $file, DocumentType::ALT_DOCUMENT, $user);
                    } catch (\InvalidArgumentException) {
                        // MIME sniff rejected the file post-upload. The form-level
                        // mime validator should have caught it, but if a magic-byte
                        // mismatch slipped through we surface a translated error
                        // (NOT the raw exception text — that's an English technical
                        // string that exposes the sniffed MIME and breaks GDPR
                        // minimisation on the toast / log surface) and continue
                        // with the next file rather than crashing the batch. Log
                        // the filename + userId so security ops can spot repeated
                        // bypass attempts without leaking the sniffed MIME itself.
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

            // Turbo flow — return a Stream that swaps the dynamic frame +
            // sidecard + appends a toast. No PRG redirect, no flash messages
            // disappearing on reload, no full-page repaint.
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

            // Non-Turbo fallback (e.g. JS disabled): legacy PRG.
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
        // Re-save in case any session-tracked IDs no longer exist in the DB
        // (deleted between requests). Keeps the bag consistent for the next GET.
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

        // GET inside a Turbo Frame (e.g. driven by Stimulus frame.reload()):
        // serve just the requested partial so the browser swaps the frame
        // in-place rather than re-rendering the whole shell.
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
            // Only expose status for documents that belong to the caller's
            // current wizard session — otherwise an attacker could probe
            // arbitrary document IDs via this endpoint.
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
    public function creditorPlaceholder(): Response
    {
        // Pas 3.1/3.2 will implement this route with the real form. For now
        // we return a stable 501 so the placeholder is visible without
        // looking like a bug.
        return $this->render('case/_step_placeholder.html.twig', [
            'step_number' => 1,
            'step_key' => 'creditor',
        ], new Response(null, Response::HTTP_NOT_IMPLEMENTED));
    }

    /**
     * @return array{documentIds: list<int>}
     */
    private function loadBag(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): array
    {
        $raw = $session->get(self::SESSION_KEY, []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = $raw['documentIds'] ?? [];
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];

        return ['documentIds' => $ids];
    }

    /**
     * @param array{documentIds: list<int>} $bag
     */
    private function saveBag(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, array $bag): void
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
        // Hard ownership check — a document with a different uploader doesn't
        // belong in this user's session and we drop it.
        $owned = array_values(array_filter(
            $found,
            static fn (Document $d) => $d->getUploadedBy()->getId() === $user->getId(),
        ));

        // Preserve session order (most-recent-first display matches the order
        // they were uploaded in).
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
}
