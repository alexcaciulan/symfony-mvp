<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPartRole;
use App\Enum\FilingPartStatus;
use App\Service\Filing\FilingPackageBlockedException;
use App\Service\Filing\FilingResplitService;
use App\Tests\Support\FilingCaseFixtureTrait;
use App\Tests\Support\FilingSubmissionTestTrait;
use App\Tests\Support\RecordingCourtTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** When splitting the annexes again can help after a size refusal, and when it cannot. */
final class FilingResplitServiceTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;
    use FilingSubmissionTestTrait;

    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('app.uploads_dir');
        $this->user = $this->createFilingUser($this->em, 'filing-resplit');
        $this->courtTransport()->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        }
        parent::tearDown();
    }

    public function testRefusedFirstEmailCarryingAnnexesCanBeSplitAgain(): void
    {
        $submission = $this->refusedForSize(1, 1, 4_000_000);

        self::assertTrue($this->service()->isResplittable($submission));
        self::assertSame(2_000_000, $this->service()->newBudget($submission->getPart(1) ?? throw new \LogicException()));
    }

    public function testBudgetBelowOneMegabyteIsRefused(): void
    {
        $submission = $this->refusedForSize(1, 1, 1_500_000);

        self::assertFalse($this->service()->isResplittable($submission));
    }

    public function testFirstEmailWithoutAnnexesCannotBeHelped(): void
    {
        $submission = $this->refusedForSize(1, 2, 8_000_000);

        self::assertFalse($submission->getPart(1)?->carries(FilingPartRole::ANNEX_BUNDLE));
        self::assertFalse($this->service()->isResplittable($submission));
    }

    public function testAnnexRefusedAfterThePetitionIsNotSplitAgain(): void
    {
        $submission = $this->refusedForSize(2, 2, 8_000_000);

        self::assertTrue($submission->isPetitionAccepted());
        self::assertFalse($this->service()->isResplittable($submission));
        $this->expectException(FilingPackageBlockedException::class);
        $this->service()->resplitAfterSizeFailure($submission, $this->user);
    }

    private function refusedForSize(int $sequence, int $emails, int $encodedBytes): FilingSubmission
    {
        $this->case = $this->createSendableFilingCase($this->em, $this->user, $emails);
        $submission = $this->submitFiling($this->case, $this->user);
        for ($i = 1; $i < $sequence; ++$i) {
            $this->handleNextFilingMessage();
        }
        $this->courtTransport()->failNext = RecordingCourtTransport::endOfDataRefused(552, 'Message size exceeds fixed limit');
        try {
            $this->handleNextFilingMessage();
        } catch (HandlerFailedException) {
        }
        $this->em->refresh($submission);
        $part = $submission->getPart($sequence) ?? throw new \LogicException();
        self::assertSame(FilingPartStatus::FAILED_SIZE, $part->getStatus());
        // The fixture files are small; the size the server refused is what sets the new budget.
        $part->recordEncodedBytes($encodedBytes);
        $this->em->flush();

        return $submission;
    }

    private function service(): FilingResplitService
    {
        return static::getContainer()->get(FilingResplitService::class);
    }
}
