<?php

namespace App\Controller\Admin;

use App\Entity\Plan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

class PlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Plan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Plan')
            ->setEntityLabelInPlural('Planuri')
            ->setDefaultSort(['priceMonthly' => 'ASC'])
            ->setPaginatorPageSize(20)
            ->setSearchFields(['name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Deleting a plan with active subscriptions would break the FK; manage
        // lifecycle via the isActive flag instead.
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield TextField::new('name', 'Denumire');
        yield MoneyField::new('priceMonthly', 'Preț lunar')
            ->setCurrency('RON')
            ->setStoredAsCents(false);
        yield IntegerField::new('includedCases', 'Dosare incluse');
        yield MoneyField::new('pricePerExtra', 'Preț dosar suplimentar')
            ->setCurrency('RON')
            ->setStoredAsCents(false);
        yield BooleanField::new('isActive', 'Activ');
        yield BooleanField::new('isTrial', 'Plan de probă');
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizat la')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isActive', 'Activ'))
            ->add(NumericFilter::new('priceMonthly', 'Preț lunar'));
    }
}
