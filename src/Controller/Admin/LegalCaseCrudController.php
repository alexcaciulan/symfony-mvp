<?php

namespace App\Controller\Admin;

use App\Entity\LegalCase;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

class LegalCaseCrudController extends AbstractCrudController
{
    private const STATUS_LABELS = [
        'draft' => 'Ciornă',
        'pending_payment' => 'În așteptarea plății',
        'paid' => 'Plătit',
        'submitted_to_court' => 'Trimis la instanță',
        'under_review' => 'În analiză',
        'additional_info_requested' => 'Info suplimentare',
        'resolved_accepted' => 'Admis',
        'resolved_rejected' => 'Respins',
        'enforcement' => 'Executare silită',
    ];

    public static function getEntityFqcn(): string
    {
        return LegalCase::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Dosar')
            ->setEntityLabelInPlural('Dosare')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $changeStatus = Action::new('changeStatus', 'Schimbă status', 'fa fa-exchange-alt')
            ->linkToRoute('admin_case_change_status', fn (LegalCase $case) => ['id' => $case->getId()]);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $changeStatus)
            ->add(Crud::PAGE_DETAIL, $changeStatus)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('user', 'Creditor');
        yield TextField::new('claimantName', 'Reclamant')->onlyOnIndex();
        yield TextField::new('firstDefendantName', 'Pârât')->onlyOnIndex();
        yield AssociationField::new('court', 'Instanța');
        yield MoneyField::new('claimAmount', 'Sumă')
            ->setCurrency('RON')
            ->setStoredAsCents(false);
        yield ChoiceField::new('status', 'Status')
            ->setChoices(array_flip(self::STATUS_LABELS))
            ->renderAsBadges([
                'draft' => 'secondary',
                'pending_payment' => 'warning',
                'paid' => 'info',
                'submitted_to_court' => 'primary',
                'under_review' => 'primary',
                'additional_info_requested' => 'warning',
                'resolved_accepted' => 'success',
                'resolved_rejected' => 'danger',
                'enforcement' => 'dark',
            ]);
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnIndex();
        yield DateTimeField::new('submittedAt', 'Depus la')->onlyOnDetail();

        // Detail-only fields
        yield TextField::new('county', 'Județ')->onlyOnDetail();
        yield TextField::new('claimantType', 'Tip reclamant')->onlyOnDetail();
        yield TextareaField::new('claimDescription', 'Descriere creanță')->onlyOnDetail();
        yield TextField::new('legalBasis', 'Temei juridic')->onlyOnDetail();
        yield TextField::new('interestType', 'Tip dobândă')->onlyOnDetail();
        yield TextareaField::new('evidenceDescription', 'Probe')->onlyOnDetail();
        yield MoneyField::new('courtFee', 'Taxă judiciară')
            ->setCurrency('RON')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
        yield MoneyField::new('platformFee', 'Comision platformă')
            ->setCurrency('RON')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
        yield MoneyField::new('totalFee', 'Total taxe')
            ->setCurrency('RON')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(array_flip(self::STATUS_LABELS)))
            ->add(EntityFilter::new('court', 'Instanța'))
            ->add(NumericFilter::new('claimAmount', 'Sumă'))
            ->add(DateTimeFilter::new('createdAt', 'Data creării'));
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $qb->andWhere('entity.deletedAt IS NULL');

        return $qb;
    }
}
