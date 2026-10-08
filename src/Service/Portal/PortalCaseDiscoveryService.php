<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\LegalCase;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Looks for a filed case on portal.just.ro without waiting for the lawyer to
 * ask, and tells them when it is found.
 *
 * Finding is not activating. The portal names parties only, never their CUI,
 * so a match is a strong guess, not an identification: the lawyer confirms it
 * with the button that already exists on the case's portal tab, and only then
 * does monitoring start. That human check is the point, so only a match the
 * matcher rates as certain enough to propose is announced, and each case number
 * is announced once. The number is also kept on the case as a proposal, which the
 * case page shows until the lawyer confirms or sets it aside; a number set aside
 * is not proposed again, because its announcement already exists.
 */
final class PortalCaseDiscoveryService
{
    public function __construct(
        private readonly PortalCaseMatcher $matcher,
        private readonly NotificationDispatcherInterface $notificationDispatcher,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return ?string the portal case number announced, null when nothing was
     */
    public function discover(LegalCase $case): ?string
    {
        $suggestions = $this->matcher->findCandidates($case);
        $best = $suggestions[0] ?? null;
        if ($best === null || !$best->isHighConfidence) {
            return null;
        }

        $dedupKey = sprintf('portal_case_found:%d:%s', (int) $case->getId(), $best->numar);
        if ($this->notifications->findOneByDedupKey($dedupKey) !== null) {
            return $best->numar;
        }

        $case->setPortalProposedNumber($best->numar);
        $this->em->flush();

        $params = ['%case%' => $case->getCaseNumber(), '%number%' => $best->numar];
        $this->notificationDispatcher->dispatch(new NotificationDispatch(
            user: $case->getUser(),
            legalCase: $case,
            type: NotificationType::PORTAL_CASE_FOUND,
            title: $this->translator->trans('notification.portal_case_found.title', $params),
            message: $this->translator->trans('notification.portal_case_found.message', $params),
            resourceLink: $this->urlGenerator->generate('case_overview', ['id' => $case->getId(), 'tab' => 'portal']),
            variant: 'info',
            emailSubject: null,
            emailTemplate: null,
            dedupKey: $dedupKey,
        ));

        return $best->numar;
    }
}
