<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineConsequenceResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the number of arrears of the current user, so the navigation badge is
 * server rendered on first paint.
 *
 * The count comes from the aggregate the deadlines page and the dashboard KPI read,
 * so the badge, the "Restante" pill and the card can never disagree. An action fired
 * from the agenda replaces the badge in the same stream that replaces the pill, which
 * is what keeps them equal without a full navigation.
 *
 * Memoized for the request: the sidebar and the mobile navigation both render one
 * badge, and the query must not fire twice for the same page.
 */
final class DeadlineExtension extends AbstractExtension
{
    private ?int $overdueCount = null;

    public function __construct(
        private readonly Security $security,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineConsequenceResolver $consequenceResolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('overdue_deadlines_count', $this->overdueCount(...)),
            new TwigFunction('deadline_is_arrear', $this->isArrear(...)),
        ];
    }

    /**
     * Whether a past-due deadline is an arrear of the lawyer.
     *
     * The response term of the debtor is not: its expiry is the event that unblocks
     * filing the payment order, so counting it as a missed obligation would report a
     * failure where the procedure actually moved forward. This is the same rule the
     * arrears aggregate applies, exposed to the templates that label a date as missed
     * so a list cannot contradict the counter shown above it.
     */
    public function isArrear(DeadlineType $type): bool
    {
        return $this->consequenceResolver->resolve($type) !== DeadlineConsequence::NO_SANCTION;
    }

    public function overdueCount(): int
    {
        if ($this->overdueCount !== null) {
            return $this->overdueCount;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->overdueCount = 0;
        }

        return $this->overdueCount = $this->deadlineRepository->countOverdueByUser($user);
    }
}
