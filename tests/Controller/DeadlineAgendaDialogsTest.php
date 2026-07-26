<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\StampDutyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The dialogs of the global agenda, as they reach the browser.
 *
 * Three things are pinned here. A term whose miss cannot be undone carries its
 * confirmation with it, already translated, so the dialog can state the sanction
 * without a second request. The act that needs a communication date is collected on
 * this page instead of sending the lawyer into the case, while the link to the case
 * survives as the fallback for a browser running no scripts. And the design rules
 * the page was built on are still true after all of it: no checkbox, one badge per
 * row, dialogs outside the regions an action replaces.
 */
final class DeadlineAgendaDialogsTest extends WebTestCase
{
    private const TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $prefix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'agenda-dialogs-' . uniqid();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE a FROM audit_log a JOIN `user` u ON a.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement(
            'DELETE lc FROM legal_case lc JOIN `user` u ON lc.user_id = u.id WHERE u.email LIKE ?',
            [$this->prefix . '%'],
        );
        $conn->executeStatement('DELETE FROM `user` WHERE email LIKE ?', [$this->prefix . '%']);

        parent::tearDown();
    }

    /**
     * The stamp duty term is the case where the wording has to be checked against
     * the record: closing it on a file whose duty is not paid is a declaration, and
     * the dialog has to say so and print the state it is contradicting.
     */
    public function testClosingTheStampDutyTermCarriesTheDutyStateAndTheWarning(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $case->setStampDuty('200.00');
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $deadline = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');
        $form = $crawler->filter('#deadline-row-' . $deadline->getId() . ' form');

        self::assertResponseIsSuccessful();
        self::assertSame('submit->agenda-dialog#confirmClose', $form->attr('data-action'));

        $translator = static::getContainer()->get('translator');
        self::assertSame(
            $translator->trans('deadlines.confirm.stamp_duty.title'),
            $form->attr('data-agenda-dialog-title-param'),
        );
        self::assertSame(
            $translator->trans('deadlines.confirm.stamp_duty.warning_unpaid'),
            $form->attr('data-agenda-dialog-warning-param'),
        );

        $facts = json_decode((string) $form->attr('data-agenda-dialog-facts-param'), true);
        self::assertIsArray($facts);
        $values = array_column($facts, 'value');
        self::assertContains('200,00 RON', $values);
        self::assertContains($translator->trans('enum.stamp_duty_status.NEACHITATA'), $values);
    }

    /**
     * A paid file gets the same dialog without the warning: the sentence is about
     * the record, so it must not appear when the record does not support it.
     */
    public function testThePaidStampDutyKeepsTheDialogButDropsTheWarning(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $deadline = $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');
        $form = $crawler->filter('#deadline-row-' . $deadline->getId() . ' form');

        self::assertResponseIsSuccessful();
        self::assertNotNull($form->attr('data-agenda-dialog-title-param'));
        self::assertNull($form->attr('data-agenda-dialog-warning-param'));
    }

    /**
     * The limitation dialog says the period runs whatever the lawyer presses. That
     * sentence is the reason the button is worded "stop tracking" rather than
     * "done", so it has to be on screen next to it.
     */
    public function testStoppingTheTrackingOfALimitationTermSaysThePeriodRunsAnyway(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $deadline = $this->makeDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');
        $forms = $crawler->filter('#deadline-row-' . $deadline->getId() . ' form[data-action]');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $forms, 'Only the closing control asks for a confirmation.');

        $translator = static::getContainer()->get('translator');
        self::assertSame(
            $translator->trans('deadlines.confirm.limitation.body'),
            $forms->attr('data-agenda-dialog-body-param'),
        );
        self::assertSame(
            $translator->trans('deadlines.action.stop_tracking'),
            $forms->attr('data-agenda-dialog-confirm-param'),
            'The dialog names the same act as the button that opened it.',
        );
    }

    /** A reminder with no sanction is closed straight away: no dialog stands in front of it. */
    public function testAReversibleTermIsClosedWithoutADialog(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+4 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');
        $form = $crawler->filter('#deadline-row-' . $deadline->getId() . ' form');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $form);
        self::assertNull($form->attr('data-action'));
    }

    /**
     * The date is collected here. The href stays pointed at the case so a browser
     * running no scripts still reaches the dialog, only through a navigation.
     */
    public function testTheCommunicationDateButtonOpensTheDialogAndKeepsTheCaseLinkAsFallback(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $deadline = $this->makeDeadline($case, '+5 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');
        $link = $crawler->filter('#deadline-row-' . $deadline->getId() . ' a[data-action]');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $link);
        self::assertSame('agenda-dialog#openDialog', $link->attr('data-action'));
        self::assertSame('hs-modal-set-summons-communication-date', $link->attr('data-agenda-dialog-dialog-param'));
        self::assertSame(
            '/case/' . $case->getId() . '/summons-communication-date',
            $link->attr('data-agenda-dialog-action-param'),
        );
        self::assertSame('/case/' . $case->getId(), $link->attr('href'));

        $dialog = $crawler->filter('#hs-modal-set-summons-communication-date');
        self::assertCount(1, $dialog);
        self::assertCount(1, $dialog->filter('input[name="payment_notice_communication_date[paymentNoticeCommunicationDate]"]'));
        self::assertSame('agenda', $dialog->filter('input[name="_context"]')->attr('value'));
    }

    /**
     * The dialog posted from the agenda answers with the agenda and dismisses
     * itself, which is the only reason it can stay on a page that replaces its own
     * regions.
     */
    public function testRecordingTheCommunicationDateFromTheAgendaDismissesTheDialog(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/case/' . $case->getId() . '/summons-communication-date',
            [
                'payment_notice_communication_date' => [
                    'paymentNoticeCommunicationDate' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
                    'paymentNoticeCommunicationMethod' => 'POSTA_RCD',
                    '_token' => $this->token('payment_notice_communication_date'),
                ],
                '_context' => 'agenda',
            ],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="deadline-agenda"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="hs-modal-set-summons-communication-date"', $body);

        $reloaded = $this->em->getRepository(LegalCase::class)->find($case->getId());
        self::assertNotNull($reloaded->getPaymentNoticeCommunicationDate());
    }

    /** The header offers the act, and the dialog behind it only lists the lawyer's own cases. */
    public function testTheHeaderOffersAddingADeadlineOnOneOfTheOwnCases(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $other = $this->makeUser('-other');
        $foreignCase = $this->makeCase($other);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-agenda-action="add-deadline"]'));

        $options = $crawler->filter('#agenda_add_deadline_case option[value]')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );

        self::assertContains((string) $case->getId(), $options);
        self::assertNotContains((string) $foreignCase->getId(), $options);
    }

    /**
     * An account with no case gets no dialog and no button: a select with nothing in
     * it promises something the account cannot do yet.
     */
    public function testAnAccountWithoutCasesIsNotOfferedTheAddDialog(): void
    {
        $user = $this->makeUser();
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#hs-modal-agenda-add-deadline'));
        self::assertCount(0, $crawler->filter('[data-agenda-action="add-deadline"]'));
    }

    /** Submitting the header dialog creates the reminder and dismisses the dialog. */
    public function testAddingFromTheHeaderDialogAnswersWithTheAgendaAndDismissesIt(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/termene/termen-nou',
            [
                'agenda_add_deadline' => [
                    'legalCase' => (string) $case->getId(),
                    'deadlineDate' => (new \DateTimeImmutable('+6 days'))->format('Y-m-d'),
                    'description' => 'Depune completare',
                    '_token' => $this->token('agenda_add_deadline'),
                ],
                '_context' => 'agenda',
            ],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="deadline-agenda"', $body);
        self::assertStringContainsString('data-close-modal-target-id-value="hs-modal-agenda-add-deadline"', $body);
        self::assertCount(1, $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $case]));
    }

    /**
     * A rejected submission keeps its dialog open, because dismissing it would throw
     * away what the lawyer typed while telling them to correct it.
     */
    public function testARejectedAdditionKeepsTheDialogOpen(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request(
            'POST',
            '/termene/termen-nou',
            [
                'agenda_add_deadline' => [
                    'legalCase' => '',
                    'deadlineDate' => (new \DateTimeImmutable('+6 days'))->format('Y-m-d'),
                    '_token' => $this->token('agenda_add_deadline'),
                ],
                '_context' => 'agenda',
            ],
            [],
            ['HTTP_ACCEPT' => self::TURBO_ACCEPT],
        );

        $body = (string) $this->client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-close-modal-target-id-value', $body);
    }

    /**
     * The dialogs must not sit inside what an action replaces, or the answer to a
     * submission would destroy the dialog that produced it while the toast is still
     * arriving.
     */
    public function testTheDialogsLiveOutsideTheRegionsAnActionReplaces(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#hs-modal-agenda-confirm-close'));
        self::assertCount(0, $crawler->filter('#deadline-agenda #hs-modal-agenda-confirm-close'));
        self::assertCount(0, $crawler->filter('#deadline-riskbar #hs-modal-agenda-confirm-close'));
    }

    /**
     * The rule the whole screen was designed around: closing a deadline is a
     * labelled button, never a tick. A dialog added on top must not smuggle one in.
     */
    public function testTheDialogsIntroduceNoCheckbox(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->makeDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->makeDeadline($case, '+9 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/termene');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="checkbox"]'));
    }

    private function token(string $tokenId): string
    {
        $this->client->request('GET', '/termene');
        $request = $this->client->getRequest();

        $stack = static::getContainer()->get(RequestStack::class);
        $stack->push($request);

        try {
            $token = static::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }

        $request->getSession()->save();

        return $token;
    }

    private function makeUser(string $suffix = ''): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->prefix . $suffix . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeCase(User $user, CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus($status);
        $case->setAmount('1000.00');
        $this->em->persist($case);

        return $case;
    }

    private function makeDeadline(LegalCase $case, string $date, DeadlineType $type): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted(false);
        $this->em->persist($deadline);

        return $deadline;
    }
}
