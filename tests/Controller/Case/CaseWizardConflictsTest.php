<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The aggregation has always known what the documents disagreed about; until
 * now the wizard threw it away and showed the lawyer a single value with an
 * "auto" badge. A divergent registered office decides the competent court
 * (CPC art. 1013 alin. 1 with art. 107) and resolving it silently by ranking is
 * how a petition lands at the wrong court without anybody knowing it was in
 * dispute.
 */
final class CaseWizardConflictsTest extends WebTestCase
{
    private const SESSION_KEY = 'case_wizard_data';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-conflicts-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Conflict');
        $this->user->setLastName('Tester');
        $this->user->setAiProcessingAgreementAt(new \DateTimeImmutable());
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testADivergentRegisteredOfficeIsShownOnTheDebtorStep(): void
    {
        $this->primeDocuments([
            $this->payload('Alfa Construct SRL', '11111111', 'Cluj', null),
            $this->payload('Alfa Construct SRL', '11111111', 'București', null),
        ]);

        $crawler = $this->client->request('GET', '/case/new/debtor');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts"]')->count());
        $panel = $crawler->filter('[data-testid="prefill-conflicts"]')->text();
        self::assertStringContainsString('Documentele nu spun același lucru', $panel);
        self::assertStringContainsString('Județul debitorului stabilește instanța competentă', $panel);
    }

    public function testInvoicesToTwoDebtorsAreReportedAsBlockingOnTheDebtorStep(): void
    {
        $this->primeDocuments([
            $this->payload('Alfa Construct SRL', '11111111', 'Cluj', 1000.0),
            $this->payload('Beta Logistic SRL', '22222222', 'Iasi', 2000.0),
        ]);

        $crawler = $this->client->request('GET', '/case/new/debtor');

        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts-ack"]')->count());
        self::assertStringContainsString(
            'nu pot fi adunate într-o singură ordonanță de plată',
            $crawler->filter('[data-testid="prefill-conflicts"]')->text(),
        );
    }

    public function testADivergentCreditorNumberBlocksTheStepUntilTheLawyerChooses(): void
    {
        // Two documents naming two registration numbers for the party bringing
        // the claim is the assignment-of-debt case: which of the two is the
        // creditor in the file is a decision, not a ranking, and stating that
        // one has looked is not the same as making it.
        $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertGreaterThan(0, $crawler->filter('[data-testid="prefill-conflict-option"]')->count());
        // Nothing to acknowledge: the conflict offers values to choose between.
        self::assertSame(0, $crawler->filter('[data-testid="prefill-conflicts-ack"]')->count());
        // A blocking conflict starts with no option ticked, or submitting the
        // step untouched would count as a decision.
        self::assertSame(0, $this->checkedOptions($crawler, 'cui')->count());

        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');
        $fields = [
            'step1_creditor' => [
                '_token' => $token,
                'personType' => 'PJ',
                'name' => 'Cesionar SRL',
                'cui' => 'RO14186770',
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
            ],
        ];

        // Same status a rejected form gets: the submission is well formed and
        // still cannot be processed, and Turbo has to render the answer rather
        // than treat it as a completed step.
        $this->client->request('POST', '/case/new/creditor', $fields);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'Alege ce valoare rămâne în dosar',
            (string) $this->client->getResponse()->getContent(),
        );

        $fields['prefill_conflict'] = [$this->conflictKey('creditor', 'cui') => '1'];
        $this->client->request('POST', '/case/new/creditor', $fields);
        self::assertResponseRedirects('/case/new/debtor');
    }

    public function testTheChosenValueSurvivesNavigatingBackToTheStep(): void
    {
        $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $token,
                'personType' => 'PJ',
                'name' => 'Cesionar SRL',
                'cui' => 'RO14186770',
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
            ],
            'prefill_conflict' => [$this->conflictKey('creditor', 'cui') => '1'],
        ]);
        self::assertResponseRedirects('/case/new/debtor');

        $crawler = $this->client->request('GET', '/case/new/creditor');

        $checked = $this->checkedOptions($crawler, 'cui');
        self::assertSame(1, $checked->count());
        self::assertSame('1', $checked->first()->attr('value'));
    }

    public function testATypedValueOutranksBothDocuments(): void
    {
        $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/creditor');
        $token = $crawler->filter('form input[name="step1_creditor[_token]"]')->first()->attr('value');
        $key = $this->conflictKey('creditor', 'cui');
        $this->client->request('POST', '/case/new/creditor', [
            'step1_creditor' => [
                '_token' => $token,
                'personType' => 'PJ',
                'name' => 'Cesionar SRL',
                'cui' => 'RO14186770',
                'onrcNumber' => 'J40/1234/2018',
                'address' => 'Str. Exemplu nr. 1, București',
            ],
            'prefill_conflict' => [$key => 'manual'],
            'prefill_conflict_manual' => [$key => '42000006'],
        ]);
        self::assertResponseRedirects('/case/new/debtor');

        $crawler = $this->client->request('GET', '/case/new/creditor');
        self::assertSame(
            '42000006',
            $crawler->filter(sprintf('input[name="prefill_conflict_manual[%s]"]', $key))->first()->attr('value'),
        );
    }

    /**
     * The radio buttons of one conflict that are ticked.
     */
    private function checkedOptions(\Symfony\Component\DomCrawler\Crawler $crawler, string $field): \Symfony\Component\DomCrawler\Crawler
    {
        return $crawler
            ->filter(sprintf('input[name="prefill_conflict[%s]"]', $this->conflictKey('creditor', $field)))
            ->reduce(static fn (\Symfony\Component\DomCrawler\Crawler $node): bool => $node->attr('checked') !== null);
    }

    /**
     * The stable identity of a conflict, as {@see \App\DTO\Extraction\PrefillConflict::key()}
     * composes it. Written out here rather than read off the DOM so a change in
     * the shape breaks this test loudly.
     */
    private function conflictKey(string $scope, string $field): string
    {
        return $scope . ':-:' . $field . ':wizard.conflict.field.' . $field;
    }

    public function testTheStepZeroSideCardShowsTheDivergencesWithoutOfferingTheChoice(): void
    {
        // Seen as soon as the documents disagree, so three steps are not filled
        // on a party two files describe differently. Not decided here: the
        // remaining documents may still be processing, so the set is not final.
        $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/documents');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-testid="prefill-conflicts"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="prefill-conflict-option"]')->count());
    }

    public function testTheSourceOfEachValueIsNamedByItsFile(): void
    {
        $this->primeDocuments([
            $this->creditorPayload('Cedent SRL', '15193236'),
            $this->creditorPayload('Cesionar SRL', '14186770'),
        ]);

        $crawler = $this->client->request('GET', '/case/new/creditor');

        // The file name is what the lawyer has open next to the screen; a
        // document id is not something they can look up.
        self::assertStringContainsString('factura-0.pdf', $crawler->filter('[data-testid="prefill-conflicts"]')->text());
    }

    public function testAFileWithoutDisagreementShowsNoPanel(): void
    {
        $this->primeDocuments([$this->payload('Alfa Construct SRL', '11111111', 'Cluj', 1000.0)]);

        $crawler = $this->client->request('GET', '/case/new/debtor');

        self::assertSame(0, $crawler->filter('[data-testid="prefill-conflicts"]')->count());
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function primeDocuments(array $payloads): void
    {
        $ids = [];
        foreach ($payloads as $index => $payload) {
            $document = new Document();
            $document->setUploadedBy($this->user);
            $document->setDocumentType(DocumentType::FACTURA);
            $document->setOriginalFilename('factura-' . $index . '.pdf');
            $document->setStoredFilename('stored-' . uniqid() . '.pdf');
            $document->setMimeType('application/pdf');
            $document->setFileSize(1024);
            $document->setExtractionStatus(ExtractionStatus::COMPLETED);
            $document->setExtractedData($payload);
            $this->em->persist($document);
            $this->em->flush();
            $ids[] = $document->getId();
        }

        $this->client->request('GET', '/case/new/documents');
        $session = $this->client->getRequest()->getSession();
        $session->set(self::SESSION_KEY, ['documentIds' => $ids]);
        $session->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function creditorPayload(string $name, string $cui): array
    {
        return [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'creditor' => [
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name, string $cui, string $county, ?float $amount): array
    {
        $payload = [
            'schemaVersion' => 2,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [[
                'personType' => 'PJ',
                'name' => $name,
                'cui' => $cui,
                'county' => $county,
                'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95, 'county' => 0.95],
            ]],
        ];
        if ($amount !== null) {
            $payload['claim'] = [
                'amount' => $amount,
                'currency' => 'RON',
                'confidencePerField' => ['amount' => 0.95, 'currency' => 0.95],
            ];
        }

        return $payload;
    }
}
