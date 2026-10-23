<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Filter\CourtCountyFilter;
use App\Controller\Admin\Filter\CourtTypeFilter;
use App\Entity\CourtEmailAddress;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Court addresses for the admin. Every state change goes through
 * CourtEmailAddressAdminController and the service (audit, single default,
 * erasure rules), so the generic new/edit/delete actions are off.
 */
class CourtEmailAddressCrudController extends AbstractCrudController
{
    public const PURPOSE_LABELS = [
        'Registratură' => CourtEmailPurpose::REGISTRY,
        'Secția civilă' => CourtEmailPurpose::SECTION_CIVIL,
        'Secția pentru profesioniști' => CourtEmailPurpose::SECTION_PROFESSIONALS,
        'Altă adresă' => CourtEmailPurpose::OTHER,
        'Marcată automat ca nominală, de verificat' => CourtEmailPurpose::PERSONAL,
    ];

    public const STATUS_LABELS = [
        'Propusă, neconfirmată' => CourtEmailStatus::PROPOSED,
        'Confirmată' => CourtEmailStatus::VALIDATED,
        'Respinsă' => CourtEmailStatus::REJECTED,
        'Invalidă' => CourtEmailStatus::INVALID,
    ];

    public const SOURCE_LABELS = [
        'Introdusă manual (admin)' => CourtEmailSource::MANUAL,
        'Portal (neoficial)' => CourtEmailSource::PORTAL_IMPORT,
        'Pagină salvată (neoficial)' => CourtEmailSource::SAVED_PAGE,
        'Sugestie avocat (neverificată)' => CourtEmailSource::LAWYER_SUGGESTION,
    ];

    public static function getEntityFqcn(): string
    {
        return CourtEmailAddress::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Adresă e-mail instanță')
            ->setEntityLabelInPlural('Adrese e-mail instanțe')
            ->setDefaultSort(['status' => 'DESC', 'id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['email', 'court.name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);

        foreach ($this->stateActions() as $action) {
            $actions->add(Crud::PAGE_DETAIL, $action);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('court', 'Instanță');
        yield TextField::new('email', 'E-mail');
        yield ChoiceField::new('purpose', 'Scop')->setChoices(self::PURPOSE_LABELS);
        yield TextField::new('sectionLabel', 'Secția (din sursă)')->onlyOnDetail();
        yield ChoiceField::new('status', 'Status')->setChoices(self::STATUS_LABELS);
        yield BooleanField::new('filingDefault', 'Implicită')->renderAsSwitch(false);
        yield ChoiceField::new('source', 'Sursă')->setChoices(self::SOURCE_LABELS);
        yield DateTimeField::new('validatedAt', 'Confirmată la');
        yield AssociationField::new('validatedBy', 'Confirmată de');
        yield DateTimeField::new('missingSince', 'Dispărută din sursă din');
        yield TextareaField::new('context', 'Context')->onlyOnDetail();
        yield UrlField::new('sourceUrl', 'URL sursă')->onlyOnDetail();
        yield TextField::new('validationStatement', 'Declarația confirmată')->onlyOnDetail();
        yield DateTimeField::new('invalidatedAt', 'Invalidată la')->onlyOnDetail();
        yield TextField::new('invalidReason', 'Motiv invalidare')->onlyOnDetail();
        yield DateTimeField::new('lastSeenAt', 'Văzută ultima dată')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(array_map(static fn (CourtEmailStatus $s): string => $s->value, self::STATUS_LABELS)))
            ->add(ChoiceFilter::new('purpose', 'Scop')->setChoices(array_map(static fn (CourtEmailPurpose $p): string => $p->value, self::PURPOSE_LABELS)))
            ->add(ChoiceFilter::new('source', 'Sursă')->setChoices(array_map(static fn (CourtEmailSource $s): string => $s->value, self::SOURCE_LABELS)))
            ->add(EntityFilter::new('court', 'Instanță'))
            ->add(CourtCountyFilter::new())
            ->add(CourtTypeFilter::new());
    }

    /** @return list<Action> */
    private function stateActions(): array
    {
        $link = static fn (string $name, string $label, string $icon, callable $displayIf): Action => Action::new($name, $label, $icon)
            ->linkToRoute('admin_court_email_action', static fn (CourtEmailAddress $a): array => ['id' => $a->getId(), 'action' => $name])
            ->displayIf($displayIf);

        $status = static fn (CourtEmailStatus ...$allowed): \Closure => static fn (CourtEmailAddress $a): bool => in_array($a->getStatus(), $allowed, true);

        return [
            $link('validate', 'Validează', 'fa fa-check', static fn (CourtEmailAddress $a): bool => !$a->isPersonal()
                && ($a->getStatus() === CourtEmailStatus::PROPOSED || ($a->getStatus() === CourtEmailStatus::VALIDATED && $a->isValidationExpired(new \DateTimeImmutable())))),
            $link('reject', 'Respinge', 'fa fa-ban', $status(CourtEmailStatus::PROPOSED)),
            $link('default', 'Implicit', 'fa fa-star', static fn (CourtEmailAddress $a): bool => !$a->isFilingDefault() && $a->isUsableForFiling(new \DateTimeImmutable())),
            $link('invalidate', 'Invalidă', 'fa fa-times-circle', $status(CourtEmailStatus::PROPOSED, CourtEmailStatus::VALIDATED)),
            $link('reopen', 'Redeschide', 'fa fa-undo', $status(CourtEmailStatus::INVALID, CourtEmailStatus::REJECTED)),
            $link('purpose', 'Schimbă scopul', 'fa fa-tag', static fn (): bool => true),
            $link('erase', 'Șterge personală', 'fa fa-trash', static fn (CourtEmailAddress $a): bool => $a->isPersonal()),
        ];
    }
}
