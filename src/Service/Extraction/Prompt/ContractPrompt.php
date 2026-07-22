<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Contracts and their amendments. This is the authoritative source for the
 * legal ground, the penalty clause, the payment term and any jurisdiction
 * clause, so the instructions push those and explicitly discourage reading a
 * claim amount out of a contract, which would compete with the invoice.
 */
final class ContractPrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'contract';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::CONTRACT || $type === DocumentType::ACT_ADITIONAL;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        DOCUMENTUL ATAȘAT este un CONTRACT sau un ACT ADIȚIONAL. Extrage cu
        atenție specială la:

        TEMEI:
          • `legalGround` este natura raportului juridic din contract
            (prestări servicii, vânzare, locațiune, împrumut, antrepriză).
          • `contractNumber`, `contractDate` și `contractReference` (obiectul
            contractului, așa cum e denumit în preambul).
          • La act adițional, `contractNumber` și `contractDate` sunt ale
            CONTRACTULUI MODIFICAT, nu ale actului adițional; numărul actului
            adițional se menționează în `description`.

        TERMEN DE PLATĂ:
          • Dacă contractul stabilește un termen („plata în 15 zile de la
            emiterea facturii"), descrie-l în `description` cu formularea din
            contract. `dueDate` se completează DOAR dacă în contract apare o
            dată calendaristică fermă de plată.

        PENALITATE: aplică regulile de penalitate din instrucțiunile de sistem.
        Contractul este singura sursă legitimă pentru o rată contractuală, deci
        citează în `description` clauza exactă pe care te-ai bazat.

        COMPETENȚĂ: dacă există o clauză atributivă de competență („litigiile se
        soluționează de instanțele din ..."), menționeaz-o în `description`.

        SUMĂ: `amount` se completează DOAR dacă contractul stabilește un preț
        total ferm. Pentru contracte cu prestații succesive sau preț unitar
        omite `amount`: suma datorată rezultă din facturi, nu din contract.
        Prețul contractual NU este suma restantă: dacă documentul nu arată cât a
        rămas neplătit, spune în `description` că valoarea este prețul
        contractului, nu debitul.
        PROMPT;
    }
}
