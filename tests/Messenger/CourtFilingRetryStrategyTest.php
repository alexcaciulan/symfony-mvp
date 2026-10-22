<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\SendFilingEmailPartMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/** The retry schedule decided for court emails: 5 min, 30 min, 2 h, then no more. */
final class CourtFilingRetryStrategyTest extends KernelTestCase
{
    public function testScheduleIsFiveMinutesHalfAnHourTwoHoursThenStops(): void
    {
        self::bootKernel();
        /** @var RetryStrategyInterface $strategy */
        $strategy = static::getContainer()->get('messenger.retry_strategy_locator')->get('court_filing');
        $failure = new \RuntimeException('451');

        $waits = [];
        foreach ([0, 1, 2] as $retryCount) {
            $envelope = $this->envelope($retryCount);
            self::assertTrue($strategy->isRetryable($envelope, $failure));
            $waits[] = $strategy->getWaitingTime($envelope, $failure);
        }

        self::assertSame([300000, 1800000, 7200000], $waits);
        self::assertFalse($strategy->isRetryable($this->envelope(3), $failure));
    }

    private function envelope(int $retryCount): Envelope
    {
        $envelope = new Envelope(new SendFilingEmailPartMessage(1));

        return $retryCount === 0 ? $envelope : $envelope->with(new RedeliveryStamp($retryCount));
    }
}
