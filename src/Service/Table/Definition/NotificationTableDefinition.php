<?php

declare(strict_types=1);

namespace App\Service\Table\Definition;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use App\Service\Table\Column;
use App\Service\Table\Filter;
use App\Service\Table\TableDefinitionInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Notifications table: the full "inbox" surface (bell dropdown handles the recent
 * few). Columns + a decoupled toolbar (type multi-select + read/unread) with
 * remote filtering/pagination, and an open-link to the related case. Message body
 * is deliberately never serialized (GDPR minimisation).
 */
final class NotificationTableDefinition implements TableDefinitionInterface
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {}

    public function key(): string
    {
        return 'notifications';
    }

    public function getColumns(): array
    {
        return [
            new Column('createdAt', 'table.notifications.columns.created_at', sortable: true, formatter: 'datetime', width: 150),
            new Column('type', 'table.notifications.columns.type', width: 170),
            new Column('title', 'table.notifications.columns.title'),
            new Column('isRead', 'table.notifications.columns.read', sortable: true, formatter: 'bool', width: 110),
            new Column('actions', 'table.notifications.columns.actions', formatter: 'open_link', width: 120),
        ];
    }

    public function getFilters(): array
    {
        $typeOptions = array_map(
            static fn (NotificationType $t): array => ['value' => $t->value, 'labelKey' => $t->label()],
            NotificationType::cases(),
        );

        return [
            new Filter(
                key: 'type',
                labelKey: 'table.notifications.columns.type',
                type: 'enum',
                field: 'type',
                options: $typeOptions,
                multiple: true,
                placeholderKey: 'table.notifications.filter.type_placeholder',
            ),
            new Filter(
                key: 'isRead',
                labelKey: 'table.notifications.columns.read',
                type: 'enum',
                field: 'isRead',
                options: [
                    ['value' => '0', 'labelKey' => 'table.notifications.filter.unread'],
                    ['value' => '1', 'labelKey' => 'table.notifications.filter.read'],
                ],
                multiple: true,
                placeholderKey: 'table.notifications.filter.read_placeholder',
            ),
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

        $type = NotificationType::tryFrom($row->getType());

        // PII-free: title carries only the case number; the message body is never
        // serialized (GDPR minimisation), consistent with the previous table.
        return [
            'id' => $row->getId(),
            'createdAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'type' => $type !== null ? $this->translator->trans($type->label()) : $row->getType(),
            'title' => $row->getTitle(),
            'isRead' => $row->isRead(),
            'link' => $row->getResourceLink() ?? '',
        ];
    }
}
