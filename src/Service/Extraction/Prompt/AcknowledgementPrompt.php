<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Balance confirmations and other acknowledgements of debt. Evidentially these
 * are the strongest documents in the file, because the debtor signed the amount
 * itself, and they usually enumerate every open invoice, which is why they get
 * the ledger-sized output budget rather than the compact one.
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

        SEMNĂTURA: notează în `description` cine a semnat pentru debitor și data
        semnării. O recunoaștere nesemnată de debitor își pierde valoarea
        probatorie, deci dacă documentul nu poartă semnătura sau ștampila
        debitorului, spune asta explicit.

        `dueDate` se completează doar dacă documentul stabilește un termen ferm
        de achitare a soldului recunoscut.
        PROMPT;
    }
}
