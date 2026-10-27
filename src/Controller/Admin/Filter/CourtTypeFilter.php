<?php

declare(strict_types=1);

namespace App\Controller\Admin\Filter;

use App\Enum\CourtType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;

/** Filters an entity with a `court` association by the court's type. */
final class CourtTypeFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'courtType', ?string $label = 'Tip instanță'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options.choices', [
                'Judecătorie' => CourtType::JUDECATORIE->value,
                'Tribunal' => CourtType::TRIBUNAL->value,
                'Tribunal specializat' => CourtType::TRIBUNAL_SPECIALIZAT->value,
            ])
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $value = $filterDataDto->getValue();
        if ($value === null || $value === []) {
            return;
        }
        $court = CourtJoin::alias($queryBuilder, $filterDataDto->getEntityAlias());
        $parameter = $filterDataDto->getParameterName();
        $queryBuilder
            ->andWhere(sprintf('%s.type %s (:%s)', $court, $filterDataDto->getComparison(), $parameter))
            ->setParameter($parameter, $value);
    }
}
