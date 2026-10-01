<?php

namespace App\Controller\Admin;

use App\Entity\LegalCaseDebtor;
use App\Enum\AnafStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A debtor's part in a case, with what was checked for that case.
 */
class LegalCaseDebtorCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LegalCaseDebtor::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Debitor pe dosar')
            ->setEntityLabelInPlural('Debitori pe dosare')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('legalCase', 'Dosar')->setDisabled();
        yield AssociationField::new('debtor', 'Debitor')->setDisabled();
        yield IntegerField::new('position', 'Poziție')->setDisabled();
        yield ChoiceField::new('anafStatus', 'Stare ANAF')
            ->setChoices($this->anafStatusChoices())
            ->setRequired(false);
        yield DateTimeField::new('anafCheckedAt', 'Verificat ANAF la')->onlyOnDetail();
        yield DateTimeField::new('insolvencyCheckedAt', 'Verificat insolvență la')->onlyOnDetail();
        yield TextareaField::new('bpiVerifiedNote', 'Notă verificare BPI')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('anafStatus', 'Stare ANAF')->setChoices($this->anafStatusChoices()))
            ->add(EntityFilter::new('legalCase', 'Dosar'));
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function anafStatusChoices(): array
    {
        $choices = [];
        foreach (AnafStatus::cases() as $status) {
            $choices[$this->translator->trans($status->label())] = $status->value;
        }

        return $choices;
    }
}
