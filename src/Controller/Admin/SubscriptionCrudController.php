<?php

namespace App\Controller\Admin;

use App\Entity\Subscription;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class SubscriptionCrudController extends AbstractCrudController
{
    /** @var array<string, string> label => stored value */
    private const STATUS_CHOICES = [
        'Activ' => 'active',
        'Anulat' => 'cancelled',
        'Expirat' => 'expired',
    ];

    public static function getEntityFqcn(): string
    {
        return Subscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Abonament')
            ->setEntityLabelInPlural('Abonamente')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('user', 'Utilizator');
        yield AssociationField::new('plan', 'Plan');
        yield ChoiceField::new('status', 'Status')->setChoices(self::STATUS_CHOICES);
        yield DateTimeField::new('currentPeriodStart', 'Început perioadă');
        yield DateTimeField::new('currentPeriodEnd', 'Sfârșit perioadă');
        yield IntegerField::new('casesConsumed', 'Dosare consumate');
        yield TextField::new('externalId', 'ID extern')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::STATUS_CHOICES))
            ->add(EntityFilter::new('user', 'Utilizator'))
            ->add(EntityFilter::new('plan', 'Plan'));
    }
}
