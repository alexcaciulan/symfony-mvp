<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction\Prompt;

use App\Enum\DocumentType;
use App\Service\Extraction\Prompt\ExtractionPromptRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Wiring test for the `app.extraction_prompt` tag. A prompt class that is not
 * collected fails silently: the registry falls back to the generic prompt and
 * the document is simply read with weaker instructions, with nothing in the
 * logs to say so.
 */
final class ExtractionPromptDiTest extends KernelTestCase
{
    public function testEveryPromptIsCollectedByTheContainer(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get('test.public.extraction_prompt_registry');
        $this->assertInstanceOf(ExtractionPromptRegistry::class, $registry);

        $keys = [];
        foreach ([
            null,
            DocumentType::FACTURA,
            DocumentType::CONTRACT,
            DocumentType::EXTRAS_CONT,
            DocumentType::SOMATIE_ANTERIOARA,
            DocumentType::CONFIRMARE_SOLD,
        ] as $type) {
            $keys[] = $registry->forType($type)->key();
        }

        $this->assertSame(
            ['generic', 'invoice', 'contract', 'bank_statement', 'prior_notice', 'acknowledgement'],
            $keys,
        );
    }
}
