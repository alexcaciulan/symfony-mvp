<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Table\TableDataService;
use App\Service\Table\TableRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the frontend config for a registered table to Twig. Columns come from
 * the single PHP definition (labels translated here), so a page only needs
 * `<twig:DataTable key="...">`.
 */
final class TableExtension extends AbstractExtension
{
    public function __construct(
        private readonly TableRegistry $registry,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('table_config', $this->tableConfig(...)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tableConfig(string $key): array
    {
        $definition = $this->registry->get($key);

        $columns = [];
        foreach ($definition->getColumns() as $column) {
            $columns[] = [
                'field' => $column->name,
                'title' => $this->translator->trans($column->labelKey),
                'sortable' => $column->sortable,
                'filterable' => $column->filterable,
                'filterType' => $column->filterType,
                'formatter' => $column->formatter,
                'width' => $column->width,
            ];
        }

        return [
            'url' => $this->urlGenerator->generate('api_table_data', ['key' => $key]),
            'columns' => $columns,
            'pageSize' => TableDataService::DEFAULT_PAGE_SIZE,
            'pageSizes' => TableDataService::PAGE_SIZES,
            'placeholder' => $this->translator->trans('component.data_table.empty'),
            'errorMessage' => $this->translator->trans('component.data_table.error'),
            'labels' => [
                'yes' => $this->translator->trans('component.data_table.yes'),
                'no' => $this->translator->trans('component.data_table.no'),
            ],
            'langs' => [
                'pagination' => [
                    'first' => $this->translator->trans('component.data_table.first'),
                    'prev' => $this->translator->trans('component.data_table.prev'),
                    'next' => $this->translator->trans('component.data_table.next'),
                    'last' => $this->translator->trans('component.data_table.last'),
                    'page_size' => $this->translator->trans('component.data_table.page_size'),
                    'counter' => [
                        'showing' => $this->translator->trans('component.data_table.showing'),
                        'of' => $this->translator->trans('component.data_table.of'),
                        'rows' => $this->translator->trans('component.data_table.rows'),
                        'pages' => $this->translator->trans('component.data_table.pages'),
                    ],
                ],
            ],
        ];
    }
}
