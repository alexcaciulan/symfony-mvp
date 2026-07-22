<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * A demand letter the creditor already sent, or other correspondence between
 * the parties. It matters procedurally: the notice and the term it granted are
 * what the court looks at for the prior-summons condition, and its date marks
 * when the debtor was put in default.
 */
final class PriorNoticePrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'prior_notice';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::SOMATIE_ANTERIOARA || $type === DocumentType::NOTIFICARE;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        DOCUMENTUL ATAȘAT este o SOMAȚIE sau o NOTIFICARE trimisă anterior între
        părți. Extrage cu atenție specială la:

        EXPEDITOR ȘI DESTINATAR: expeditorul somației este CREDITORUL, iar
        destinatarul este DEBITORUL. Această corespondență este singura în care
        rolurile rezultă din sensul comunicării, nu dintr-un raport contractual.

        TERMENUL ACORDAT:
          • În `description` notează, în această ordine: data somației, termenul
            acordat pentru plată așa cum e formulat („în 15 zile de la primire”),
            data-limită rezultată dacă e calculabilă, și modalitatea de
            comunicare menționată (poștă cu confirmare de primire, curier,
            e-mail, executor).
          • Termenul acordat NU se completează în `dueDate`. `dueDate` rămâne
            scadența creanței din documentele care o stabilesc (factură,
            contract); confundarea celor două ar muta scadența creanței la data
            somației și ar reduce dobânda datorată.

        SUMA: `amount` este suma pretinsă prin somație, dacă e indicată.
        Enumeră în `description` facturile invocate, cu numere și sume, exact
        cum apar.
        PROMPT;
    }
}
