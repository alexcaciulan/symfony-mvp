<?php

declare(strict_types=1);

namespace App\Service\Table\Tabulator;

use App\Service\Table\TableDataService;
use App\Service\Table\TableQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tabulator-specific adapter (inbound). Maps Tabulator's remote query string
 * (`page`, `size`, `sort[0][field|dir]`, `filter[i][field|value]`) into the
 * library-agnostic {@see TableQuery}.
 *
 * To swap the grid library, rewrite only this class + {@see TabulatorResponseFactory}
 * + the `tabulator` Stimulus controller. The engine and table definitions stay.
 */
final class TabulatorRequestParser
{
    public function parse(Request $request): TableQuery
    {
        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = $request->query->getInt('size', TableDataService::DEFAULT_PAGE_SIZE);

        $sortField = null;
        $sortDir = 'ASC';
        $sorters = $request->query->all('sort');
        if (isset($sorters[0]) && \is_array($sorters[0]) && isset($sorters[0]['field'])) {
            $sortField = (string) $sorters[0]['field'];
            $sortDir = strtoupper((string) ($sorters[0]['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        }

        $filters = [];
        foreach ($request->query->all('filter') as $filter) {
            if (\is_array($filter) && isset($filter['field'])) {
                $filters[(string) $filter['field']] = $filter['value'] ?? '';
            }
        }

        return new TableQuery($page, $pageSize, $sortField, $sortDir, $filters);
    }
}
