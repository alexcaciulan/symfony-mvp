<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Extraction\Prompt\AcknowledgementPrompt;
use App\Service\Extraction\Prompt\BankStatementPrompt;
use App\Service\Extraction\Prompt\ContractPrompt;
use App\Service\Extraction\Prompt\ExtractionPromptRegistry;
use App\Service\Extraction\Prompt\GenericDocumentPrompt;
use App\Service\Extraction\Prompt\InvoicePrompt;
use App\Service\Extraction\Prompt\PriorNoticePrompt;

/**
 * Builds the prompt registry the way the container does, for unit tests that
 * construct the vision strategy by hand. Using the real prompts rather than a
 * double keeps those tests honest about what actually goes over the wire.
 */
final class ExtractionPrompts
{
    public static function registry(): ExtractionPromptRegistry
    {
        $generic = new GenericDocumentPrompt();

        return new ExtractionPromptRegistry(
            [
                $generic,
                new InvoicePrompt(),
                new ContractPrompt(),
                new BankStatementPrompt(),
                new PriorNoticePrompt(),
                new AcknowledgementPrompt(),
            ],
            $generic,
        );
    }
}
