<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-side of the notification center: unread count, the recent dropdown list, and
 * the ownership-guarded read lifecycle. Controllers stay thin and delegate here.
 * The full inbox list is served by the Tabulator table, not this service.
 */
final class NotificationCenterService
{
    public const DROPDOWN_SIZE = 10;

    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogService $auditLog,
    ) {}

    public function unreadCount(User $user): int
    {
        return $this->repository->countUnreadByUser($user);
    }

    /** @return Notification[] */
    public function recent(User $user): array
    {
        return $this->repository->findRecentByUser($user, self::DROPDOWN_SIZE);
    }

    /**
     * Mark one notification read. Returns null when the id does not belong to the
     * user (the controller turns that into a 403), so ownership is enforced here;
     * otherwise returns the row so the caller can redirect to its resourceLink.
     */
    public function markRead(int $id, User $user): ?Notification
    {
        $notification = $this->repository->findOneByIdAndUser($id, $user);
        if ($notification === null) {
            return null;
        }

        if (!$notification->isRead()) {
            $notification->setIsRead(true);
            $this->auditLog->log(
                action: 'notification_read',
                entityType: 'Notification',
                entityId: (string) $id,
                category: AuditLogService::CATEGORY_NOTIFICATION,
            );
            $this->em->flush();
        }

        return $notification;
    }

    /** Bulk mark-all-read; returns the number of rows flipped. */
    public function markAllRead(User $user): int
    {
        $affected = $this->repository->markAllReadByUser($user);

        if ($affected > 0) {
            $this->auditLog->log(
                action: 'notification_read_all',
                entityType: 'Notification',
                entityId: (string) $user->getId(),
                newData: ['count' => $affected],
                category: AuditLogService::CATEGORY_NOTIFICATION,
            );
            $this->em->flush();
        }

        return $affected;
    }
}
