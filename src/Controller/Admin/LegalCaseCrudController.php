<?php

namespace App\Controller\Admin;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

class LegalCaseCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

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
        yield TextField::new('caseNumber', 'Număr dosar');
        yield AssociationField::new('user', 'Avocat')->onlyOnDetail();
        yield AssociationField::new('creditor', 'Creditor');
        yield AssociationField::new('court', 'Instanța');
        // Currency read from the case: a case whose positions all await a manual
        // exchange rate keeps its amount in the original currency, not RON.
        yield MoneyField::new('amount', 'Sumă')
            ->setCurrencyPropertyPath('currency')
            ->setStoredAsCents(false);
        yield ChoiceField::new('status', 'Status')
            ->setChoices($this->statusChoices())
            ->renderAsBadges(self::statusBadgeMap());
        yield DateField::new('dueDate', 'Scadență')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnIndex();

        yield TextField::new('currency', 'Monedă')->onlyOnDetail();
        yield MoneyField::new('calculatedInterest', 'Dobândă calculată')
            ->setCurrency('RON')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
        yield MoneyField::new('stampDuty', 'Taxă timbru')
            ->setCurrency('RON')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
        yield DateField::new('paymentNoticeDate', 'Data somație')->onlyOnDetail();
        yield TextField::new('courtCaseNumber', 'Nr. dosar instanță')->onlyOnDetail();
        yield DateField::new('hearingDate', 'Termen judecată')->onlyOnDetail();
        // The field holds the date the order was pronounced, written by the
        // emite_ordonanta transition. It is now also the fallback anchor of the
        // enforcement limitation, so the label must not suggest it is the date the
        // order became final.
        yield DateField::new('finalRulingDate', 'Data pronunțării')->onlyOnDetail();
        yield TextareaField::new('notes', 'Note')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices($this->statusChoices()))
            ->add(EntityFilter::new('court', 'Instanța'))
            ->add(EntityFilter::new('creditor', 'Creditor'))
            ->add(NumericFilter::new('amount', 'Sumă'))
            ->add(DateTimeFilter::new('createdAt', 'Data creării'));
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $qb->andWhere('entity.deletedAt IS NULL');

        return $qb;
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function statusChoices(): array
    {
        $choices = [];
        foreach (CaseStatus::cases() as $status) {
            $choices[$this->translator->trans($status->label())] = $status->value;
        }
        return $choices;
    }

    /**
     * @return array<string, string> place value => Bootstrap badge color
     */
    private static function statusBadgeMap(): array
    {
        $colorMap = [
            'slate' => 'secondary',
            'amber' => 'warning',
            'sky' => 'info',
            'blue' => 'primary',
            'indigo' => 'primary',
            'violet' => 'primary',
            'orange' => 'warning',
            'emerald' => 'success',
            'teal' => 'info',
            'red' => 'danger',
            'green' => 'success',
            'gray' => 'secondary',
        ];

        $map = [];
        foreach (CaseStatus::cases() as $status) {
            $map[$status->value] = $colorMap[$status->color()] ?? 'secondary';
        }
        return $map;
    }
}
