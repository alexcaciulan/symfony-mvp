<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\Calculation\InterestPeriod;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Enum\AnafStatus;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Document\SummonsContextBuilder;
use App\Service\Validation\OpAdmissibilityValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:verify-creanta', description: 'Throwaway end-to-end check of the interest/admissibility changes')]
final class VerifyCreantaCommand extends Command
{
    public function __construct(
        private readonly InterestCalculatorService $interest,
        private readonly OpAdmissibilityValidator $admissibility,
        private readonly SummonsContextBuilder $summons,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ref = new \DateTimeImmutable('2026-06-30');
        $invoiceDate = new \DateTimeImmutable('2024-02-10');
        $dueDate = new \DateTimeImmutable('2024-03-15');
        $principal = 50_000.0;
        $pass = true;

        // --- M3 + M7: interest calculation over real seeded BNR rates ---
        $io->title('Calcul creanță (dosar nou) — principal 50.000 RON COMERCIAL/PENALIZATOARE');
        $io->writeln(sprintf(
            'Factură: %s | Scadență: %s | Data referință: %s',
            $invoiceDate->format('d.m.Y'),
            $dueDate->format('d.m.Y'),
            $ref->format('d.m.Y'),
        ));

        $result = $this->interest->calculate(
            amount: $principal,
            dueDate: $dueDate,
            referenceDate: $ref,
            relationshipType: RelationshipType::COMERCIAL,
            invoiceDate: $invoiceDate,
        );

        $rows = [];
        $sumDays = 0;
        foreach ($result->breakdown as $p) {
            /** @var InterestPeriod $p */
            $sumDays += $p->days;
            $rows[] = [
                $p->startDate->format('d.m.Y') . ' – ' . $p->endDate->format('d.m.Y'),
                $p->days,
                number_format($p->nbrRate, 2) . '%',
                number_format($p->applicableRate, 2) . '%',
                number_format($p->periodInterest, 2) . ' RON',
            ];
        }
        $io->table(['Perioadă', 'Zile', 'BNR', 'Aplicabilă (BNR+8)', 'Dobândă parțială'], $rows);
        $io->writeln('Total dobândă: <info>' . number_format($result->total, 2) . ' RON</info>');

        // M3: Z+1 — sum of segment days must equal diff(dueDate, referenceDate)
        $expectedDays = (int) $dueDate->diff($ref)->days;
        $z1 = $sumDays === $expectedDays;
        $pass = $pass && $z1;
        $io->writeln(sprintf(
            '[M3 Z+1] Σ zile segmente = %d, diff(scadență, referință) = %d → %s',
            $sumDays,
            $expectedDays,
            $z1 ? '<info>OK</info>' : '<error>FAIL</error>',
        ));

        // M7: invoiceDate echoed in the result for audit
        $m7 = $result->invoiceDate !== null
            && $result->invoiceDate->format('Y-m-d') === $invoiceDate->format('Y-m-d')
            && $result->dueDate->format('Y-m-d') === $dueDate->format('Y-m-d')
            && $result->referenceDate->format('Y-m-d') === $ref->format('Y-m-d');
        $pass = $pass && $m7;
        $io->writeln(sprintf(
            '[M7 audit] InterestResult conține invoiceDate=%s, dueDate=%s, referenceDate=%s → %s',
            $result->invoiceDate?->format('d.m.Y') ?? 'NULL',
            $result->dueDate->format('d.m.Y'),
            $result->referenceDate->format('d.m.Y'),
            $m7 ? '<info>OK</info>' : '<error>FAIL</error>',
        ));

        // M7 wiring: SummonsContextBuilder exposes invoiceDate
        $summonsCase = $this->makeCase($principal, $dueDate, $ref, $invoiceDate);
        $ctx = $this->summons->build($summonsCase);
        $m7b = isset($ctx['invoiceDate'])
            && $ctx['invoiceDate'] instanceof \DateTimeImmutable
            && $ctx['interestResult']?->invoiceDate?->format('Y-m-d') === $invoiceDate->format('Y-m-d');
        $pass = $pass && $m7b;
        $io->writeln(sprintf(
            '[M7 somație] SummonsContextBuilder.invoiceDate=%s, grandTotal=%s RON → %s',
            $ctx['invoiceDate']?->format('d.m.Y') ?? 'NULL',
            number_format($ctx['grandTotal'], 2),
            $m7b ? '<info>OK</info>' : '<error>FAIL</error>',
        ));

        // --- M4: future due date is inadmissible ---
        $io->section('Admisibilitate (CPC art. 1013 exigibilitate)');
        $now = new \DateTimeImmutable('2026-06-30');

        $futureCase = $this->makeCase($principal, $now->modify('+30 days'), $ref, $invoiceDate);
        $futureCase->addDebtor($this->cleanDebtor($now));
        $futureCodes = array_map(fn($i) => $i->code, $this->admissibility->validate($futureCase, $now));
        $m4a = in_array('OP_DEBT_NOT_YET_DUE', $futureCodes, true);

        $pastCase = $this->makeCase($principal, $now->modify('-30 days'), $ref, $invoiceDate);
        $pastCase->addDebtor($this->cleanDebtor($now));
        $pastCodes = array_map(fn($i) => $i->code, $this->admissibility->validate($pastCase, $now));
        $m4b = !in_array('OP_DEBT_NOT_YET_DUE', $pastCodes, true);

        $pass = $pass && $m4a && $m4b;
        $io->writeln(sprintf('[M4 scadență viitoare +30z] issues=%s → %s', implode(',', $futureCodes) ?: '(none)', $m4a ? '<info>ERROR OP_DEBT_NOT_YET_DUE prezent (OK)</info>' : '<error>FAIL</error>'));
        $io->writeln(sprintf('[M4 scadență trecută -30z] issues=%s → %s', implode(',', $pastCodes) ?: '(none)', $m4b ? '<info>fără eroare exigibilitate (OK)</info>' : '<error>FAIL</error>'));

        // --- M6 boundary: non-RON rejected ---
        $io->section('Monedă (limitare MVP)');
        $m6 = false;
        try {
            $this->interest->calculate($principal, $dueDate, $ref, RelationshipType::COMERCIAL, currency: 'EUR');
        } catch (\InvalidArgumentException) {
            $m6 = true;
        }
        $pass = $pass && $m6;
        $io->writeln('[M6 EUR respins] → ' . ($m6 ? '<info>OK</info>' : '<error>FAIL</error>'));

        $io->newLine();
        if ($pass) {
            $io->success('TOATE verificările au trecut — calculele și modificările se aplică corect.');

            return Command::SUCCESS;
        }
        $io->error('Cel puțin o verificare a eșuat.');

        return Command::FAILURE;
    }

    private function makeCase(float $principal, \DateTimeImmutable $due, \DateTimeImmutable $ref, \DateTimeImmutable $invoice): LegalCase
    {
        $case = new LegalCase();
        $case->setCurrency('RON');
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setPenaltyType(PenaltyType::LEGAL_PENALIZATOARE);
        $case->setAmount(number_format($principal, 2, '.', ''));
        $case->setDueDate(\DateTime::createFromInterface($due));
        $case->setInvoiceDate(\DateTime::createFromInterface($invoice));
        $case->setPaymentNoticeDate(\DateTime::createFromInterface($ref));

        return $case;
    }

    private function cleanDebtor(\DateTimeImmutable $now): Debtor
    {
        $debtor = new Debtor();
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Test Debitor SRL');
        $debtor->setAddress('Str. Test 1, București');
        $debtor->setAnafStatus(AnafStatus::ACTIV);
        $debtor->setAnafCheckedAt($now->modify('-3 days'));
        $debtor->setInInsolvency(false);
        $debtor->setInsolvencyCheckedAt($now->modify('-3 days'));

        return $debtor;
    }
}
