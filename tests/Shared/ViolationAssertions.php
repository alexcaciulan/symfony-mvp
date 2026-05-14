<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Shared assertion helpers pentru testele DTO care verifică
 * Symfony Validator violations cu property path specific.
 * Folosit de Step1CreditorDataTest + Step2DebtorEntryTest.
 */
trait ViolationAssertions
{
    private static function assertViolation(
        ConstraintViolationListInterface $violations,
        string $expectedPath,
        string $expectedTemplate,
    ): void {
        foreach ($violations as $v) {
            if ($v->getPropertyPath() === $expectedPath && $v->getMessageTemplate() === $expectedTemplate) {
                self::assertTrue(true);

                return;
            }
        }

        $debug = [];
        foreach ($violations as $v) {
            $debug[] = sprintf('%s: %s', $v->getPropertyPath(), $v->getMessageTemplate());
        }

        self::fail(sprintf(
            'Expected violation "%s" on property "%s" but got: %s',
            $expectedTemplate,
            $expectedPath,
            $debug ? implode(' | ', $debug) : '(no violations)',
        ));
    }

    private static function assertNoViolation(
        ConstraintViolationListInterface $violations,
        string $forbiddenPath,
    ): void {
        foreach ($violations as $v) {
            if ($v->getPropertyPath() === $forbiddenPath) {
                self::fail(sprintf(
                    'Unexpected violation on property "%s": %s',
                    $forbiddenPath,
                    $v->getMessageTemplate(),
                ));
            }
        }

        self::assertTrue(true);
    }
}
