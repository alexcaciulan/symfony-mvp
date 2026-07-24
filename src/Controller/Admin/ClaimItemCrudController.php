<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ClaimItem;
use App\Enum\ClaimItemKind;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only back-office view of claim positions. Since positions are the source
 * of truth for a case (LegalCase scalars are denormalized from them), the
 * back-office needs a way to inspect what each case actually rests on.
 */
class ClaimItemCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ClaimItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Poziție creanță')
            ->setEntityLabelInPlural('Poziții creanță')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['documentNumber', 'causeReference']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('legalCase', 'Dosar');
        yield ChoiceField::new('kind', 'Tip')->setChoices($this->kindChoices());
        yield TextField::new('documentNumber', 'Nr. document');
        yield DateField::new('documentDate', 'Data document')->onlyOnDetail();
        yield DateField::new('dueDate', 'Scadență');
        // Amount is in the position's own currency; RON is the converted figure.
        yield MoneyField::new('amount', 'Sumă')
            ->setCurrencyPropertyPath('currency')
            ->setStoredAsCents(false);
        yield MoneyField::new('amountRon', 'Sumă RON')
            ->setCurrency('RON')
            ->setStoredAsCents(false);
        yield BooleanField::new('confirmedByLawyer', 'Confirmat')->renderAsSwitch(false);
        yield BooleanField::new('excludedByLawyer', 'Exclus')->renderAsSwitch(false);
        yield BooleanField::new('needsManualFx', 'Necesită curs manual')->renderAsSwitch(false)->onlyOnDetail();
        yield TextField::new('causeReference', 'Cauză')->onlyOnDetail();
        yield TextField::new('currency', 'Monedă')->onlyOnDetail();
        yield TextField::new('exchangeRate', 'Curs')->onlyOnDetail();
        yield DateField::new('exchangeRateDate', 'Data curs')->onlyOnDetail();
        yield MoneyField::new('paidAmount', 'Achitat')
            ->setCurrencyPropertyPath('currency')
            ->setStoredAsCents(false)
            ->onlyOnDetail();
        yield AssociationField::new('sourceDocument', 'Document sursă')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('kind', 'Tip')->setChoices($this->kindChoices()))
            ->add(BooleanFilter::new('confirmedByLawyer', 'Confirmat'))
            ->add(BooleanFilter::new('excludedByLawyer', 'Exclus'))
            ->add(BooleanFilter::new('needsManualFx', 'Necesită curs manual'))
            ->add(EntityFilter::new('legalCase', 'Dosar'));
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function kindChoices(): array
    {
        $choices = [];
        foreach (ClaimItemKind::cases() as $kind) {
            $choices[$this->translator->trans($kind->label())] = $kind->value;
        }
        return $choices;
    }
}
