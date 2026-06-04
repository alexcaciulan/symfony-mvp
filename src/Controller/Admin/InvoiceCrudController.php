<?php

namespace App\Controller\Admin;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

class InvoiceCrudController extends AbstractCrudController
{
    /** @return array<string, InvoiceStatus> label => enum case */
    private static function statusChoices(): array
    {
        return [
            'În așteptare' => InvoiceStatus::PENDING,
            'Plătită' => InvoiceStatus::PAID,
            'Anulată' => InvoiceStatus::CANCELED,
        ];
    }

    /** @return array<string, InvoiceType> label => enum case */
    private static function typeChoices(): array
    {
        return [
            'Abonament' => InvoiceType::SUBSCRIPTION,
            'Dosar suplimentar' => InvoiceType::CASE_EXTRA,
        ];
    }

    public static function getEntityFqcn(): string
    {
        return Invoice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Factură')
            ->setEntityLabelInPlural('Facturi')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('user', 'Utilizator');
        yield MoneyField::new('amount', 'Sumă')
            ->setCurrency('RON')
            ->setStoredAsCents(false);
        yield ChoiceField::new('type', 'Tip')->setChoices(self::typeChoices());
        yield ChoiceField::new('status', 'Status')->setChoices(self::statusChoices());
        yield AssociationField::new('subscription', 'Abonament')->onlyOnDetail();
        yield AssociationField::new('legalCase', 'Dosar')->onlyOnDetail();
        yield DateTimeField::new('paidAt', 'Plătită la');
        yield TextField::new('externalId', 'ID extern')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Creată la')->onlyOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::statusChoices()))
            ->add(ChoiceFilter::new('type', 'Tip')->setChoices(self::typeChoices()))
            ->add(EntityFilter::new('user', 'Utilizator'))
            ->add(NumericFilter::new('amount', 'Sumă'))
            ->add(DateTimeFilter::new('createdAt', 'Data creării'));
    }
}
