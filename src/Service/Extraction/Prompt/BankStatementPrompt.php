<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Bank statements. They legitimately carry almost no party identification and
 * no claim of their own; what they do carry is proof of which invoices were
 * paid and which were not, which is why the instructions ask for payment
 * references in the description rather than for a claim amount.
 */
final class BankStatementPrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'bank_statement';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::EXTRAS_CONT;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        DOCUMENTUL ATAȘAT este un EXTRAS DE CONT. Este o probă de plată, nu un
        titlu de creanță, deci:

        PĂRȚI:
          • Titularul contului este de regulă CREDITORUL (a primit plăți) sau
            DEBITORUL (a efectuat plăți). Stabilește rolul din sensul
            operațiunilor, nu din poziția pe pagină, și completează numele,
            `iban` și `bankName` acolo unde le poți susține.
          • Dacă din extras nu rezultă fără dubiu cine e creditor și cine e
            debitor, completează doar partea de care ești sigur și omite cealaltă
            secțiune. NU repartiza arbitrar rolurile.

        PLĂȚI:
          • În `description` enumeră încasările și plățile relevante pentru
            creanță, câte una pe rând, în forma:
            `data | sens (încasare/plată) | sumă | valută | detalii plată | factura referită`.
          • Sensul operațiunii este obligatoriu: fără el rândul nu poate fi
            folosit. Încasările titularului-creditor sting parțial creanța;
            enumeră-le ca atare, fără să le scazi tu din `amount`. Imputația
            plății o face avocatul.
          • Preia referința la factură exact cum apare în explicația plății
            („contravaloare fact. MJ-123/2025”). Aceste referințe permit
            corelarea plăților cu facturile, deci nu le prescurta și nu le
            normaliza.
          • Nu completa `amount` cu totalul rulajelor sau cu soldul contului:
            niciunul nu este suma pretinsă. Completează `amount` doar dacă
            extrasul indică explicit un sold restant al debitorului.

        Lipsa datelor de identificare completă a părților este normală pentru
        acest tip de document și NU trebuie compensată prin deducții.
        PROMPT;
    }
}
