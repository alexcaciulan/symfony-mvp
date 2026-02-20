<?php

namespace App\Controller\Admin;

use App\Entity\Court;
use App\Enum\CourtType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

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
            ->setDefaultSort(['county' => 'ASC', 'name' => 'ASC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['name', 'county']);
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
        yield TextField::new('county', 'Județ');
        yield ChoiceField::new('type', 'Tip')
            ->setChoices([
                'Judecătorie' => CourtType::JUDECATORIE,
                'Tribunal' => CourtType::TRIBUNAL,
            ]);
        yield TextField::new('address', 'Adresă')->hideOnIndex();
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield TelephoneField::new('phone', 'Telefon')->hideOnIndex();
        yield BooleanField::new('active', 'Activ');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('county', 'Județ'))
            ->add(ChoiceFilter::new('type', 'Tip')->setChoices([
                'Judecătorie' => 'judecatorie',
                'Tribunal' => 'tribunal',
            ]))
            ->add(BooleanFilter::new('active', 'Activ'));
    }
}
