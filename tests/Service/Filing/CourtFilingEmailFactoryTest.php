<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\StampDutyStatus;
use App\Service\Filing\CourtFilingEmailException;
use App\Service\Filing\CourtFilingEmailFactory;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class CourtFilingEmailFactoryTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private FilingSubmission $submission;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'filing-email');
        $this->courtTransport()->reset();
        $this->case = $this->createSendableFilingCase($this->em, $this->user, 2);
        $this->submission = $this->submitFiling($this->case, $this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testFirstPartCarriesTheDecidedHeaders(): void
    {
        $part = $this->submission->getPart(1);
        $built = $this->factory()->build($part, 'instanta-simulata@example.test', new \DateTimeImmutable('2026-10-22 10:00:00'));
        $raw = $built->bytes;

        self::assertStringContainsString('From: "Av. Ana Avocat prin LexRecovery" <depuneri@lexrecovery.test>', $raw);
        self::assertStringContainsString('To: instanta-simulata@example.test', $raw);
        self::assertStringContainsString('Cc: ' . $this->user->getEmail(), $raw);
        self::assertStringContainsString('Reply-To: ' . $this->user->getEmail(), $raw);
        self::assertStringContainsString('Message-ID: ' . $part->getMessageId(), $raw);
        self::assertStringContainsString('Disposition-Notification-To: depuneri@lexrecovery.test', $raw);
        self::assertStringContainsString('X-LexRecovery-Ref: ' . $this->submission->getToken(), $raw);
        self::assertStringContainsString('Date: Thu, 22 Oct 2026 10:00:00', $raw);
        self::assertStringNotContainsString('In-Reply-To', $raw);
        self::assertStringContainsString('filename=01-cerere-si-opis.pdf', $raw);
        self::assertStringContainsString('filename=02-imputernicire.pdf', $raw);

        self::assertSame(hash('sha256', $raw), $built->sha256);
        self::assertSame(strlen($raw), $built->encodedBytes);
        self::assertSame('bounces+' . $part->getVerpToken() . '@lexrecovery.test', $built->envelope->getSender()->getAddress());
        self::assertSame(['instanta-simulata@example.test', $this->user->getEmail()], array_map(static fn ($a): string => $a->getAddress(), $built->envelope->getRecipients()));
    }

    public function testLaterPartsThreadUnderTheFirst(): void
    {
        $built = $this->factory()->build($this->submission->getPart(2), 'instanta-simulata@example.test', new \DateTimeImmutable());

        self::assertStringContainsString('In-Reply-To: ' . $this->submission->getPart(1)?->getMessageId(), $built->bytes);
        self::assertStringContainsString('References: ' . $this->submission->getPart(1)?->getMessageId(), $built->bytes);
        self::assertStringContainsString('filename=03-anexe.pdf', $built->bytes);
    }

    public function testSubjectNamesTheCreditorAndTheDebtorAndThePart(): void
    {
        self::assertSame(
            'Cerere de emitere a ordonanței de plată: SC Creditor Pachet SRL contra SC Debitor Pachet SRL (partea 1 din 2)',
            $this->submission->getPart(1)?->getSubject(),
        );
    }

    public function testSubjectWithLongPartyNamesFitsTheColumnAndKeepsThePartCounter(): void
    {
        $this->case->getCreditor()?->setName('SC ' . str_repeat('Creditor Cu Denumire Foarte Lungă ', 6) . 'SRL');
        $this->case->getDebtors()->first()->getDebtor()->setName('SC ' . str_repeat('Debitor Cu Denumire Foarte Lungă ', 6) . 'SRL');

        $subject = $this->factory()->subject($this->case, 2, 2);

        self::assertLessThanOrEqual(240, mb_strlen($subject));
        self::assertStringStartsWith('Cerere de emitere a ordonanței de plată: SC Creditor Cu Denumire', $subject);
        self::assertStringEndsWith('… (partea 2 din 2)', $subject);
        self::assertLessThanOrEqual(240, mb_strlen($this->factory()->subject($this->case, 1, 1)));
    }

    public function testBodyIsRomanianWhateverTheLawyerLocale(): void
    {
        static::getContainer()->get(LocaleSwitcher::class)->setLocale('en');

        $body = $this->factory()->body($this->case, $this->submission->getPackage(), 1);

        self::assertStringStartsWith('Către ' . $this->case->getCourt()?->getName() . ',', $body);
        self::assertStringContainsString('Subscrisa SC Creditor Pachet SRL, cu sediul în Str. Pachet 1, București, CUI RO99993333, prin avocat Ana Avocat', $body);
        self::assertStringContainsString('Împuternicire avocațială seria B nr. 1234 din 15.01.2026', $body);
        self::assertStringContainsString('Anexele urmează în mesajele următoare', $body);
        self::assertStringContainsString('Mesaj transmis prin platforma LexRecovery în numele avocatului.', $body);
        self::assertStringContainsString('Actele sunt semnate electronic de avocat; acest e-mail asigură doar transmiterea.', $body);
        self::assertStringContainsString('comunicați numărul dosarului la adresa avocatului, ' . $this->user->getEmail(), $body);
        // Never the debtor's address or personal data, never an amount or a filing claim.
        self::assertStringNotContainsString('Str. Pachet 2', $body);
        self::assertStringNotContainsString('RON', $body);
        self::assertStringNotContainsString('depusă', $body);
        self::assertStringNotContainsString('—', $body);
    }

    public function testStampDutySentenceFollowsThePackage(): void
    {
        $package = $this->submission->getPackage();
        self::assertTrue($package->includesStampDutyProof());
        self::assertStringContainsString('Dovada achitării taxei judiciare de timbru este inclusă în anexe.', $this->factory()->body($this->case, $package, 1));

        $package->setIncludesStampDutyProof(false);
        self::assertStringContainsString('plată prin registratura electronică a instanțelor', $this->factory()->body($this->case, $package, 1));

        $this->case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        self::assertStringContainsString('Taxa judiciară de timbru se va achita la indicarea instanței.', $this->factory()->body($this->case, $package, 1));

        // Only the first email speaks of the duty.
        self::assertStringNotContainsString('taxei judiciare', $this->factory()->body($this->case, $package, 2));
    }

    public function testMissingPowerOfAttorneyDetailsAreNeverLeftBlank(): void
    {
        foreach ($this->case->getDocuments() as $document) {
            if ($document->getDocumentType() === DocumentType::IMPUTERNICIRE_AVOCATIALA) {
                $document->setPowerOfAttorneyNumber(null);
            }
        }

        $this->expectException(CourtFilingEmailException::class);
        $this->factory()->body($this->case, $this->submission->getPackage(), 1);
    }

    public function testNoCopyToTheLawyerMeansNoEmail(): void
    {
        $this->user->setEmail('');

        $this->expectException(CourtFilingEmailException::class);
        $this->factory()->build($this->submission->getPart(1), 'instanta-simulata@example.test', new \DateTimeImmutable());
    }

    public function testSignedFileChangedOnDiskIsNotAttached(): void
    {
        $annex = $this->submission->getPart(2)?->getPackageParts()->first();
        file_put_contents($this->filingUploadsDir . '/' . $annex->getSignedPath(), 'tampered');

        $this->expectException(CourtFilingEmailException::class);
        $this->factory()->build($this->submission->getPart(2), 'instanta-simulata@example.test', new \DateTimeImmutable());
    }

    private function factory(): CourtFilingEmailFactory
    {
        return static::getContainer()->get(CourtFilingEmailFactory::class);
    }
}
