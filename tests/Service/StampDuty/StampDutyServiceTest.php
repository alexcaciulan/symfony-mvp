<?php

declare(strict_types=1);

namespace App\Tests\Service\StampDuty;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\StampDutyStatus;
use App\Repository\CityRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineService;
use App\Service\Deadline\WorkingDayResolver;
use App\Service\Document\DocumentUploadService;
use App\Service\StampDuty\StampDutyService;
use App\Service\StampDuty\StampDutyUatResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The proof upload commits on its own flush, outside the transaction that records the
 * payment. If the payment state then fails, a proof left behind would sit on a case
 * still reading NEACHITATA, and the re-upload guard (which keys off the status) would
 * accept a second one.
 */
final class StampDutyServiceTest extends TestCase
{
    public function testAFailedPaymentDoesNotLeaveTheProofBehind(): void
    {
        $case = new LegalCase();
        $document = new Document();

        $uploads = $this->createMock(DocumentUploadService::class);
        $uploads->method('upload')->willReturn($document);
        $uploads->expects(self::once())
            ->method('delete')
            ->with($document);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException(new \RuntimeException('flush failed'));

        // DeadlineService and StampDutyUatResolver are final: build them for real over
        // stubbed collaborators rather than loosening the classes just to be doubled.
        $deadlines = new DeadlineService(
            $em,
            new WorkingDayResolver(),
            $this->createStub(AuditLogService::class),
            $this->createStub(LegalDeadlineRepository::class),
            $this->createStub(TranslatorInterface::class),
        );

        $service = new StampDutyService(
            $em,
            $uploads,
            $deadlines,
            $this->createStub(AuditLogService::class),
            new StampDutyUatResolver($this->createStub(CityRepository::class)),
        );

        try {
            $service->recordPayment(
                case: $case,
                file: $this->createStub(UploadedFile::class),
                user: $this->createStub(UserInterface::class),
                paidAt: new \DateTimeImmutable('2026-07-10'),
                paidAmount: '200.00',
                payerName: 'SC Creditor SRL',
                paymentReference: 'OP 1',
                lawVersion: 'OUG 80/2013 art. 6 alin. 2',
            );
            self::fail('The failure must surface to the caller, not be swallowed.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(
            StampDutyStatus::NEACHITATA,
            $case->getStampDutyStatus(),
            'A case whose payment failed must not read as paid.',
        );
    }
}
