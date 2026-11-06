<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\CaseStatus;
use App\Enum\DeadlineBlockageReason;
use App\Enum\DeadlineType;
use App\Tests\Support\AgendaPageFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The side panel, where everything left out of a row went. It reads the same voter as the
 * case, its forms are the very buttons of the row, and what a row refuses to offer the
 * panel must refuse too: a close offered one level deeper would undo every rule the row
 * test pins.
 */
final class DeadlinePanelTest extends WebTestCase
{
    use AgendaPageFixtureTrait;

    private const FRAME = ['HTTP_TURBO_FRAME' => 'deadline-panel-frame'];

    private const DEBTOR = 'Debitor Confidential SRL';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->prefix = 'deadline-panel-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeAgendaFixtures();

        parent::tearDown();
    }

    // ===== who can read it =================================================

    public function testAnAnonymousVisitorIsSentToLogin(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->panel($deadline);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAForeignTermIsRefusedAndNamesNoDebtor(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $foreign = $this->makeCase($this->makeUser('-other'));
        $this->attachDebtor($foreign, self::DEBTOR);
        $deadline = $this->makeDeadline($foreign, '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->panel($deadline);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(self::DEBTOR, (string) $this->client->getResponse()->getContent());
    }

    public function testATermOfADeletedCaseIsGone(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $case->setDeletedAt(new \DateTimeImmutable());
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->panel($deadline);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingTermIsGone(): void
    {
        $user = $this->makeUser();
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/termene/999999999/detalii', [], [], self::FRAME);

        self::assertResponseStatusCodeSame(404);
    }

    /** A plain visit has no frame to fill, so the link leads back to the page it came from. */
    public function testAPlainVisitLandsOnTheAgendaKeepingTheSelection(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request('GET', '/termene/' . $deadline->getId() . '/detalii');
        self::assertResponseRedirects('/termene');

        $this->client->request('GET', '/termene/' . $deadline->getId() . '/detalii?fatal=1');
        self::assertResponseRedirects('/termene?fatal=1');
    }

    /** The redirect happens before the record is read, so it reveals nothing about it. */
    public function testAPlainVisitToAForeignTermRevealsNothing(): void
    {
        $user = $this->makeUser();
        $foreign = $this->makeDeadline($this->makeCase($this->makeUser('-other')), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/termene/' . $foreign->getId() . '/detalii');

        self::assertResponseRedirects('/termene');
    }

    // ===== what it says ====================================================

    public function testThePanelStatesConsequenceComputationAndBasis(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $this->attachDebtor($case, self::DEBTOR);
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::DEBTOR, $text);
        self::assertStringContainsString($this->trans('deadlines.panel_type.TIMBRARE.consequence'), $text);
        self::assertStringContainsString($this->trans('deadlines.panel_type.TIMBRARE.computation'), $text);
        self::assertStringContainsString($this->trans('deadlines.panel_type.TIMBRARE.basis'), $text);
        self::assertStringContainsString($this->trans('deadlines.panel.consequence'), $text);
    }

    /** A term with no sanction has a meaning, not a consequence, and no article to quote. */
    public function testAReminderHasAMeaningAndNoBasis(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringContainsString($this->trans('deadlines.panel.meaning'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.panel.consequence'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.panel.basis'), $text);
    }

    public function testThePanelPrintsTheLadderOfAlerts(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringContainsString($this->trans('deadlines.panel.alert_after_expiry'), $text);
        self::assertStringContainsString($this->trans('deadlines.panel.alert_tier', 7), $text);
        self::assertStringNotContainsString($this->trans('case_overview.deadlines.alerts_muted'), $text);
    }

    /** A term whose alerts the job will not send must not promise a ladder. */
    public function testAMutedTermSaysSoInsteadOfPromisingAlerts(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('-3 days'));
        $deadline = $this->makeDeadline($case, '+400 days', DeadlineType::PRESCRIPTIE_EXECUTARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringContainsString($this->trans('case_overview.deadlines.alerts_muted'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.panel.alert_after_expiry'), $text);
    }

    public function testAClosedTermPromisesNoAlertsAndStatesItsClosing(): void
    {
        $user = $this->makeUser();
        $deadline = $this->closedDeadline($this->makeCase($user), $user);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringNotContainsString($this->trans('deadlines.panel.alerts'), $text);
        self::assertStringContainsString($this->trans('deadlines.panel.completed_on'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.panel.closed_by_app'), $text);
    }

    /** A term the application closed itself carries no user, and the panel says who did it. */
    public function testATermClosedByTheApplicationSaysSo(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '-5 days', DeadlineType::OTHER);
        $deadline->markCompleted(null);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertStringContainsString($this->trans('deadlines.panel.closed_by_app'), $this->panelBody($deadline)->text());
    }

    /** The sentence behind a marker that the row only has room to abbreviate. */
    public function testAnUnconfirmedDateStatesItsNote(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $deadline = $this->makeDeadline($case, '+40 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $body = $this->panelBody($deadline);

        $parameters = ['%date%' => $this->holdsUntil('-3 days')];
        self::assertStringContainsString(
            $this->trans('deadlines.row.estimate.prescription_interrupted.mark', null, $parameters),
            $body->text(),
        );
        self::assertStringContainsString(
            $this->trans('deadlines.row.estimate.prescription_interrupted.note', null, $parameters),
            $body->text(),
        );
    }

    /** Past the six months with nothing filed the panel must stop reading as a protection. */
    public function testALapsedInterruptionIsStatedAsLapsed(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-300 days'));
        $deadline = $this->makeDeadline($case, '+40 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringContainsString($this->trans('deadlines.row.estimate.prescription_interruption_lapsed.note'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.row.estimate.prescription_interrupted.mark', null, ['%date%' => $this->holdsUntil('-300 days')]), $text);
    }

    /** The sentences of the header point at a band this page does not have. */
    public function testTheNextStepSentenceDoesNotReferToABandOfTheCasePage(): void
    {
        $user = $this->makeUser();
        $awaitingDate = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $dateTerm = $this->makeDeadline($awaitingDate, '+20 days', DeadlineType::PRESCRIPTIE);

        $awaitingProof = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $awaitingProof->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $proofTerm = $this->makeDeadline($awaitingProof, '+20 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);

        $date = $this->panelBody($dateTerm)->text();
        self::assertStringContainsString($this->trans('deadlines.next_step.sentence.summons_blocked'), $date);
        self::assertStringNotContainsString('din banda de mai sus', $date);

        $proof = $this->panelBody($proofTerm)->text();
        self::assertStringContainsString($this->trans('deadlines.next_step.sentence.proof_blocked'), $proof);
        self::assertStringNotContainsString('din banda de mai sus', $proof);
    }

    /** While the row is silent, the panel still states what comes after the fifteen days. */
    public function testThePanelStatesTheNextStepWhileTheDaysRun(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->attachProof($case);
        $deadline = $this->makeDeadline($case, '+13 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertStringContainsString(
            $this->trans('case_overview.hero.next_step.SOMATIE_TRIMISA'),
            $this->panelBody($deadline)->text(),
        );
    }

    /** Only before the summons does the panel add why the summons matters here. */
    public function testTheInterruptionSentenceOnlyShowsBeforeTheSummons(): void
    {
        $user = $this->makeUser();
        $amicable = $this->makeDeadline($this->makeCase($user), '+20 days', DeadlineType::PRESCRIPTIE);
        $spent = $this->makeDeadline($this->makeCase($user), '-5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $sentence = $this->trans('deadlines.next_step.sentence.summons_interrupts');

        self::assertStringContainsString($sentence, $this->panelBody($amicable)->text());
        self::assertStringNotContainsString($sentence, $this->panelBody($spent)->text(), 'A run-out term can no longer be interrupted.');
    }

    /** A closed limitation term has no list of due dates, so it has to name its own. */
    public function testAClosedLimitationTermNamesTheDueDateItCovered(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '-5 days', DeadlineType::PRESCRIPTIE, completed: true);
        $deadline->setDescription('Scadența facturii 333');
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertStringContainsString('Scadența facturii 333', $this->panelBody($deadline)->text());
    }

    public function testAMergedRowListsEveryDueDateItStandsFor(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user);
        $row = $this->makeDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $row->setDescription('Factura 111');
        $later = $this->makeDeadline($case, '+300 days', DeadlineType::PRESCRIPTIE);
        $later->setDescription('Factura 222');
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($row)->text();

        self::assertStringContainsString($this->trans('deadlines.panel.covered_terms', 2), $text);
        self::assertStringContainsString('Factura 111', $text);
        self::assertStringContainsString('Factura 222', $text);
    }

    public function testASingleTermStillNamesTheDueDateItCovers(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertStringContainsString($this->trans('deadlines.panel.covered_terms', 1), $this->panelBody($deadline)->text());
    }

    // ===== what it cannot do ===============================================

    /** @return iterable<string, array{DeadlineType, string, CaseStatus}> */
    public static function noCloseProvider(): iterable
    {
        yield 'limitation' => [DeadlineType::PRESCRIPTIE, '+20 days', CaseStatus::AMIABIL];
        yield 'filing the request' => [DeadlineType::DEPUNERE_CERERE, '+20 days', CaseStatus::SOMATIE_TRIMISA];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE, '+400 days', CaseStatus::EXECUTARE];
        yield 'consumed fatal term' => [DeadlineType::TIMBRARE, '-3 days', CaseStatus::CERERE_DEPUSA];
        yield 'consumed annulment window' => [DeadlineType::CERERE_IN_ANULARE, '-3 days', CaseStatus::ORDONANTA_EMISA];
    }

    #[DataProvider('noCloseProvider')]
    public function testThePanelOffersNoCloseWhereTheRowOffersNone(DeadlineType $type, string $date, CaseStatus $status): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, $status);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-20 days'));
        $deadline = $this->makeDeadline($case, $date, $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->frame($deadline);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'), 'Nothing here closes this term.');
    }

    public function testAClosedTermHasNoCloseInThePanel(): void
    {
        $user = $this->makeUser();
        $deadline = $this->closedDeadline($this->makeCase($user), $user);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertCount(0, $this->frame($deadline)->filter('form'));
    }

    /** On a terminal case a term can still be read, but there is nothing left to do on it. */
    public function testATerminalCaseHasNoCloseInThePanel(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user, CaseStatus::INCHIS_SUCCES), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->frame($deadline);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form'));
    }

    /** A term consumed on an irreversible date says so instead of leaving a silent panel. */
    public function testAConsumedTermStatesIt(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user, CaseStatus::CERERE_DEPUSA), '-3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);

        self::assertStringContainsString($this->trans('deadlines.panel.consumed'), $this->panelBody($deadline)->text());
    }

    /** The debtor's term running out is good news, and the panel must not read as a miss. */
    public function testAnExpiredDebtorTermReadsAsOpeningTheFiling(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->attachProof($case);
        $deadline = $this->makeDeadline($case, '-14 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $text = $this->panelBody($deadline)->text();

        self::assertStringContainsString($this->trans('deadlines.panel.debtor_term_expired'), $text);
        self::assertStringNotContainsString($this->trans('deadlines.panel.consumed'), $text);
    }

    public function testNothingInThePanelGeneratesADocument(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->attachProof($case);
        $deadline = $this->makeDeadline($case, '-14 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->frame($deadline);
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('/summons/generate', $body);
        self::assertStringNotContainsString('/payment-order/generate', $body);
        self::assertStringNotContainsString('/transition/', $body);
    }

    // ===== its forms are the forms of the row ==============================

    public function testACloseFormCarriesTheContextAndTheSelection(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user, CaseStatus::CERERE_DEPUSA), '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);

        $default = $this->frame($deadline)->filter('form');
        self::assertCount(1, $default);
        self::assertSame('agenda', $default->filter('input[name="_context"]')->attr('value'));
        self::assertCount(0, $default->filter('input[name="tab"]'));
        self::assertCount(0, $default->filter('input[name="fatal"]'));

        $fatal = $this->frame($deadline, '?fatal=1')->filter('form');
        self::assertSame('1', $fatal->filter('input[name="fatal"]')->attr('value'));

        $tab = $this->frame($deadline, '?tab=de-completat')->filter('form');
        self::assertSame('de-completat', $tab->filter('input[name="tab"]')->attr('value'));
    }

    /** The row and the panel cannot diverge: same route, same dialog, same wording. */
    public function testThePanelCloseIsTheCloseOfTheRow(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user, CaseStatus::CERERE_DEPUSA), '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);

        $row = $this->client->request('GET', '/termene')->filter('#deadline-row-' . $deadline->getId() . ' form');
        $panel = $this->frame($deadline)->filter('form');

        self::assertSame($row->attr('action'), $panel->attr('action'));
        foreach (['data-action', 'data-agenda-dialog-title-param', 'data-agenda-dialog-body-param', 'data-agenda-dialog-facts-param', 'data-agenda-dialog-confirm-param'] as $attribute) {
            self::assertNotNull($panel->attr($attribute), $attribute);
            self::assertSame($row->attr($attribute), $panel->attr($attribute), $attribute);
        }
    }

    /** The row hands its selection to the panel it opens, so the panel can pass it on. */
    public function testTheRowLinksThePanelUnderTheActiveSelection(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user, CaseStatus::CERERE_DEPUSA), '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);

        $base = '/termene/' . $deadline->getId() . '/detalii';
        $selector = '#deadline-row-' . $deadline->getId() . ' [data-deadline-panel-url-param]';

        self::assertSame($base, $this->client->request('GET', '/termene')->filter($selector)->attr('data-deadline-panel-url-param'));
        self::assertSame($base . '?fatal=1', $this->client->request('GET', '/termene?fatal=1')->filter($selector)->attr('data-deadline-panel-url-param'));
    }

    /** No script: the row link is a plain link into the case. */
    public function testTheRowLinkFallsBackToTheCase(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::CERERE_DEPUSA);
        $deadline = $this->makeDeadline($case, '+3 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        $this->client->loginUser($user);
        $link = $this->client->request('GET', '/termene')->filter('#deadline-row-' . $deadline->getId() . ' a[data-deadline-panel-url-param]');

        self::assertSame('/case/' . $case->getId(), $link->attr('href'));
    }

    /** The one dialog the page carries is reachable from the panel of a limitation term. */
    public function testAMissingCommunicationDateOpensTheDialogFromThePanel(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::SOMATIE_TRIMISA);
        $deadline = $this->makeDeadline($case, '+20 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $this->client->loginUser($user);
        $link = $this->frame($deadline)->filter('a[data-action="agenda-dialog#openDialog"]');

        self::assertCount(1, $link);
        self::assertSame('hs-modal-set-summons-communication-date', $link->attr('data-agenda-dialog-dialog-param'));
        self::assertSame('/case/' . $case->getId() . '/summons-communication-date', $link->attr('data-agenda-dialog-action-param'));
        self::assertSame('/case/' . $case->getId(), $link->attr('href'));
    }

    public function testThePanelCarriesTheFrameThePageWaitsFor(): void
    {
        $user = $this->makeUser();
        $deadline = $this->makeDeadline($this->makeCase($user), '+3 days', DeadlineType::OTHER);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->frame($deadline);

        self::assertCount(1, $crawler->filter('turbo-frame#deadline-panel-frame[target="_top"]'));
    }

    // ===== the panel of a blocked case =====================================

    /** @return iterable<string, array{DeadlineBlockageReason}> */
    public static function reasonProvider(): iterable
    {
        foreach (DeadlineBlockageReason::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('reasonProvider')]
    public function testEveryReasonStatesWhatIsMissingAndWhatItCosts(DeadlineBlockageReason $reason): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, $reason);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->blockagePanel($case);

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('[data-testid="deadline-panel"]')->text();
        self::assertStringContainsString($this->trans($reason->missingDeadlineLabel()), $text);
        self::assertStringContainsString($this->trans($reason->causeLabel()), $text);
        self::assertStringContainsString($this->trans($reason->consequenceLabel()), $text);
        self::assertStringContainsString($this->trans($reason->legalBasisLabel()), $text);
        self::assertStringContainsString($this->trans($reason->stateLabel()), $text);
        self::assertStringContainsString($this->trans($reason->label()), $crawler->filter('h2')->text());

        // The action needs data this panel does not collect, so it is a link, never a form.
        self::assertCount(0, $crawler->filter('form'));
        self::assertCount(1, $crawler->filter('a')->reduce(
            fn (Crawler $link): bool => trim($link->text()) === $this->trans($reason->actionLabel()),
        ));
    }

    public function testTheBailiffNumberLeadsToTheTermeneTabOfTheCase(): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->blockagePanel($case);

        self::assertCount(1, $crawler->filter(sprintf('a[href="/case/%d?tab=termene"]', $case->getId())));
    }

    public function testAResolvedBlockageIsGone(): void
    {
        $user = $this->makeUser();
        $case = $this->makeCase($user, CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->blockagePanel($case);

        self::assertResponseStatusCodeSame(404);
    }

    /** The finder is scoped to the user, so another account gets the same 404 as a missing one. */
    public function testAForeignBlockedCaseIsGoneAndNamesNoDebtor(): void
    {
        $user = $this->makeUser();
        $this->makeCase($user);
        $foreign = $this->blockedCase($this->makeUser('-other'), DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->attachDebtor($foreign, self::DEBTOR);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->blockagePanel($foreign);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::DEBTOR, (string) $this->client->getResponse()->getContent());
    }

    public function testADeletedBlockedCaseIsGone(): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $case->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->client->loginUser($user);
        $this->blockagePanel($case);

        self::assertResponseStatusCodeSame(404);
    }

    public function testThePlainVisitOfABlockagePanelLandsOnTheAgenda(): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/termene/dosar/' . $case->getId() . '/completare?tab=de-completat');

        self::assertResponseRedirects('/termene?tab=de-completat');
    }

    public function testAnAnonymousVisitorCannotOpenABlockagePanel(): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->em->flush();

        $this->blockagePanel($case);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheBlockedRowLinksItsPanelUnderTheTab(): void
    {
        $user = $this->makeUser();
        $case = $this->blockedCase($user, DeadlineBlockageReason::RULING_COMMUNICATION_MISSING);
        $this->em->flush();

        $this->client->loginUser($user);
        $link = $this->client->request('GET', '/termene?tab=de-completat')->filter('[data-testid="blockage-row"] [data-deadline-panel-url-param]');

        self::assertSame(
            '/termene/dosar/' . $case->getId() . '/completare?tab=de-completat',
            $link->attr('data-deadline-panel-url-param'),
        );
    }

    // ===== helpers =========================================================

    private function panel(LegalDeadline $deadline, string $query = ''): Crawler
    {
        return $this->client->request('GET', '/termene/' . $deadline->getId() . '/detalii' . $query, [], [], self::FRAME);
    }

    private function frame(LegalDeadline $deadline, string $query = ''): Crawler
    {
        return $this->panel($deadline, $query);
    }

    private function panelBody(LegalDeadline $deadline): Crawler
    {
        $crawler = $this->panel($deadline);
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-testid="deadline-panel"]');
    }

    private function blockagePanel(LegalCase $case): Crawler
    {
        return $this->client->request('GET', '/termene/dosar/' . $case->getId() . '/completare', [], [], self::FRAME);
    }

    private function holdsUntil(string $communicatedOn): string
    {
        return static::getContainer()->get(\App\Service\Deadline\DeadlineService::class)
            ->filingInterruptionTermEnd(new \DateTimeImmutable($communicatedOn))->end->format('d.m.Y');
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, ?int $count = null, array $parameters = []): string
    {
        return static::getContainer()->get('translator')->trans($key, $parameters + ($count !== null ? ['%count%' => $count] : []));
    }
}
