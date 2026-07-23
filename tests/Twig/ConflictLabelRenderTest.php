<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\PrefillConflict;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * A legal-ground conflict must read as the label the rest of the wizard uses,
 * not the enum code the value is stored under, so the lawyer sees what they are
 * choosing between.
 */
final class ConflictLabelRenderTest extends KernelTestCase
{
    public function testLegalGroundConflictShowsTheTranslatedLabelNotTheEnumCode(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);

        $conflict = new PrefillConflict(
            scope: ConflictScope::CLAIM,
            severity: ConflictSeverity::INFO,
            messageKey: 'wizard.conflict.field.legalGround',
            field: 'legalGround',
            options: [
                new ConflictOption(value: 'CONTRACT_PRESTARI_SERVICII', documentId: 1, documentType: DocumentType::CONTRACT, confidence: 0.97),
                new ConflictOption(value: 'FACTURA_ACCEPTATA', documentId: 2, documentType: DocumentType::FACTURA, confidence: 0.85),
            ],
            suggestedIndex: 0,
        );

        $html = $twig->render('case/_prefill_conflicts.html.twig', [
            'prefill_conflicts' => [$conflict],
            'conflicts_form_id' => 'step3-claim-form',
        ]);

        self::assertStringContainsString('Contract de prestări servicii', $html);
        self::assertStringContainsString('Factură acceptată', $html);
        self::assertStringNotContainsString('CONTRACT_PRESTARI_SERVICII', $html);
        self::assertStringNotContainsString('FACTURA_ACCEPTATA', $html);
    }

    public function testPersonTypeConflictShowsTheTranslatedLabelNotTheEnumCode(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);

        $conflict = new PrefillConflict(
            scope: ConflictScope::DEBTOR,
            severity: ConflictSeverity::INFO,
            messageKey: 'wizard.conflict.field.personType',
            field: 'personType',
            options: [
                new ConflictOption(value: 'PJ', documentId: 1, documentType: DocumentType::CONTRACT, confidence: 0.97),
                new ConflictOption(value: 'PF', documentId: 2, documentType: DocumentType::FACTURA, confidence: 0.90),
            ],
            suggestedIndex: 0,
        );

        $html = $twig->render('case/_prefill_conflicts.html.twig', [
            'prefill_conflicts' => [$conflict],
            'conflicts_form_id' => 'step2-debtor-form',
        ]);

        self::assertStringContainsString('Persoană juridică', $html);
        self::assertStringContainsString('Persoană fizică', $html);
        // The bare enum codes must not leak as standalone values.
        self::assertStringNotContainsString('>PJ<', $html);
    }
}
