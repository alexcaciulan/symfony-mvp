<?php

namespace App\Controller\Admin;

use App\Entity\CourtPortalEvent;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class CourtPortalEventCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CourtPortalEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Eveniment portal')
            ->setEntityLabelInPlural('Evenimente portal')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['description', 'solutie', 'solutieSumar']);
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
        yield AssociationField::new('legalCase', 'Dosar');
        yield TextField::new('eventType.value', 'Tip eveniment')->onlyOnIndex();
        yield DateField::new('eventDate', 'Data eveniment');
        yield TextField::new('description', 'Descriere');
        yield TextField::new('solutieSumar', 'Soluție (sumar)')->onlyOnDetail();
        yield TextField::new('solutie', 'Soluție (complet)')->onlyOnDetail();
        yield BooleanField::new('notified', 'Notificat')->renderAsSwitch(false);
        yield DateTimeField::new('detectedAt', 'Detectat la');
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnDetail();

        yield CodeEditorField::new('rawDataJson', 'Date brute portal')
            ->onlyOnDetail()
            ->setLanguage('js');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('detectedAt', 'Detectat la'));
    }
}
