<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Invoices, the document the claim itself rests on. The amount and the due date
 * read from here end up in the petitum and in the interest calculation, so the
 * instructions spend their length on exactly those two and on the invoice
 * identifiers that let the same invoice be recognised across documents.
 */
final class InvoicePrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'invoice';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::FACTURA;
    }

    public function maxTokens(): int
    {
        return self::LEDGER_MAX_TOKENS;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        DOCUMENTUL ATAȘAT este o FACTURĂ. Extrage datele cu atenție specială la:

        PĂRȚI: emitentul facturii, adică blocul „Furnizor” / „Vânzător” /
        „Prestator”, este CREDITORUL. Blocul „Client” / „Cumpărător” /
        „Beneficiar” / „Achizitor” este DEBITORUL. Cele două blocuri arată
        identic (denumire, CUI, J, adresă), iar IBAN-ul tipărit pe factură este
        al furnizorului: contul de încasare NU schimbă rolurile.

        PROFORMĂ: dacă documentul poartă mențiunea „PROFORMĂ”, „ofertă” sau
        „deviz”, nu este factură fiscală. Omite `amount` și
        `dueDate` și scrie în `description` că documentul este o
        proformă: singură, nu întemeiază o creanță certă și exigibilă.

        SUME:
          • `amount` este TOTALUL DE PLATĂ al facturii, cu TVA inclus, în moneda
            facturii. Nu returna baza impozabilă și nu returna valoarea TVA.
          • Dacă factura are scăzăminte (avans, storno parțial, reținere de
            garanție) menționate pe ea, `amount` rămâne totalul facturat, iar
            scăzământul se descrie în `description`. Imputarea o face avocatul.
          • `currency` este moneda de facturare, nu moneda echivalentului
            informativ în lei tipărit pentru TVA.

        SCADENȚĂ:
          • `dueDate` este termenul de plată al acestei facturi. Dacă factura
            indică un termen relativ („scadent la 30 de zile de la emitere”),
            calculează data pornind de la `invoiceDate` și explică în
            `description`.
          • Dacă nu apare niciun termen, omite câmpul. NU folosi data
            emiterii ca scadență: o scadență greșită modifică dobânda datorată.

        IDENTIFICARE:
          • `invoiceNumber` conține seria și numărul așa cum apar pe document
            (ex: „MJ 2024-00123”), fără cuvintele „nr.” sau „factura”.
          • `invoiceDate` este data emiterii.
          • Dacă factura menționează contractul în baza căruia a fost emisă,
            completează `contractNumber`, `contractDate` și `contractReference`.

        Factura este documentul autoritar pentru sumă și scadență; nu completa
        clauze de penalitate decât dacă sunt tipărite chiar pe această factură.
        PROMPT;
    }
}
