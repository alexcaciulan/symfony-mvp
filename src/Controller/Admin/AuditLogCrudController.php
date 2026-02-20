<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class AuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Jurnal audit')
            ->setEntityLabelInPlural('Jurnal audit')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['action', 'entityType', 'entityId']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('user', 'Utilizator');
        yield TextField::new('action', 'Acțiune');
        yield TextField::new('entityType', 'Tip entitate');
        yield TextField::new('entityId', 'ID entitate');
        yield TextField::new('ipAddress', 'IP')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Data');

        // Detail-only: show JSON data
        yield CodeEditorField::new('oldDataJson', 'Date vechi')
            ->onlyOnDetail()
            ->setLanguage('json');
        yield CodeEditorField::new('newDataJson', 'Date noi')
            ->onlyOnDetail()
            ->setLanguage('json');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user', 'Utilizator'))
            ->add(TextFilter::new('action', 'Acțiune'))
            ->add(TextFilter::new('entityType', 'Tip entitate'))
            ->add(DateTimeFilter::new('createdAt', 'Data'));
    }
}
