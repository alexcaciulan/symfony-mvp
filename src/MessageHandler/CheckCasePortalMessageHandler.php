<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\LegalCase;
use App\Message\CheckCasePortalMessage;
use App\Repository\LegalCaseRepository;
use App\Service\Portal\CaseMonitoringService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumes {@see CheckCasePortalMessage} in the async worker and runs the portal
 * check for a single case.
 *
 * Retry: unlike ExtractDataMessageHandler (which swallows errors and ACKs), here
 * we let PortalJustException/throwables propagate so Messenger retries per the
 * `retry_strategy` (max 3, multiplier 2). A missing or deleted case ACKs without
 * retry (nothing to retry).
 */
#[AsMessageHandler]
final class CheckCasePortalMessageHandler
{
    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly CaseMonitoringService $monitoringService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(CheckCasePortalMessage $message): void
    {
        $case = $this->caseRepository->find($message->caseId);
        if (!$case instanceof LegalCase || $case->isDeleted()) {
            $this->logger->warning('Portal check skipped: case not found or deleted', [
                'caseId' => $message->caseId,
            ]);

            return;
        }

        // Exceptions propagate intentionally so Messenger retries on transient failure.
        $this->monitoringService->monitorCase($case);
    }
}
