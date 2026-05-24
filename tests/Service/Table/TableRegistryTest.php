<?php

declare(strict_types=1);

namespace App\Tests\Service\Table;

use App\Service\Table\Definition\NotificationTableDefinition;
use App\Service\Table\TableRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TableRegistryTest extends KernelTestCase
{
    public function testResolvesTaggedDefinitionByKey(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(TableRegistry::class);

        self::assertTrue($registry->has('notifications'));
        self::assertInstanceOf(NotificationTableDefinition::class, $registry->get('notifications'));
    }

    public function testThrowsOnUnknownKey(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(TableRegistry::class);

        $this->expectException(\InvalidArgumentException::class);
        $registry->get('does_not_exist');
    }
}
