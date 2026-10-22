<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Court filings sent by email, read-only and metadata only: states, times, sizes,
 * hashes and SMTP codes. The subject, the body, the confirmed statements and the
 * copies of the emails carry data under professional secrecy and stay with the
 * lawyer, so none of them is shown or linked here.
 */
class FilingSubmissionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FilingSubmission::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transmitere la instanță')
            ->setEntityLabelInPlural('Transmiteri la instanță')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        $override = Action::new('override', 'Deblocare operațională', 'fa fa-unlock')
            ->linkToRoute('admin_filing_submission_override', static fn (FilingSubmission $s): array => ['id' => $s->getId()])
            ->displayIf(static fn (FilingSubmission $s): bool => $s->isActive());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $override)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield IntegerField::new('legalCase.id', 'ID dosar');
        yield TextField::new('court.name', 'Instanța');
        yield TextField::new('deliveryStatus', 'Stare transmitere')
            ->formatValue(static fn ($value): string => $value?->value ?? '')
            // The text template prints the raw value as a title, which an enum cannot be.
            ->setTemplateName('crud/field/generic');
        yield TextField::new('evidenceStatus', 'Dovada primirii')
            ->formatValue(static fn ($value): string => $value?->value ?? '')
            // The text template prints the raw value as a title, which an enum cannot be.
            ->setTemplateName('crud/field/generic');
        yield DateTimeField::new('initiatedAt', 'Cerută la');
        yield DateTimeField::new('sentAt', 'Acceptată SMTP la');
        yield TextField::new('reviewReason', 'Motiv verificare')->onlyOnDetail();
        yield DateTimeField::new('courtReceiptAt', 'Confirmare de primire la')->onlyOnDetail();
        yield IntegerField::new('receiptInboundMail.id', 'ID mesaj confirmare')->onlyOnDetail();
        yield DateTimeField::new('readReceiptAt', 'Notificare de afișare la')->onlyOnDetail();
        yield TextField::new('templateVersion', 'Versiune șablon')->onlyOnDetail();
        yield IntegerField::new('parts', 'E-mailuri')
            ->onlyOnIndex()
            ->formatValue(static fn ($value, FilingSubmission $submission): int => $submission->getParts()->count());
        yield TextField::new('parts', 'Părți')
            ->onlyOnDetail()
            ->formatValue(static fn ($value, FilingSubmission $submission): string => implode(' | ', array_map(
                static fn (FilingEmailPart $p): string => sprintf(
                    '#%d %s, %s B, sha256 %s, SMTP %s %s',
                    $p->getSequence(),
                    $p->getStatus()->value,
                    $p->getEncodedBytes() ?? '-',
                    $p->getEmlSha256() ?? '-',
                    $p->getLastSmtpCode() ?? '-',
                    $p->getLastEnhancedStatus() ?? '',
                ),
                $submission->getParts()->toArray(),
            )));
    }
}
