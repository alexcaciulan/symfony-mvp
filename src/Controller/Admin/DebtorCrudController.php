<?php

namespace App\Controller\Admin;

use App\Entity\Debtor;
use App\Enum\PersonType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

class DebtorCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Debtor::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Debitor (firmă)')
            ->setEntityLabelInPlural('Debitori (firme)')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->setSearchFields(['name', 'cui', 'onrcNumber']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // Identity is edited from the lawyer's library, where each change is
            // recorded on every case that names the company.
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('user', 'Avocat');
        yield ChoiceField::new('personType', 'Tip persoană')->setChoices($this->personTypeChoices());
        yield TextField::new('name', 'Denumire');
        yield TextField::new('cui', 'CUI');
        yield TextField::new('onrcNumber', 'Nr. ONRC')->hideOnIndex();
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield TelephoneField::new('phone', 'Telefon')->hideOnIndex();
        yield TextField::new('iban', 'IBAN')->onlyOnDetail();
        yield TextField::new('administrator', 'Administrator')->onlyOnDetail();
        yield TextareaField::new('address', 'Adresă')->onlyOnDetail();
        yield TextField::new('addressCounty', 'Județ')->onlyOnDetail();
        yield TextField::new('addressLocality', 'Localitate')->onlyOnDetail();
        yield TextField::new('cuiKey', 'Cheie CUI')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('personType', 'Tip persoană')->setChoices($this->personTypeChoices()))
            ->add(EntityFilter::new('user', 'Avocat'));
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function personTypeChoices(): array
    {
        $choices = [];
        foreach (PersonType::cases() as $type) {
            $choices[$this->translator->trans($type->label())] = $type->value;
        }
        return $choices;
    }
}
