<?php

/**
 * One-shot generator for the realistic-PDF fixtures committed under
 * tests/fixtures/extraction/. Run via Docker:
 *
 *   docker compose exec php php tests/fixtures/extraction/generate-fixtures.php
 *
 * Re-run only when the fixture content needs to change. The resulting *.pdf
 * files are committed to git so that PdfParserExtractionStrategyTest reads
 * deterministic, layout-rich documents — not the simple HTML used by the
 * unit-style tests in setUpBeforeClass().
 *
 * Fictive identifiers used (no real persons / companies):
 *   - CUI 15193236 (creditor side) — checksum-valid per ANAF.
 *   - CUI 14186770 (debtor side)   — checksum-valid per ANAF.
 *   - CNP 1980715221232            — checksum-valid per OUG 97/2005.
 *   - IBAN RO49AAAA1B31007593840000 — ISO 7064 reference example.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const FIXTURES_DIR = __DIR__;

function renderPdf(string $filename, string $html, string $orientation = 'portrait'): void
{
    $options = new Options();
    $options->set('isPhpEnabled', false);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();

    file_put_contents(FIXTURES_DIR . '/' . $filename, $dompdf->output());
    echo "  ✓ {$filename}\n";
}

echo "Generating realistic extraction fixtures...\n";

// ---------- Fixture 1: factura ERP-style cu tabel line items ----------

$invoiceHtml = <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
  .header { border-bottom: 2px solid #0a4f8b; padding-bottom: 8px; margin-bottom: 16px; }
  .header h1 { color: #0a4f8b; margin: 0 0 4px 0; font-size: 18pt; }
  .meta { font-size: 9pt; color: #555; }
  .parties { display: table; width: 100%; margin: 14px 0; border-collapse: collapse; }
  .parties .col { display: table-cell; width: 50%; padding: 8px; border: 1px solid #ccc; vertical-align: top; }
  .parties h3 { margin: 0 0 4px 0; font-size: 10pt; color: #0a4f8b; }
  table.lines { width: 100%; border-collapse: collapse; margin: 12px 0; }
  table.lines th, table.lines td { border: 1px solid #ccc; padding: 4px 6px; font-size: 9pt; }
  table.lines th { background: #e8f0f8; }
  table.lines td.num { text-align: right; }
  .totals { width: 50%; margin-left: auto; margin-top: 12px; }
  .totals td { padding: 3px 6px; }
  .totals tr.grand { font-weight: bold; border-top: 2px solid #0a4f8b; }
  .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #ccc; font-size: 9pt; color: #555; }
</style>
</head><body>

<div class="header">
  <h1>Alpha Servicii Comerciale SRL</h1>
  <div class="meta">
    Sediu: Cluj-Napoca, str. Memorandumului nr. 28, jud. Cluj &nbsp;|&nbsp;
    Telefon: +40 264 555 0001 &nbsp;|&nbsp; Email: facturi@alpha-servicii.ro
  </div>
</div>

<h2 style="text-align:center; margin: 8px 0; color: #0a4f8b;">FACTURA FISCALA</h2>
<p style="text-align:center; margin: 0 0 16px 0; font-size: 10pt;">
  Seria <b>ALP</b> nr. <b>2026-0042</b> &nbsp;|&nbsp;
  Data emiterii: <b>01.05.2026</b> &nbsp;|&nbsp;
  Scadenta: <b>31.05.2026</b>
</p>

<div class="parties">
  <div class="col">
    <h3>Furnizor</h3>
    Denumire: <b>Alpha Servicii Comerciale SRL</b><br>
    CUI: <b>RO15193236</b><br>
    Reg. com.: J12/1234/2018<br>
    Adresa: Cluj-Napoca, str. Memorandumului nr. 28<br>
    IBAN: <b>RO49AAAA1B31007593840000</b><br>
    Banca: Banca Transilvania
  </div>
  <div class="col">
    <h3>Client (cumparator)</h3>
    Denumire: <b>Beta Distribution SRL</b><br>
    CUI: <b>RO14186770</b><br>
    Reg. com.: J40/9876/2020<br>
    Adresa: Bucuresti, sector 3, bd. Decebal nr. 17<br>
    Persoana de contact: Ion Popescu
  </div>
</div>

<table class="lines">
  <thead><tr>
    <th>#</th><th>Descriere</th><th class="num">Cant.</th><th class="num">Pret unit.</th>
    <th class="num">Valoare</th><th class="num">TVA 19%</th><th class="num">Total</th>
  </tr></thead>
  <tbody>
    <tr><td>1</td><td>Consultanta IT - implementare CRM (mai 2026)</td>
        <td class="num">40</td><td class="num">85,00</td>
        <td class="num">3.400,00</td><td class="num">646,00</td><td class="num">4.046,00</td></tr>
    <tr><td>2</td><td>Mentenanta lunara servere productie</td>
        <td class="num">1</td><td class="num">1.200,00</td>
        <td class="num">1.200,00</td><td class="num">228,00</td><td class="num">1.428,00</td></tr>
    <tr><td>3</td><td>Licente software (per pachet)</td>
        <td class="num">3</td><td class="num">150,00</td>
        <td class="num">450,00</td><td class="num">85,50</td><td class="num">535,50</td></tr>
    <tr><td>4</td><td>Ore suport tehnic out-of-hours</td>
        <td class="num">4</td><td class="num">120,00</td>
        <td class="num">480,00</td><td class="num">91,20</td><td class="num">571,20</td></tr>
    <tr><td>5</td><td>Audit securitate retea (raport)</td>
        <td class="num">1</td><td class="num">800,00</td>
        <td class="num">800,00</td><td class="num">152,00</td><td class="num">952,00</td></tr>
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal (fara TVA):</td><td class="num">6.330,00 RON</td></tr>
  <tr><td>TVA 19%:</td><td class="num">1.202,70 RON</td></tr>
  <tr class="grand"><td>Total de plata:</td><td class="num"><b>7.532,70 RON</b></td></tr>
</table>

<div class="footer">
  <b>Termen de plata:</b> 31.05.2026 (30 zile de la emitere). Pentru intarziere se aplica
  dobanda penalizatoare conform art. 3 alin. 2&sup1; din OG 13/2011.<br>
  Plata se va efectua prin transfer bancar in contul IBAN RO49AAAA1B31007593840000 deschis la Banca Transilvania.<br>
  Factura emisa electronic conform Legii 227/2015.
</div>

</body></html>
HTML;

renderPdf('invoice-realistic.pdf', $invoiceHtml);

// ---------- Fixture 2: contract multipagina B2B ----------

$contractHtml = <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  @page { margin: 2cm 2cm 2.5cm 2cm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #222; line-height: 1.4; }
  h1 { text-align: center; font-size: 14pt; margin: 0 0 4px 0; }
  h2 { font-size: 12pt; margin: 14px 0 6px 0; }
  .subtitle { text-align: center; font-size: 10pt; color: #555; margin-bottom: 18px; }
  .article { margin: 10px 0; }
  .article-head { font-weight: bold; }
  table.signatures { width: 100%; margin-top: 30px; }
  table.signatures td { width: 50%; padding-top: 40px; border-top: 1px solid #333; vertical-align: top; }
</style>
</head><body>

<h1>CONTRACT DE PRESTARI SERVICII</h1>
<p class="subtitle">Nr. 158 / 12.04.2026</p>

<h2>I. Partile contractante</h2>

<div class="article">
  <span class="article-head">Furnizor:</span>
  <b>Alpha Servicii Comerciale SRL</b>, persoana juridica romana,
  cu sediul in Cluj-Napoca, str. Memorandumului nr. 28, judet Cluj,
  inregistrata la Registrul Comertului sub nr. J12/1234/2018,
  avand cod unic de inregistrare <b>RO15193236</b>,
  cont bancar deschis la Banca Transilvania <b>IBAN RO49AAAA1B31007593840000</b>,
  reprezentata legal de <b>Maria Ionescu</b>, in calitate de administrator,
  denumita in continuare "Prestator" sau "Furnizor".
</div>

<div class="article">
  <span class="article-head">Client (Beneficiar):</span>
  <b>Beta Distribution SRL</b>, persoana juridica romana,
  cu sediul in Bucuresti, sector 3, bd. Decebal nr. 17,
  inregistrata la Registrul Comertului sub nr. J40/9876/2020,
  avand cod unic de inregistrare <b>RO14186770</b>,
  reprezentata legal de <b>Ion Popescu</b>, in calitate de director general,
  denumita in continuare "Beneficiar" sau "Client".
</div>

<h2>II. Obiectul contractului</h2>
<div class="article">
  <span class="article-head">Art. 1.</span>
  Prestatorul se obliga sa furnizeze Beneficiarului servicii de consultanta IT,
  mentenanta sisteme informatice si suport tehnic, conform Anexei nr. 1, parte integranta a prezentului contract.
</div>

<h2>III. Pretul si modalitatea de plata</h2>
<div class="article">
  <span class="article-head">Art. 2.</span>
  Pretul total al serviciilor este de <b>7.532,70 RON</b> (saptemii cincisutetreizecidoi 70/100 lei),
  TVA inclus.
</div>
<div class="article">
  <span class="article-head">Art. 3.</span>
  Plata se va efectua de catre Beneficiar in contul Furnizorului mentionat la Art. I,
  in termen de <b>30 zile</b> de la emiterea facturii fiscale.
  Termen de plata: <b>31.05.2026</b>.
</div>

<div style="page-break-before: always;"></div>

<h2>IV. Termene si livrare</h2>
<div class="article">
  <span class="article-head">Art. 4.</span>
  Serviciile vor fi prestate in perioada 01.05.2026 - 31.05.2026.
  La finalul perioadei, Prestatorul va emite factura fiscala impreuna cu raportul de activitate.
</div>

<h2>V. Sanctiuni in caz de neexecutare</h2>
<div class="article">
  <span class="article-head">Art. 5.</span>
  In caz de intarziere a platii peste termenul scadentei (31.05.2026), Beneficiarul datoreaza
  dobanda penalizatoare la nivelul ratei de referinta a Bancii Nationale a Romaniei
  plus 8 puncte procentuale, conform OG 13/2011 art. 3 alin. 2&sup1; (introdus prin Legea 72/2013 art. 20).
</div>
<div class="article">
  <span class="article-head">Art. 6.</span>
  Prezentul contract constituie titlu executoriu pentru obligatia de plata, in conditiile
  art. 1014 si urm. din Codul de procedura civila (procedura ordonantei de plata).
</div>

<h2>VI. Dispozitii finale</h2>
<div class="article">
  <span class="article-head">Art. 7.</span>
  Litigiile decurgand din interpretarea sau executarea prezentului contract sunt de
  competenta instantelor de la sediul Furnizorului, conform art. 107 si 113 din CPC.
</div>
<div class="article">
  <span class="article-head">Art. 8.</span>
  Prezentul contract a fost incheiat astazi, 12.04.2026, in doua exemplare originale,
  cate unul pentru fiecare parte.
</div>

<table class="signatures">
  <tr>
    <td>
      <b>Furnizor:</b><br>
      Alpha Servicii Comerciale SRL<br>
      Prin Maria Ionescu, administrator<br><br>
      Semnatura: ........................<br>
      Stampila: .........................
    </td>
    <td>
      <b>Beneficiar:</b><br>
      Beta Distribution SRL<br>
      Prin Ion Popescu, director general<br><br>
      Semnatura: ........................<br>
      Stampila: .........................
    </td>
  </tr>
</table>

</body></html>
HTML;

renderPdf('contract-multipage.pdf', $contractHtml);

// ---------- Fixture 3: contract de imprumut PF cu CNP ----------

$loanHtml = <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; line-height: 1.5; }
  h1 { text-align: center; font-size: 14pt; }
  .article { margin: 10px 0; text-align: justify; }
  .article-head { font-weight: bold; }
</style>
</head><body>

<h1>CONTRACT DE IMPRUMUT</h1>
<p style="text-align:center; margin: 0; font-size: 10pt;">incheiat astazi, 15.03.2026</p>

<h2>I. Partile</h2>

<div class="article">
  <span class="article-head">Imprumutator:</span>
  <b>Alpha Servicii Comerciale SRL</b>, persoana juridica,
  cu sediul in Cluj-Napoca, str. Memorandumului nr. 28,
  cod unic de inregistrare <b>RO15193236</b>,
  cont bancar <b>IBAN RO49AAAA1B31007593840000</b> la Banca Transilvania,
  reprezentata de Maria Ionescu, denumita in continuare "Imprumutator".
</div>

<div class="article">
  <span class="article-head">Imprumutat:</span>
  domnul <b>Andrei Dumitrescu</b>, persoana fizica,
  domiciliat in Bucuresti, sector 3, str. Episcop Radu nr. 12, ap. 5,
  identificat prin CI seria RX nr. 123456,
  CNP <b>1980715221232</b>,
  denumit in continuare "Imprumutat".
</div>

<h2>II. Obiectul contractului</h2>
<div class="article">
  <span class="article-head">Art. 1.</span>
  Imprumutatorul acorda Imprumutatului suma de <b>15.000,00 RON</b>
  (cincisprezecemii lei), cu titlu de imprumut, conform art. 2158 si urm. din Codul Civil.
</div>

<h2>III. Restituire si scadenta</h2>
<div class="article">
  <span class="article-head">Art. 2.</span>
  Imprumutatul se obliga sa restituie suma imprumutata pana la data de
  <b>15.09.2026</b>. Termen plata final: 15.09.2026.
</div>

<div class="article">
  <span class="article-head">Art. 3.</span>
  In caz de neexecutare la scadenta, prezentul contract constituie titlu pentru solicitarea
  ordonantei de plata in conditiile art. 1014 si urm. CPC.
</div>

</body></html>
HTML;

renderPdf('loan-individual.pdf', $loanHtml);

echo "Done. Fixtures saved at: " . FIXTURES_DIR . "\n";
