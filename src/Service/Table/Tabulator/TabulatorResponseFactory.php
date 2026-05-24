<?php

declare(strict_types=1);

namespace App\Service\Table\Tabulator;

use App\Service\Table\TableResult;

/**
 * Tabulator-specific adapter (outbound). Shapes the library-agnostic
 * {@see TableResult} into the JSON Tabulator's remote pagination expects
 * (`last_page` + `data`). Paired with {@see TabulatorRequestParser}.
 */
final class TabulatorResponseFactory
{
    /**
     * @return array<string, mixed>
     */
    public function format(TableResult $result): array
    {
        return [
            'last_page' => $result->lastPage(),
            // `last_row` is the key Tabulator reads for the exact total; without
            // it the counter estimates total as last_page * pageSize.
            'last_row' => $result->total,
            'data' => $result->rows,
        ];
    }
}
