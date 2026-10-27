<?php

declare(strict_types=1);

namespace App\Controller\Admin\Filter;

use App\Entity\County;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\EntityFilterType;

/** Filters an entity with a `court` association by the court's county. */
final class CourtCountyFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'courtCounty', ?string $label = 'Județ'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(EntityFilterType::class)
            ->setFormTypeOption('value_type_options.class', County::class)
            ->setFormTypeOption('value_type_options.choice_label', 'name')
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $value = $filterDataDto->getValue();
        if ($value === null) {
            return;
        }
        $court = CourtJoin::alias($queryBuilder, $filterDataDto->getEntityAlias());
        $parameter = $filterDataDto->getParameterName();
        $queryBuilder
            ->andWhere(sprintf('%s.county %s :%s', $court, $filterDataDto->getComparison(), $parameter))
            ->setParameter($parameter, $value);
    }
}
