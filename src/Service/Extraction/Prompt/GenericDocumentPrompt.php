<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * First pass over a document whose type nobody declared. It classifies and
 * extracts in the same call, which is what breaks the circularity of "pick a
 * specialised prompt for a type you do not know yet".
 *
 * There is deliberately no automatic second call once the type is known: the
 * binary is the expensive part of the request, and resending it to re-read the
 * same page would double the input cost for a marginal gain. The specialised
 * prompt is used the next time the document is processed, once the type is
 * recorded, and only then.
 */
final class GenericDocumentPrompt extends AbstractExtractionPrompt
{
    public function key(): string
    {
        return 'generic';
    }

    public function supports(DocumentType $type): bool
    {
        // Accepts everything, so the registry always has a resolution. The
        // priority below keeps it out of the way of the specialised prompts.
        return true;
    }

    public function priority(): int
    {
        return 0;
    }

    public function maxTokens(): int
    {
        // Unknown type means unknown density: this same prompt sees both a
        // one-page handover report and an invoice with thirty lines, and it is
        // the only prompt that also has to emit a classification. Budgeting it
        // below the specialised invoice prompt made the pass over an undeclared
        // invoice the tightest one in the system, which is the wrong way round.
        return self::LEDGER_MAX_TOKENS;
    }

    protected function classifies(): bool
    {
        return true;
    }

    public function userInstructions(): string
    {
        return <<<'PROMPT'
        Analizează DOCUMENTUL ATAȘAT și execută două sarcini în același răspuns.

        RĂSPUNSUL este UN SINGUR obiect JSON, care conține deopotrivă
        `classification` și secțiunile de extracție. Nu returna două obiecte
        separate, câte unul pe sarcină, și nu adăuga text înainte sau după el.

        1. CLASIFICARE (`classification`): stabilește ce fel de document este,
           alegând din lista de tipuri din instrucțiunile de sistem.
           • `confidence` exprimă cât de sigur ești pe tip. Un scor mic nu
             înseamnă o extracție ratată, ci că tipul va fi confirmat de
             avocat, deci nu umfla scorul: când documentul e ambiguu sau
             conține mai multe acte lipite, returnează `alt_document` cu
             confidence redusă.
           • `subtype` este opțional, pentru nuanțe pe care lista nu le acoperă
             (ex: „factură storno”, „contract-cadru”).
           • Factura proformă, oferta și devizul NU sunt facturi fiscale: se
             clasifică `alt_document`, cu mențiunea în `subtype`.
           • Factura storno și nota de credit scad creanța: clasifică-le
             `factura`, notează „storno” în `subtype` și omite
             `claim.amount`, ca o sumă negativă să nu ajungă principal.
           • Notificarea care nu cere plată (reziliere, denunțare unilaterală)
             rămâne `notificare`, dar secțiunea `claim` se omite.
           • `rationale` este o singură propoziție cu elementul decisiv (antet,
             titlu, formular tipizat), pentru trasabilitate.

        2. EXTRACȚIE: completează `creditor`, `debtor` și `claim` cu tot ce
           apare în document. Dacă documentul nu conține deloc o secțiune (de
           exemplu un proces-verbal fără sume), omite acea secțiune în loc să
           deduci valori.
           • `amount` este totalul de plată, cu TVA inclus. Nu returna baza
             impozabilă și nu returna separat valoarea TVA.
           • `dueDate` este scadența, NU data emiterii. Dacă documentul indică
             doar un termen relativ („în 30 de zile de la emitere”), calculează
             data și explică în `description`. Dacă nu apare niciun termen,
             omite câmpul.
           • Prețul stabilit printr-un contract nu este suma datorată: suma
             datorată rezultă din facturi. Într-un contract completează
             `amount` doar la un preț total ferm.
           • Plățile care apar în document (încasări, plăți parțiale) sting
             creanța; enumeră-le în `description` cu sensul lor, fără să le
             scazi din `amount`.

        Datele extrase trebuie să provină din documentul acesta, nu din
        presupuneri despre ce ar conține de obicei un document de acest tip.
        PROMPT;
    }
}
