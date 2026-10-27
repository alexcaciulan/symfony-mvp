<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CourtEmailAddressReport;
use App\Enum\CourtEmailReportKind;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin's queue of reports on court addresses: a lawyer's report, or a
 * delivery report saying the mailbox does not exist. Nothing changed on the
 * address when the report came in; the admin dismisses it or takes the address
 * out of use (through CourtEmailAddressAdminController and the service).
 */
class CourtEmailAddressReportCrudController extends AbstractCrudController
{
    public const KIND_LABELS = [
        'Raport al unui avocat' => CourtEmailReportKind::LAWYER,
        'Raport de nelivrare' => CourtEmailReportKind::DELIVERY_REPORT,
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return CourtEmailAddressReport::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Raport adresă instanță')
            ->setEntityLabelInPlural('Rapoarte adrese instanțe')
            ->setDefaultSort(['resolvedAt' => 'ASC', 'id' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        $link = static fn (string $name, string $label, string $icon): Action => Action::new($name, $label, $icon)
            ->linkToRoute('admin_court_email_report_action', static fn (CourtEmailAddressReport $r): array => ['id' => $r->getId(), 'action' => $name])
            ->displayIf(static fn (CourtEmailAddressReport $r): bool => $r->isOpen());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_DETAIL, $link('dismiss', 'Respinge raportul', 'fa fa-ban'))
            ->add(Crud::PAGE_DETAIL, $link('invalidate', 'Scoate adresa din uz', 'fa fa-times-circle'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('address', 'Adresă');
        yield TextField::new('address.court.name', 'Instanță');
        yield TextField::new('kind', 'Tip')
            ->formatValue($this->enumLabel(...))
            // The text template prints the raw value as a title, which an enum cannot be.
            ->setTemplateName('crud/field/generic');
        yield TextField::new('reason', 'Motiv')
            ->formatValue($this->enumLabel(...))
            // The text template prints the raw value as a title, which an enum cannot be.
            ->setTemplateName('crud/field/generic');
        yield TextField::new('enhancedStatus', 'Cod raport de nelivrare');
        yield AssociationField::new('legalCase', 'Dosar');
        yield AssociationField::new('reportedBy', 'Raportat de');
        yield DateTimeField::new('createdAt', 'Primit la');
        yield DateTimeField::new('resolvedAt', 'Decis la');
        yield TextField::new('resolution', 'Decizie')
            ->formatValue($this->enumLabel(...))
            // The text template prints the raw value as a title, which an enum cannot be.
            ->setTemplateName('crud/field/generic');
        yield AssociationField::new('resolvedBy', 'Decis de')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(NullFilter::new('resolvedAt', 'Stare')->setChoiceLabels('Deschis', 'Decis'))
            ->add(ChoiceFilter::new('kind', 'Tip')->setChoices(array_map(static fn (CourtEmailReportKind $k): string => $k->value, self::KIND_LABELS)));
    }

    private function enumLabel(mixed $value): string
    {
        return $value instanceof \BackedEnum && method_exists($value, 'label') ? $this->translator->trans($value->label()) : '';
    }
}
