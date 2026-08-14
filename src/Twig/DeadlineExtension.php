<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineAlertService;
use App\Service\Deadline\DeadlineConsequenceResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Deadline facts the templates need but must not derive themselves: the number of
 * arrears of the current user, whether a past-due date counts as one, and when the
 * next email alert on a deadline goes out.
 *
 * The arrears count is what makes the navigation badge server rendered on first paint.
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
        private readonly DeadlineAlertService $alertService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('overdue_deadlines_count', $this->overdueCount(...)),
            new TwigFunction('deadline_is_arrear', $this->isArrear(...)),
            new TwigFunction('deadline_next_alert_days_before', $this->nextAlertDaysBefore(...)),
            new TwigFunction('deadline_alert_ladder', $this->alertLadder(...)),
            new TwigFunction('deadline_alerts_muted', $this->alertsMuted(...)),
        ];
    }

    /**
     * Whether an open term deliberately sends no reminders, so the card can say so
     * instead of printing an empty ladder next to the word "Alerts".
     */
    public function alertsMuted(LegalDeadline $deadline): bool
    {
        return $this->alertService->alertsMuted($deadline);
    }

    /**
     * Every reminder scheduled for a deadline, loosest first, each with whether it has
     * gone out. The tiers depend on the type, which is why the card cannot list them
     * itself.
     *
     * @return list<array{days: int, sent: bool}>
     */
    public function alertLadder(LegalDeadline $deadline): array
    {
        return $this->alertService->alertLadder($deadline);
    }

    /**
     * Days before expiry the next email alert on this deadline goes out, 0 when only
     * the expiry alert is left, null when every alert has been sent.
     *
     * Exposed as a function rather than passed down from the controller because the
     * card that prints it is included three levels deep, and because the ladder is not
     * the same for every type: the limitation terms are warned about weeks ahead, the
     * procedural ones days ahead.
     */
    public function nextAlertDaysBefore(LegalDeadline $deadline): ?int
    {
        return $this->alertService->nextAlertDaysBefore($deadline);
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
