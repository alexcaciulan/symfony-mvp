<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\Table\Column;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Reference table definition: the current user's notifications. Serves as the
 * canonical example to copy for new tables (cases, reports, etc.).
 */
final class NotificationTableDefinition implements TableDefinitionInterface
{
    public function __construct(private readonly NotificationRepository $repository) {}

    public function key(): string
    {
        return 'notifications';
    }

    public function getColumns(): array
    {
        return [
            new Column('createdAt', 'table.notifications.columns.created_at', sortable: true, formatter: 'datetime', width: 160),
            new Column('type', 'table.notifications.columns.type', filterable: true, filterType: 'text', width: 160),
            new Column('title', 'table.notifications.columns.title'),
            new Column('isRead', 'table.notifications.columns.read', sortable: true, filterable: true, filterType: 'bool', formatter: 'bool', width: 120),
        ];
    }

    public function createScopedQueryBuilder(User $user): QueryBuilder
    {
        return $this->repository->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');
    }

    public function serializeRow(object $row): array
    {
        if (!$row instanceof Notification) {
            throw new \UnexpectedValueException('Expected a Notification row.');
        }

        // Exposed fields are deliberately PII-free: title carries only the case
        // number, and the full message is never serialized (GDPR minimisation).
        return [
            'id' => $row->getId(),
            'createdAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'type' => $row->getType(),
            'title' => $row->getTitle(),
            'isRead' => $row->isRead(),
            'link' => $row->getResourceLink(),
        ];
    }
}
