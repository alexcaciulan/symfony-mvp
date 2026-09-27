<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\DocumentType;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;

/**
 * The parts every extraction prompt shares: the party-role glossary, the
 * address rules, the penalty rules, the confidence rules and the interpolated
 * enum vocabularies, plus the JSON Schema fragments that describe the response.
 *
 * They live in one place for two reasons. The first is correctness: the role
 * glossary decides who sues whom, so a copy that drifts in one prompt produces
 * a filing with the parties reversed. The second is cost: the provider serves a
 * cached prefix only on an exact byte match, so a single reworded sentence in
 * one prompt makes every document in the batch pay full input price.
 */
final class SharedPromptFragments
{
    /**
     * Types the classifier may return. Auto-generated types are excluded: the
     * platform produces those itself, so a lawyer never uploads one and letting
     * the model pick one would mislabel evidence as a generated filing.
     *
     * @return list<DocumentType>
     */
    public static function classifiableTypes(): array
    {
        return array_values(array_filter(
            DocumentType::uploadableTypes(),
            static fn (DocumentType $type): bool => $type->isClassifiable(),
        ));
    }

    /**
     * The stable block, identical for every prompt. Kept as one method rather
     * than assembled per prompt so there is no way to compose a variant.
     */
    public static function systemPrompt(): string
    {
        return implode("\n\n", [
            self::role(),
            self::partyGlossary(),
            self::classificationVocabulary(),
            self::confidenceRules(),
            self::fieldFormatRules(),
            self::addressRules(),
            self::penaltyRules(),
            self::outputRules(),
        ]);
    }

    private static function role(): string
    {
        return 'Ești un expert în extracție de date din documente juridice și comerciale '
            . 'românești (facturi, contracte, extrase de cont, somații, recunoașteri de datorie). '
            . 'Documentul analizat stă la baza unei cereri de ordonanță de plată '
            . '(Codul de procedură civilă art. 1013-1024).';
    }

    private static function partyGlossary(): string
    {
        return <<<'FRAGMENT'
        ROLURILE PĂRȚILOR: folosește acest glosar STRICT, niciodată nu inversa rolurile:

        CREDITOR (cel care PRETINDE plata, livrează bunul/serviciul, este partea
        neplătită) = oricare dintre acești termeni contractuali RO:
          • Prestator (în contract de prestări servicii)
          • Furnizor / Vânzător (în factură sau contract de vânzare)
          • Executant / Antreprenor (în contract de antrepriză/lucrări)
          • Locator (în contract de locațiune, proprietarul)
          • Imprumutător / Creditor (în contract de împrumut)
          • Cedent (în cesiune de creanță)
          • Emitent / Trăgător (în cambie / bilet la ordin / cec)
          • Mandant (în mandat)
          • Producător

        DEBITOR (cel care DATOREAZĂ plata, primește bunul/serviciul) = oricare dintre:
          • Beneficiar (în contract de prestări servicii)
          • Client / Cumpărător / Achizitor (în factură sau contract de vânzare)
          • Locatar / Chiriaș (în locațiune)
          • Imprumutat / Debitor (în împrumut)
          • Cesionar (în cesiune)
          • Trasă (în cambie)
          • Mandatar (în mandat, dacă datorează contravaloare servicii)

        REGULA-CHEIE: în contractele de prestări servicii românești tipice,
        "Prestator" e CREDITOR și "Beneficiar" e DEBITOR, chiar dacă în text
        Prestator apare cu un cont bancar (acela e contul în care primește plata),
        NU îl confunda cu Debitor. Plata curge DE LA Beneficiar (debitor) CĂTRE
        Prestator (creditor).
        FRAGMENT;
    }

    private static function classificationVocabulary(): string
    {
        $lines = [];
        foreach (self::classifiableTypes() as $type) {
            $lines[] = '  • ' . $type->value . ': ' . self::typeDescription($type);
        }

        return "TIPURI DE DOCUMENT recunoscute:\n" . implode("\n", $lines);
    }

    private static function typeDescription(DocumentType $type): string
    {
        return match ($type) {
            // Deliberately narrow: a proforma is not a fiscal document and does
            // not on its own found a certain, liquid and due claim, so it must
            // not land on the prompt that treats the amount as authoritative.
            DocumentType::FACTURA => 'factură fiscală emisă către client (NU proformă, ofertă sau deviz)',
            DocumentType::CONTRACT => 'contract, convenție sau înțelegere semnată de părți',
            DocumentType::ACT_ADITIONAL => 'act adițional care modifică un contract existent',
            DocumentType::EXTRAS_CONT => 'extras de cont bancar cu rulaje și solduri',
            DocumentType::CONFIRMARE_SOLD => 'confirmare de sold, punctaj sau recunoaștere de datorie',
            DocumentType::PROCES_VERBAL => 'proces-verbal de recepție, predare-primire sau constatare, inclusiv notă de recepție',
            DocumentType::COMANDA => 'comandă fermă, ofertă acceptată sau notă de comandă',
            DocumentType::NOTIFICARE => 'notificare, adresă sau corespondență între părți, inclusiv notificare de reziliere sau denunțare',
            DocumentType::SOMATIE_ANTERIOARA => 'somație de plată sau punere în întârziere trimisă anterior de creditor',
            DocumentType::TITLU_VALOARE => 'cambie, bilet la ordin sau cec',
            DocumentType::DOVADA_COMUNICARE => 'dovadă de comunicare: confirmare poștală, AR, borderou curier',
            DocumentType::ACT_CONSTATATOR => 'act constatator emis de o autoritate',
            DocumentType::ORDONANTA_PLATA => 'hotărâre judecătorească de ordonanță de plată',
            DocumentType::BPI_PROOF => 'extras din Buletinul Procedurilor de Insolvență',
            DocumentType::DOVADA => 'înscris doveditor care nu intră în categoriile de mai sus, inclusiv aviz de însoțire a mărfii',
            DocumentType::ANEXA => 'anexă la un alt document',
            DocumentType::ALT_DOCUMENT => 'orice altceva, sau când nu ești sigur',
            default => 'document justificativ',
        };
    }

    private static function confidenceRules(): string
    {
        return <<<'FRAGMENT'
        CONFIDENCE (0..1) reflectă cât de sigur ești pe baza vizuală: text și
        sigiliu clare = 0.95+; text obscurat parțial sau ambiguu = 0.5-0.7;
        dedus din context = 0.3-0.5.
        OBLIGATORIU: pentru FIECARE câmp completat adaugă scorul corespunzător
        în `confidencePerField`. Câmpurile fără scor sunt IGNORATE la
        pre-completarea formularului, deci un câmp extras fără scor este muncă
        pierdută. Pentru câmpurile care lipsesc din document omite atât cheia
        valorii, cât și cheia scorului.
        Pentru email și telefon omite câmpul dacă nu apar explicit. Nu inventa.
        Pentru onrcNumber respectă formatul canonic `J40/1234/2025` (acceptă și
        „Nr. ORC”, „Reg. Com.”, „J40/...”, „C.U.I./J...” din antetul documentului).
        FRAGMENT;
    }

    /**
     * Per-field shapes the readers downstream accept. A value the reader cannot
     * parse is dropped silently, so a wrongly formatted date or phone number
     * costs the same as one that was never extracted.
     */
    private static function fieldFormatRules(): string
    {
        return <<<'FRAGMENT'
        FORMATUL VALORILOR (o valoare în alt format este ARUNCATĂ la citire):
          • Toate datele calendaristice se returnează strict ca `YYYY-MM-DD`
            (ex: 2025-03-15), indiferent cum apar în document („15.03.2025”,
            „15 martie 2025”). Omite câmpul dacă data nu poate fi determinată;
            nu inventa ziua sau luna.
          • `cui`: doar cifrele, fără prefixul „RO” și fără spații (ex: 12345678).
          • `personalId`: CNP, exact 13 cifre, doar pentru persoane fizice.
          • `onrcNumber`: forma canonică `J40/1234/2025` (acceptă și „Nr. ORC”,
            „Reg. Com.”, „C.U.I./J...” din antetul documentului).
          • `iban`: fără spații, „RO” plus 22 de caractere.
          • `phone`: format compact, `0XXXXXXXXX` sau `+40XXXXXXXXX`, fără
            puncte, spații sau paranteze.
          • `invoiceNumber`: seria și numărul așa cum apar (ex: „MJ 2024-00123”),
            fără cuvintele „nr.” sau „factura”.
          • `bankName`: denumirea băncii la care e deschis contul.
          • `legalRepresentative` / `administrator`: numele reprezentantului
            legal sau al administratorului, așa cum apare în document.
          • `amount` și `contractualPenaltyRate` sunt numere, cu punct zecimal,
            fără separator de mii și fără simbolul monedei.
          • `currency`: doar `RON` sau `EUR`. Pentru orice altă monedă omite
            câmpul și scrie moneda reală în `description`.
        FRAGMENT;
    }

    private static function addressRules(): string
    {
        return <<<'FRAGMENT'
        ADRESĂ (`county` + `locality`) se extrag pentru AMBELE părți:
          • `county` alege instanța competentă pentru debitor și primăria care încasează
            taxa de timbru pentru creditor. O valoare greșită trimite dosarul la instanța
            greșită, deci NU ghici: dacă județul nu apare explicit sau nu rezultă fără
            dubiu din localitate, omite câmpul.
          • NU deduce județul din prefixul telefonic, din CUI sau din seria ONRC.
          • Facturile e-Factura codifică județul ca ISO 3166-2 în câmpul „Regiune”
            (ex: „RO-B” = București, „RO-CJ” = Cluj, „RO-IF” = Ilfov, „RO-TM” = Timiș).
            Convertește codul în denumirea județului, nu îl copia ca atare.
          • București: `county` este „București” (NU „Ilfov”, care e alt județ, și nu
            „Municipiul București”). Sectoarele apar adesea lipite sau cu sufix
            („SECTOR1”, „Sector 1 Mun. București”, „Sectorul 1”): normalizează
            `locality` la forma „Sector 1” … „Sector 6”.
          • Orice adresă care conține un sector are `county` = „București”.
          • Fără prefixele „jud.”, „mun.”, „oraș”, „comuna” în valorile returnate.
        FRAGMENT;
    }

    private static function penaltyRules(): string
    {
        return <<<'FRAGMENT'
        PENALITĂȚI (`penaltyType` + `contractualPenaltyRate`):
          • Returnează `penaltyType="CONTRACTUAL"` ȘI `contractualPenaltyRate` (rata zilnică
            ca procent, ex: 0.1 pentru „0,1% pe zi”) STRICT DOAR dacă documentul conține o
            clauză explicită de penalități sau majorări cu rată PER ZI / PER ZI CALENDARISTICĂ,
            formulată explicit în procente pe zi (ex: „penalități de 0,1%/zi de întârziere”,
            „0,15% pentru fiecare zi de întârziere”, „majorări de 0,1% pe zi calendaristică
            de întârziere").
          • Dacă rata e exprimată PER LUNĂ (ex: „1% pe lună”, „1%/lună”), PER AN (ex: „18%
            pe an", „dobândă de întârziere de 18% anual”) sau ca SUMĂ FIXĂ (ex: „100 RON
            pe zi"), omite `penaltyType` și `contractualPenaltyRate`. NU
            converti rate lunare sau anuale în rate zilnice și NU confunda dobânda
            remuneratorie (pe durata contractului) cu penalitatea de întârziere.
          • Dacă NU există nicio clauză de penalitate sau majorare de întârziere, omite
            `penaltyType` și `contractualPenaltyRate`. NU presupune
            `LEGAL_PENALIZATOARE` și NU deduce o rată din context, nici din dobânda legală.
            Câmpul gol = aplicarea valorii implicite din formular (aleasă de avocat).
        FRAGMENT;
    }

    private static function outputRules(): string
    {
        return 'FORMAT: returnează DOAR obiectul JSON cerut, fără text liber, fără '
            . 'markdown și fără comentarii. Completează doar cheile pe care le poți '
            . 'susține din document și OMITE complet orice cheie pentru care '
            . 'documentul nu conține informația: o cheie lipsă înseamnă „nu apare '
            . 'în document”. Nu trimite valori goale, zero-uri sau text de tip „N/A” '
            . 'în locul unei omisiuni. Nu inventa valori și nu completa din '
            . 'cunoștințe generale despre firmele identificate: singura sursă '
            . 'admisă este documentul atașat. '
            // A citation written with a straight quote closes the JSON string
            // early and the whole answer is lost, so the text fields must not
            // contain one at all.
            . 'GHILIMELE: în valorile de text nu folosi niciodată caracterul " . '
            . 'Dacă citezi un titlu sau o mențiune din document, scrie-l fără '
            . 'ghilimele sau între „ și ”.';
    }

    // ---------- vocabularies ----------

    /**
     * @return list<string>
     */
    public static function legalGroundValues(): array
    {
        return array_map(static fn (LegalGroundCategory $c): string => $c->value, LegalGroundCategory::cases());
    }

    /**
     * @return list<string>
     */
    public static function penaltyTypeValues(): array
    {
        return array_map(static fn (PenaltyType $p): string => $p->value, PenaltyType::cases());
    }

    /**
     * @return list<string>
     */
    public static function personTypeValues(): array
    {
        return array_map(static fn (PersonType $p): string => $p->value, PersonType::cases());
    }

    /**
     * The currencies the claim form accepts. Offering a wider list would
     * produce a prefilled value the lawyer then has to clear by hand.
     *
     * @return list<string>
     */
    public static function currencyValues(): array
    {
        return Step3ClaimData::SUPPORTED_CURRENCIES;
    }

    /**
     * @return list<string>
     */
    public static function documentTypeValues(): array
    {
        return array_map(static fn (DocumentType $t): string => $t->value, self::classifiableTypes());
    }

    // ---------- JSON Schema fragments ----------

    /**
     * Response schema shared by every prompt. Specialised prompts differ in
     * their instructions, not in the shape they return: everything downstream
     * (persistence, the wizard prefill aggregator) reads one payload shape, and
     * a per-type shape would fork that reader per type.
     *
     * @param bool $withClassification include the classification object, which
     *        only the first pass over an unknown document needs
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(bool $withClassification): array
    {
        $properties = [];
        if ($withClassification) {
            $properties['classification'] = self::classificationSchema();
        }
        $properties['creditor'] = self::object(self::partySchema(isCreditor: true));
        $properties['debtor'] = self::object(self::partySchema(isCreditor: false));
        $properties['claim'] = self::object(self::claimSchema());

        return self::object($properties);
    }

    /**
     * @return array<string, mixed>
     */
    private static function classificationSchema(): array
    {
        return self::object(
            [
                'type' => self::enumField(self::documentTypeValues(), 'Tipul documentului, ales din listă'),
                'confidence' => self::numberField('Cât de sigur ești pe tip, între 0 și 1'),
                'subtype' => self::stringField('Nuanță pe care lista nu o acoperă, ex: factură proformă, factură storno'),
                'rationale' => self::stringField('O propoziție cu elementul decisiv'),
            ],
            // A classification without a type or a score is not a
            // classification; the rest of the object is commentary.
            ['type', 'confidence'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function partySchema(bool $isCreditor): array
    {
        $fields = [
            'personType' => self::enumField(self::personTypeValues(), 'Persoană juridică sau persoană fizică'),
            'name' => self::stringField('Denumirea completă, așa cum apare în document'),
            'cui' => self::stringField('Doar cifrele, fără prefixul RO'),
            'isVatPayer' => self::field('boolean', 'true doar dacă documentul arată explicit calitatea de plătitor de TVA'),
            'personalId' => self::stringField('CNP, 13 cifre, doar la persoană fizică'),
            'onrcNumber' => self::stringField('Număr ONRC în forma J40/1234/2025'),
            'address' => self::stringField('Adresa completă, fără județ și localitate duplicate'),
            'county' => self::stringField('Denumirea județului, fără prefixul jud.'),
            'locality' => self::stringField('Localitatea; la București, forma Sector 1 … Sector 6'),
            'email' => self::stringField('Adresa de e-mail, doar dacă apare explicit'),
            'phone' => self::stringField('Format compact: 0XXXXXXXXX sau +40XXXXXXXXX'),
            'iban' => self::stringField('IBAN fără spații: RO plus 22 de caractere'),
        ];
        $fields += $isCreditor
            ? [
                'legalRepresentative' => self::stringField('Reprezentantul legal al creditorului'),
                'bankName' => self::stringField('Banca la care e deschis contul creditorului'),
            ]
            : ['administrator' => self::stringField('Administratorul sau reprezentantul legal al debitorului')];

        $fields['confidencePerField'] = self::confidenceSchema(array_keys($fields));

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private static function claimSchema(): array
    {
        $fields = [
            'amount' => self::numberField('Suma pretinsă, ca număr, fără simbolul monedei'),
            'currency' => self::enumField(self::currencyValues(), 'Moneda creanței; omite câmpul pentru orice monedă în afara listei'),
            'dueDate' => self::dateField('Scadența creanței, format YYYY-MM-DD'),
            'legalGround' => self::enumField(self::legalGroundValues(), 'Natura raportului juridic'),
            'description' => self::stringField('Detalii pe care celelalte câmpuri nu le acoperă'),
            'invoiceNumber' => self::stringField('Seria și numărul facturii, ex: MJ 2024-00123'),
            'invoiceDate' => self::dateField('Data emiterii facturii, format YYYY-MM-DD'),
            'contractNumber' => self::stringField('Numărul contractului invocat'),
            'contractDate' => self::dateField('Data încheierii contractului, format YYYY-MM-DD'),
            'contractReference' => self::stringField('Obiectul contractului, așa cum e denumit în preambul'),
            'penaltyType' => self::enumField(self::penaltyTypeValues(), 'Doar CONTRACTUAL, și doar pentru o clauză explicită cu rată pe zi'),
            'contractualPenaltyRate' => self::numberField('Rata zilnică în procente, ex: 0.1 pentru 0,1% pe zi'),
        ];
        $fields['confidencePerField'] = self::confidenceSchema(array_keys($fields));

        return $fields;
    }

    /**
     * The confidence map has to be spelled out field by field. Structured
     * outputs require `additionalProperties: false` on every object, so a
     * free-form map of field name to score is not expressible. Scores are
     * optional like every other key: a score for a field that was not
     * extracted is dropped when the payload is read, so emitting one is only
     * wasted output.
     *
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    private static function confidenceSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = self::numberField('Scor 0..1 pentru `' . $field . '`, doar dacă acel câmp a fost completat');
        }

        return self::object($properties);
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required keys the model must always emit
     *
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        // Only what the document always carries is required. Requiring a field
        // the source does not contain leaves the model no way out but to invent
        // one, which is the failure this whole prompt is written to avoid.
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * A leaf property.
     *
     * Deliberately not nullable. The provider caps a constrained-decoding
     * schema at 16 parameters carrying a union (`anyOf` or a type array), and
     * an extraction payload has more than forty fields, every one of which may
     * legitimately be absent. Absence is therefore expressed by omitting the
     * key, which is what `required` below leaves room for, and every reader
     * already treats a missing key and a null as the same thing.
     *
     * The description rides along because constrained decoding passes it to
     * the model: it is the closest place to put a per-field format rule. The
     * same rules are repeated in {@see self::fieldFormatRules()} for a model
     * that never sees the schema.
     *
     * @return array<string, mixed>
     */
    private static function field(string $type, string $description): array
    {
        return ['type' => $type, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringField(string $description): array
    {
        return self::field('string', $description);
    }

    /**
     * @return array<string, mixed>
     */
    private static function numberField(string $description): array
    {
        return self::field('number', $description);
    }

    /**
     * A calendar date. The `date` format is part of the supported subset, so
     * on a model with constrained decoding it rules out the free-form spellings
     * a Romanian document uses; the reader rejects anything else regardless.
     *
     * @return array<string, mixed>
     */
    private static function dateField(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    private static function enumField(array $values, string $description): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }
}
