<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\DeadlineBlockageReason;
use App\Service\Deadline\DeadlineBlockage;
use App\Service\Deadline\DeadlineBlockageActionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single button of a blockage row. A blocked case is one the agenda has nothing
 * to show a date for, so the row is the only place it appears at all: a button pointing
 * at the wrong route leaves the case reachable in theory and stranded in practice.
 */
final class DeadlineBlockageActionResolverTest extends TestCase
{
    private DeadlineBlockageActionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeadlineBlockageActionResolver();
    }

    /**
     * The whole mapping, one line per reason. The third column is the dialog the agenda
     * carries itself: only the summons date has one, every other reason sends the lawyer
     * into the case, where the dialog that collects the fact lives.
     *
     * @return iterable<string, array{DeadlineBlockageReason, string, ?string}>
     */
    public static function reasonProvider(): iterable
    {
        yield 'summons communication' => [
            DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING,
            'case_deadline_summons_communication_date',
            'hs-modal-set-summons-communication-date',
        ];
        // The proof is a file, which no agenda dialog collects, so this one is a plain
        // link into the case even though its sibling above opens a dialog in place.
        yield 'summons proof' => [
            DeadlineBlockageReason::SUMMONS_PROOF_MISSING,
            'case_document_upload',
            null,
        ];
        yield 'ruling communication' => [
            DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
            'case_deadline_ruling_date',
            null,
        ];
        yield 'annulment ruling communication' => [
            DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING,
            'case_deadline_annulment_ruling_date',
            null,
        ];
        yield 'stamping notice' => [
            DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING,
            'case_stamp_duty_court_notice',
            null,
        ];
        // The enforcement anchor of a case that never went through an annulment request
        // is the communication of the order itself, recorded through the same route.
        yield 'enforcement anchor' => [
            DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING,
            'case_deadline_ruling_date',
            null,
        ];
        yield 'bailiff registration number' => [
            DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING,
            'case_deadline_enforcement_registration_number',
            null,
        ];
    }

    #[DataProvider('reasonProvider')]
    public function testEachReasonLeadsToTheRouteThatRecordsIt(DeadlineBlockageReason $reason, string $expectedRoute, ?string $expectedDialogId): void
    {
        $button = $this->resolver->resolve(new DeadlineBlockage(new LegalCase(), $reason));

        self::assertSame($expectedRoute, $button->route);
        self::assertSame($reason->actionLabel(), $button->label);
        self::assertSame('POST', $button->method);
        self::assertSame($expectedDialogId, $button->agendaDialogId);
    }

    /**
     * None of these routes takes a bare token: each of them wants a date, or a number,
     * that the agenda row does not collect. So every button is built without a CSRF token
     * id, which is what makes the shared template render a link into the case instead of
     * a form the route would answer 400 to.
     *
     * The bailiff registration number is the reason this is asserted for all of them
     * rather than for the older five: it was the first one added after the agenda grew a
     * dialog of its own, and giving it a token id would have produced a form posting an
     * empty payload to a route that validates a form type.
     */
    public function testNoBlockageButtonPostsFromTheAgendaItself(): void
    {
        foreach (DeadlineBlockageReason::cases() as $reason) {
            $button = $this->resolver->resolve(new DeadlineBlockage(new LegalCase(), $reason));

            self::assertNull($button->csrfTokenId, $reason->value);
            self::assertTrue($button->needsCaseDialog(), $reason->value);
            self::assertFalse($button->closesDeadline, $reason->value . ': recording a missing fact is not closing a term.');
        }
    }

    /** A reason added to the enum without a line above would silently go untested. */
    public function testEveryReasonIsPinnedByTheMappingAbove(): void
    {
        $covered = array_map(
            static fn (array $row): DeadlineBlockageReason => $row[0],
            iterator_to_array(self::reasonProvider()),
        );

        self::assertEqualsCanonicalizing(DeadlineBlockageReason::cases(), array_values($covered));
    }
}
