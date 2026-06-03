<?php

namespace App\Controller\Admin;

use App\Entity\LegalDeadline;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

class LegalDeadlineCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LegalDeadline::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Termen')
            ->setEntityLabelInPlural('Termene')
            ->setDefaultSort(['deadlineDate' => 'ASC'])
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
        yield AssociationField::new('legalCase', 'Dosar');
        yield ChoiceField::new('type', 'Tip termen')->setChoices($this->typeChoices());
        yield DateField::new('deadlineDate', 'Data limită');
        yield ChoiceField::new('priority', 'Prioritate')
            ->setChoices($this->priorityChoices())
            ->renderAsBadges(self::priorityBadgeMap());
        yield BooleanField::new('completed', 'Finalizat');
        yield TextField::new('description', 'Descriere')->onlyOnDetail();
        yield DateTimeField::new('completedAt', 'Finalizat la')->onlyOnDetail();
        yield AssociationField::new('completedBy', 'Finalizat de')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('type', 'Tip termen')->setChoices($this->typeChoices()))
            ->add(ChoiceFilter::new('priority', 'Prioritate')->setChoices($this->priorityChoices()))
            ->add(BooleanFilter::new('completed', 'Finalizat'))
            ->add(DateTimeFilter::new('deadlineDate', 'Data limită'))
            ->add(EntityFilter::new('legalCase', 'Dosar'));
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function typeChoices(): array
    {
        $choices = [];
        foreach (DeadlineType::cases() as $type) {
            $choices[$this->translator->trans($type->label())] = $type->value;
        }
        return $choices;
    }

    /**
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function priorityChoices(): array
    {
        $choices = [];
        foreach (DeadlinePriority::cases() as $priority) {
            $choices[$this->translator->trans($priority->label())] = $priority->value;
        }
        return $choices;
    }

    /**
     * @return array<string, string> priority value => Bootstrap badge color
     */
    private static function priorityBadgeMap(): array
    {
        $colorMap = [
            'slate' => 'secondary',
            'sky' => 'info',
            'amber' => 'warning',
            'red' => 'danger',
        ];

        $map = [];
        foreach (DeadlinePriority::cases() as $priority) {
            $map[$priority->value] = $colorMap[$priority->color()] ?? 'secondary';
        }
        return $map;
    }
}
