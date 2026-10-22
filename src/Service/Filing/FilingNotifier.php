<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\NotificationType;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the lawyer is told about a court filing. Never "filed", "registered" or
 * "received": the platform only knows that a mail server accepted a message.
 */
final class FilingNotifier
{
    public function __construct(
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%app.base_url%')]
        private readonly string $baseUrl,
    ) {}

    public function petitionSent(FilingSubmission $submission): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_SENT, 'filing_sent', 'info', [
            '%case%' => $this->caseLabel($case),
            '%address%' => $submission->getToAddress(),
        ], 'filing_sent:' . $submission->getId());
    }

    /**
     * @param list<string> $sent    titles of the documents that left
     * @param list<string> $notSent titles of the documents that did not
     */
    public function annexesCancelled(FilingSubmission $submission, array $sent, array $notSent): void
    {
        $case = $submission->getLegalCase();
        $this->dispatch($case, NotificationType::FILING_ANNEXES_CANCELLED, 'filing_annexes_cancelled', 'warning', [
            '%case%' => $this->caseLabel($case),
            '%sent%' => implode(', ', $sent),
            '%not_sent%' => implode(', ', $notSent),
        ], 'filing_annexes_cancelled:' . $submission->getId());
    }

    /** @param array<string, string> $params */
    private function dispatch(LegalCase $case, NotificationType $type, string $key, string $variant, array $params, string $dedupKey): void
    {
        $link = $this->urlGenerator->generate('case_overview', ['id' => $case->getId()]);
        $title = $this->translator->trans("notification.$key.title", $params);
        $message = $this->translator->trans("notification.$key.message", $params);

        $this->dispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: $type,
            title: $title,
            message: $message,
            resourceLink: $link,
            variant: $variant,
            emailSubject: $this->translator->trans("email.$key.subject", $params),
            emailTemplate: 'emails/case_status.html.twig',
            emailContext: [
                'case' => $case,
                'caseLabel' => $params['%case%'],
                'caseUrl' => rtrim($this->baseUrl, '/') . $link,
                'heading' => $title,
                'body' => $message,
            ],
            dedupKey: $dedupKey,
        ));
    }

    private function caseLabel(LegalCase $case): string
    {
        return $case->getCourtCaseNumber() ?? $case->getCaseNumber();
    }
}
