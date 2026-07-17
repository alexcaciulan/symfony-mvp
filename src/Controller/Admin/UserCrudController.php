<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\Form\FormBuilderInterface;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    // Opt into the `admin` validation group so the email UniqueEntity constraint runs here
    // (it is intentionally off in the Default group used by public registration).
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formOptions->set('validation_groups', ['Default', 'admin']);

        return parent::createNewFormBuilder($entityDto, $formOptions, $context);
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $formOptions->set('validation_groups', ['Default', 'admin']);

        return parent::createEditFormBuilder($entityDto, $formOptions, $context);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilizator')
            ->setEntityLabelInPlural('Utilizatori')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield EmailField::new('email', 'Email');
        yield TextField::new('firstName', 'Prenume');
        yield TextField::new('lastName', 'Nume');
        yield ChoiceField::new('roles', 'Roluri')
            ->setChoices([
                'Utilizator' => 'ROLE_USER',
                'Administrator' => 'ROLE_ADMIN',
            ])
            ->allowMultipleChoices()
            ->renderExpanded();
        yield BooleanField::new('isVerified', 'Verificat');
        yield TextField::new('password', 'Parolă')
            ->onlyOnForms()
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->setHelp($pageName === Crud::PAGE_EDIT ? 'Lasă gol pentru a păstra parola curentă.' : '');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('email', 'Email'))
            ->add(TextFilter::new('firstName', 'Prenume'))
            ->add(TextFilter::new('lastName', 'Nume'))
            ->add(BooleanFilter::new('isVerified', 'Verificat'));
    }
}
