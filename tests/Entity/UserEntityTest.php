<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use PHPUnit\Framework\TestCase;

class UserEntityTest extends TestCase
{
    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testGetRolesReturnsUniqueValues(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $roles = $user->getRoles();
        $this->assertSame(count($roles), count(array_unique($roles)));
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesWithEmptyRoles(): void
    {
        $user = new User();
        $user->setRoles([]);

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testGetFullNameWithBothNames(): void
    {
        $user = new User();
        $user->setFirstName('Ion');
        $user->setLastName('Popescu');

        $this->assertSame('Ion Popescu', $user->getFullName());
    }

    public function testGetFullNameWithOnlyFirstName(): void
    {
        $user = new User();
        $user->setFirstName('Ion');

        $this->assertSame('Ion', $user->getFullName());
    }

    public function testGetFullNameWithOnlyLastName(): void
    {
        $user = new User();
        $user->setLastName('Popescu');

        $this->assertSame('Popescu', $user->getFullName());
    }

    public function testGetFullNameReturnsNullWhenNoNames(): void
    {
        $user = new User();

        $this->assertNull($user->getFullName());
    }

    public function testIsDeletedReturnsTrueWhenDeletedAtSet(): void
    {
        $user = new User();
        $user->setDeletedAt(new \DateTimeImmutable());

        $this->assertTrue($user->isDeleted());
    }

    public function testIsDeletedReturnsFalseWhenDeletedAtNull(): void
    {
        $user = new User();

        $this->assertFalse($user->isDeleted());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->assertSame('test@example.com', $user->getUserIdentifier());
    }

    public function testToStringReturnsFullNameOrEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $this->assertSame('test@example.com', (string) $user);

        $user->setFirstName('Ion');
        $this->assertSame('Ion', (string) $user);

        $user->setLastName('Popescu');
        $this->assertSame('Ion Popescu', (string) $user);
    }

    public function testExtractionModeDefaultsToMaxAccuracy(): void
    {
        $user = new User();

        $this->assertSame(ExtractionMode::MAX_ACCURACY, $user->getExtractionMode());
    }

    public function testExtractionModeSetterAndGetter(): void
    {
        $user = new User();

        $user->setExtractionMode(ExtractionMode::BALANCED);
        $this->assertSame(ExtractionMode::BALANCED, $user->getExtractionMode());

        $user->setExtractionMode(ExtractionMode::MAX_ACCURACY);
        $this->assertSame(ExtractionMode::MAX_ACCURACY, $user->getExtractionMode());
    }

    /**
     * The PHP-level default has to match the column default, because that is
     * the whole mechanism by which new accounts get AI Vision: nothing calls
     * setExtractionPipeline() on the registration path.
     */
    public function testExtractionPipelineDefaultsToAiOnly(): void
    {
        $user = new User();

        $this->assertSame(ExtractionPipeline::AI_ONLY, $user->getExtractionPipeline());
    }

    public function testExtractionPipelineSetterAndGetter(): void
    {
        $user = new User();

        $user->setExtractionPipeline(ExtractionPipeline::LEGACY_CASCADE);
        $this->assertSame(ExtractionPipeline::LEGACY_CASCADE, $user->getExtractionPipeline());

        $user->setExtractionPipeline(ExtractionPipeline::AI_ONLY);
        $this->assertSame(ExtractionPipeline::AI_ONLY, $user->getExtractionPipeline());
    }

    public function testAiProcessingAgreementStartsUnrecorded(): void
    {
        $user = new User();

        $this->assertNull($user->getAiProcessingAgreementAt());
        $this->assertNull($user->getAiProcessingAgreementVersion());
        $this->assertFalse($user->hasAcceptedAiProcessing());
    }

    public function testAiProcessingAgreementSettersRecordTimeAndVersion(): void
    {
        $user = new User();
        $acceptedAt = new \DateTimeImmutable('2026-07-21 09:30:00');

        $user->setAiProcessingAgreementAt($acceptedAt);
        $user->setAiProcessingAgreementVersion('v1-draft');

        $this->assertSame($acceptedAt, $user->getAiProcessingAgreementAt());
        $this->assertSame('v1-draft', $user->getAiProcessingAgreementVersion());
        $this->assertTrue($user->hasAcceptedAiProcessing());
    }

    /**
     * The timestamp is what proves acceptance, so a version left behind by a
     * withdrawn agreement must not keep the flag true on its own.
     */
    public function testAiProcessingAgreementIsDrivenByTheTimestampNotTheVersion(): void
    {
        $user = new User();
        $user->setAiProcessingAgreementVersion('v1-draft');

        $this->assertFalse($user->hasAcceptedAiProcessing());
    }
}
