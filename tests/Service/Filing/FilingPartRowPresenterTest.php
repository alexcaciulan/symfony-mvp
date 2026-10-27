<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\OpisPlacement;
use App\Enum\SignatureStatus;
use App\Service\Filing\FilingPartRow;
use App\Service\Filing\FilingPartRowPresenter;
use PHPUnit\Framework\TestCase;

final class FilingPartRowPresenterTest extends TestCase
{
    private FilingPartRowPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new FilingPartRowPresenter();
    }

    public function testEachSignatureStateHasItsRowState(): void
    {
        $missing = self::part();
        $matched = self::part();
        $matched->recordSignedUpload('s/m.pdf', str_repeat('m', 64), 10, new User(), SignatureStatus::PRESENT_MATCHED, []);
        $awaiting = self::part();
        $awaiting->recordSignedUpload('s/a.pdf', str_repeat('a', 64), 10, new User(), SignatureStatus::PRESENT_PROBABLE, []);
        $confirmed = self::part();
        $confirmed->recordSignedUpload('s/c.pdf', str_repeat('c', 64), 10, new User(), SignatureStatus::PRESENT_PROBABLE, []);
        $confirmed->confirmProbableMatch(new User());

        self::assertSame(FilingPartRow::STATE_MISSING, $this->presenter->row($missing, true)->state);
        self::assertSame(FilingPartRow::STATE_MATCHED, $this->presenter->row($matched, true)->state);
        self::assertSame(FilingPartRow::STATE_CONFIRMED, $this->presenter->row($confirmed, true)->state);
        $row = $this->presenter->row($awaiting, true);
        self::assertSame(FilingPartRow::STATE_AWAITING, $row->state);
        self::assertTrue($row->panelOpen, 'The observations are read before the confirmation.');
        self::assertFalse($this->presenter->row($matched, true)->panelOpen);
    }

    public function testARefusalMarksAnUnsignedRowAndKeepsAnAcceptedOneAsItWas(): void
    {
        $unsigned = self::part();
        $unsigned->recordRefusal('filing.signature.refused.mismatch');
        $accepted = self::part();
        $accepted->recordSignedUpload('s/m.pdf', str_repeat('m', 64), 10, new User(), SignatureStatus::PRESENT_MATCHED, []);
        $accepted->recordRefusal('filing.signature.refused.not_signed');

        $refused = $this->presenter->row($unsigned, true);
        self::assertSame(FilingPartRow::STATE_REFUSED, $refused->state);
        self::assertSame('mismatch', $refused->refusal['slug']);
        self::assertFalse($refused->refusal['keptPrevious']);
        self::assertFalse($refused->refusal['overBudget']);

        $kept = $this->presenter->row($accepted, true);
        self::assertSame(FilingPartRow::STATE_REFUSED_KEPT_PREVIOUS, $kept->state);
        self::assertTrue($kept->refusal['keptPrevious']);
    }

    public function testAnOverBudgetRefusalCarriesItsExcessAndWhetherTheAnnexesCanBeSplitAgain(): void
    {
        $annex = self::part(FilingPartRole::ANNEX_BUNDLE);
        $annex->recordRefusal('filing.upload.over_budget.text', ['excessBytes' => 2 * 1048576, 'rebuildable' => true]);
        $petition = self::part();
        $petition->recordRefusal('filing.upload.error.first_email_too_large', ['excessBytes' => 1048576, 'rebuildable' => false]);

        $rebuildable = $this->presenter->row($annex, true)->refusal;
        self::assertTrue($rebuildable['overBudget']);
        self::assertTrue($rebuildable['rebuildable']);
        self::assertSame('over_budget', $rebuildable['slug']);
        self::assertSame(2 * 1048576, $rebuildable['excessBytes']);
        self::assertSame(['%excess%' => '2,00'], $rebuildable['params']);

        $fixed = $this->presenter->row($petition, true)->refusal;
        self::assertTrue($fixed['overBudget']);
        self::assertFalse($fixed['rebuildable']);
        self::assertSame('first_email_too_large', $fixed['slug']);
    }

    public function testNoRefusalIsShownOnceUploadsAreClosed(): void
    {
        $part = self::part();
        $part->recordRefusal('filing.signature.refused.damaged');

        $row = $this->presenter->row($part, false);

        self::assertNull($row->refusal);
        self::assertSame(FilingPartRow::STATE_MISSING, $row->state);
    }

    public function testObservationsListTheNotesThenTheWarningsInFullWithTheDigitsNoteStrong(): void
    {
        $part = self::part();
        $part->recordSignedUpload('s/a.pdf', str_repeat('a', 64), 10, new User(), SignatureStatus::PRESENT_PROBABLE, [
            'warnings' => ['filing.signature.warning.cn_differs', 'filing.signature.warning.legacy_sub_filter'],
            'matchNotes' => [
                ['key' => 'filing.signature.note.text_added', 'params' => ['%page%' => 4, '%count%' => 3]],
                ['key' => 'filing.signature.note.text_added_with_digits', 'params' => ['%page%' => 4]],
                ['key' => 'filing.signature.note.pages_appended', 'params' => ['%count%' => 1]],
                ['key' => 'filing.signature.note.part_id_missing', 'params' => []],
            ],
        ]);

        $row = $this->presenter->row($part, true);

        self::assertSame(6, $row->observationCount());
        self::assertSame([
            'filing.signature.note.text_added',
            'filing.signature.note.text_added_with_digits',
            'filing.signature.note.pages_appended',
            'filing.signature.note.part_id_missing',
            'filing.signature.warning.cn_differs',
            'filing.signature.warning.legacy_sub_filter',
        ], array_column($row->observations, 'key'));
        self::assertSame([false, true, false, false, false, false], array_column($row->observations, 'strong'));
        self::assertSame(['note', 'note', 'note', 'note', 'warning', 'warning'], array_column($row->observations, 'kind'));
        self::assertSame('filing.signature.short.text_added_with_digits', $row->observations[1]['shortKey']);
        self::assertSame(['%page%' => 4], $row->observations[1]['params']);
        self::assertSame('cn_differs', $row->observations[4]['slug']);
        self::assertSame([], $row->observations[4]['params']);
    }

    public function testTheCardFoldsOnlyForASignedPackageWithoutAVisibleRefusal(): void
    {
        $package = new FilingPackage(new LegalCase(), 1, OpisPlacement::MERGED, new User());
        $petition = self::part();
        $annex = self::part(FilingPartRole::ANNEX_BUNDLE);
        $package->addPart($petition)->addPart($annex);
        $petition->recordSignedUpload('s/p.pdf', str_repeat('p', 64), 10, new User(), SignatureStatus::PRESENT_MATCHED, []);

        $package->setStatus(FilingPackageStatus::AWAITING_SIGNATURES);
        $rows = $this->presenter->present($package, true);
        self::assertFalse($rows->collapsed);
        self::assertTrue($rows->anySignature);
        self::assertCount(2, $rows->rows);

        $annex->recordSignedUpload('s/x.pdf', str_repeat('x', 64), 10, new User(), SignatureStatus::PRESENT_MATCHED, []);
        $package->setStatus(FilingPackageStatus::SIGNED);
        self::assertTrue($this->presenter->present($package, true)->collapsed);

        $annex->recordRefusal('filing.signature.refused.not_signed');
        self::assertFalse($this->presenter->present($package, true)->collapsed, 'A refused replacement reopens the list.');
        self::assertTrue($this->presenter->present($package, false)->collapsed, 'Closed uploads show no refusal.');
    }

    public function testNoSignatureMeansNoValidityNoticeOnTheCard(): void
    {
        $package = new FilingPackage(new LegalCase(), 1, OpisPlacement::MERGED, new User());
        $package->addPart(self::part());

        self::assertFalse($this->presenter->present($package, true)->anySignature);
    }

    private static function part(FilingPartRole $role = FilingPartRole::PETITION): FilingPackagePart
    {
        $token = bin2hex(random_bytes(8));

        return new FilingPackagePart($role, 1, 'Titlu', 2, 'unsigned/' . $token . '.pdf', str_repeat('0', 64), 1000, $token);
    }
}
