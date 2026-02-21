<?php

namespace App\Tests\RateLimiter;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Tests that rate limiter factories are properly configured and injectable.
 * Actual rate limiting behavior is tested via the no_limit override in test env
 * to avoid interference with other tests. These tests verify configuration only.
 */
class RateLimiterTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testRegistrationLimiterIsConfigured(): void
    {
        $factory = static::getContainer()->get('limiter.registration');
        $this->assertInstanceOf(RateLimiterFactory::class, $factory);
    }

    public function testForgotPasswordLimiterIsConfigured(): void
    {
        $factory = static::getContainer()->get('limiter.forgot_password');
        $this->assertInstanceOf(RateLimiterFactory::class, $factory);
    }

    public function testCaseCreationLimiterIsConfigured(): void
    {
        $factory = static::getContainer()->get('limiter.case_creation');
        $this->assertInstanceOf(RateLimiterFactory::class, $factory);
    }

    public function testDocumentUploadLimiterIsConfigured(): void
    {
        $factory = static::getContainer()->get('limiter.document_upload');
        $this->assertInstanceOf(RateLimiterFactory::class, $factory);
    }
}
