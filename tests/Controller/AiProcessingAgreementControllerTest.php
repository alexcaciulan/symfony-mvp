<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Form\AiProcessingAgreementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The agreement page is the only production path that moves a user out of
 * LOCAL_ONLY, so without it an AI-only account can never extract anything.
 */
class AiProcessingAgreementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('ai-agreement-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setExtractionMode(ExtractionMode::LOCAL_ONLY);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testAcceptingRecordsTheStampAndEnablesAiExtraction(): void
    {
        $crawler = $this->client->request('GET', '/profile/ai-agreement');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="ai_processing_agreement"]')->form();
        $form['ai_processing_agreement[accepted]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(ExtractionMode::BALANCED, $reloaded->getExtractionMode());
        self::assertNotNull($reloaded->getAiProcessingAgreementAt());
        self::assertSame(
            AiProcessingAgreementType::CURRENT_VERSION,
            $reloaded->getAiProcessingAgreementVersion(),
        );
    }

    public function testSubmittingWithoutTickingLeavesTheUserOnLocalOnly(): void
    {
        $crawler = $this->client->request('GET', '/profile/ai-agreement');
        $form = $crawler->filter('form[name="ai_processing_agreement"]')->form();
        $this->client->submit($form);

        // Symfony re-renders an invalid form with 422, so the page is returned
        // rather than the redirect the success path produces.
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(ExtractionMode::LOCAL_ONLY, $reloaded->getExtractionMode());
        self::assertNull($reloaded->getAiProcessingAgreementAt());
    }

    /**
     * The stamp is evidence that this account agreed to sub-processing, so it
     * must not be settable by a cross-site POST. Ticking the box without a
     * valid token has to leave the account exactly where it was.
     */
    public function testPostWithoutAValidCsrfTokenRecordsNothing(): void
    {
        $this->client->request('POST', '/profile/ai-agreement', [
            'ai_processing_agreement' => [
                'accepted' => '1',
                '_token' => 'forged-token',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertNull($reloaded->getAiProcessingAgreementAt());
        self::assertNull($reloaded->getAiProcessingAgreementVersion());
        self::assertSame(ExtractionMode::LOCAL_ONLY, $reloaded->getExtractionMode());
    }

    /**
     * A second visit after acceptance must not silently re-stamp the record:
     * the original date and version are what prove which wording was accepted.
     */
    public function testRevisitingThePageDoesNotOverwriteAnExistingAcceptance(): void
    {
        $acceptedAt = new \DateTimeImmutable('2026-01-15 08:00:00');
        $this->user->setAiProcessingAgreementAt($acceptedAt);
        $this->user->setAiProcessingAgreementVersion('v0-earlier');
        $this->em->flush();

        $this->client->request('GET', '/profile/ai-agreement');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(
            $acceptedAt->format('Y-m-d H:i:s'),
            $reloaded->getAiProcessingAgreementAt()->format('Y-m-d H:i:s'),
        );
        self::assertSame('v0-earlier', $reloaded->getAiProcessingAgreementVersion());
    }
    /**
     * An account already on MAX_ACCURACY must not be quietly downgraded as a
     * side effect of signing the text: the agreement is about who may process
     * the documents, not about how hard the extraction tries.
     */
    public function testAcceptingDoesNotDowngradeAnAccountAlreadyAboveBalanced(): void
    {
        $this->user->setExtractionMode(ExtractionMode::MAX_ACCURACY);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/profile/ai-agreement');
        $form = $crawler->filter('form[name="ai_processing_agreement"]')->form();
        $form['ai_processing_agreement[accepted]']->tick();
        $this->client->submit($form);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(ExtractionMode::MAX_ACCURACY, $reloaded->getExtractionMode());
        self::assertNotNull($reloaded->getAiProcessingAgreementAt());
    }

    /**
     * The agreement text promises the lawyer can go back to local extraction.
     * Withdrawal is what makes that promise true, and it has to clear the stamp
     * as well as the mode: a stamp left behind would keep reading as consent.
     */
    public function testWithdrawingReturnsTheAccountToLocalExtractionAndClearsTheStamp(): void
    {
        $this->user->setExtractionMode(ExtractionMode::BALANCED);
        $this->user->setAiProcessingAgreementAt(new \DateTimeImmutable());
        $this->user->setAiProcessingAgreementVersion(AiProcessingAgreementType::CURRENT_VERSION);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/profile/ai-agreement');
        $withdraw = $crawler->filter('form[action="/profile/ai-agreement/withdraw"]')->form();

        $this->client->submit($withdraw);
        self::assertResponseRedirects('/profile');

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(ExtractionMode::LOCAL_ONLY, $reloaded->getExtractionMode());
        self::assertFalse($reloaded->hasAcceptedAiProcessing());
        self::assertNull($reloaded->getAiProcessingAgreementVersion());
    }

    public function testWithdrawWithoutAValidCsrfTokenChangesNothing(): void
    {
        $this->user->setExtractionMode(ExtractionMode::BALANCED);
        $this->user->setAiProcessingAgreementAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->client->request('POST', '/profile/ai-agreement/withdraw', ['_token' => 'not-a-token']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertSame(ExtractionMode::BALANCED, $reloaded->getExtractionMode());
        self::assertTrue($reloaded->hasAcceptedAiProcessing());
    }

    /**
     * Declining is offered on the same screen as accepting. Without it the only
     * way out of the page is the browser back button, and the lawyer never
     * learns that manual entry remains a complete flow.
     */
    public function testThePageOffersDecliningAsWellAsAccepting(): void
    {
        $crawler = $this->client->request('GET', '/profile/ai-agreement');

        self::assertCount(1, $crawler->filter('form[action="/profile/ai-agreement/withdraw"]'));
    }
}
