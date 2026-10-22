<?php

namespace App\Controller\Admin;

use App\Entity\Court;
use App\Enum\CourtType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class CourtCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Court::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Instanță')
            ->setEntityLabelInPlural('Instanțe')
            ->setDefaultSort(['county.name' => 'ASC', 'name' => 'ASC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['name', 'county.name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nume');
        yield AssociationField::new('county', 'Județ');
        yield ChoiceField::new('type', 'Tip')
            ->setChoices([
                'Judecătorie' => CourtType::JUDECATORIE,
                'Tribunal' => CourtType::TRIBUNAL,
                'Tribunal specializat' => CourtType::TRIBUNAL_SPECIALIZAT,
            ]);
        yield TextField::new('address', 'Adresă')->hideOnIndex();
        yield TelephoneField::new('phone', 'Telefon')->hideOnIndex();
        yield BooleanField::new('active', 'Activ');
        yield IntegerField::new('portalSiteId', 'ID pagină portal')->hideOnIndex()->setRequired(false);
        // Read-only here: addresses change only through their own admin actions.
        yield Field::new('emailAddresses', 'Adrese e-mail')
            ->onlyOnDetail()
            ->setTemplatePath('admin/court/_email_addresses.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('county', 'Județ'))
            ->add(ChoiceFilter::new('type', 'Tip')->setChoices([
                'Judecătorie' => 'judecatorie',
                'Tribunal' => 'tribunal',
                'Tribunal specializat' => 'tribunal_specializat',
            ]))
            ->add(BooleanFilter::new('active', 'Activ'));
    }
}
