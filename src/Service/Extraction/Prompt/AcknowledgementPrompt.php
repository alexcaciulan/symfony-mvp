<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Balance confirmations, account statements and other acknowledgements of
 * debt. Evidentially these are the strongest documents in the file, because
 * the debtor signed the amount itself, and they usually enumerate every open
 * invoice and the payments made against them, which is why they get the
 * ledger-sized output budget rather than the compact one.
 */
final class AcknowledgementPrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'acknowledgement';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::CONFIRMARE_SOLD;
    }

    public function maxTokens(): int
    {
        return self::LEDGER_MAX_TOKENS;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        DOCUMENTUL ATAȘAT este o CONFIRMARE DE SOLD, un punctaj sau o
        recunoaștere de datorie. Extrage cu atenție specială la:

        SOLDUL RECUNOSCUT:
          • `amount` este soldul pe care DEBITORUL îl recunoaște ca datorat,
            nu totalul facturat și nu totalul plătit.
          • Dacă documentul conține două solduri (unul al furnizorului, unul al
            clientului) și acestea diferă, completează `amount` cu soldul
            recunoscut de debitor și notează ambele valori în `description`.
            Diferența dintre ele este exact ceea ce avocatul trebuie să vadă.

        DEFALCARE:
          • În `description` enumeră pozițiile confirmate, câte una pe rând, în
            forma: `număr factură | dată | sumă | valută | sold rămas`.
          • Preia numerele de factură exact cum apar, fără normalizare.

        PLĂȚI (fișă de cont sau punctaj care arată și încasările):
          • Pune în `payments` fiecare plată a debitorului pe care documentul o
            arată: `amount`, `currency`, `date` și, în `invoiceReferences`,
            factura sau facturile pe care documentul o leagă de plată.
          • Dacă documentul spune că anumite facturi sunt achitate integral
            (ex: „achitate integral: 20951, 20954”), adaugă un element cu acele
            facturi în `invoiceReferences` și `fullyPaid` = true, chiar dacă
            suma plății nu apare.
          • O confirmare care arată doar un sold, fără plăți, nu are `payments`.

        SEMNĂTURA: notează în `description` cine a semnat pentru debitor și data
        semnării. O recunoaștere nesemnată de debitor își pierde valoarea
        probatorie, deci dacă documentul nu poartă semnătura sau ștampila
        debitorului, spune asta explicit.

        `dueDate` se completează doar dacă documentul stabilește un termen ferm
        de achitare a soldului recunoscut.
        PROMPT;
    }
}
