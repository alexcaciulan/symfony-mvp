<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FiscalInvoice;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use App\Service\Billing\FiscalInvoiceService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Read-only view of issued fiscal invoices, plus an operator "Storno" action
 * (issuing a correction invoice through the provider). Storno is a fiscal act,
 * so it is admin-only; there is no create/edit/delete of fiscal invoices here.
 */
class FiscalInvoiceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FiscalInvoiceService $fiscalInvoiceService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    public static function getEntityFqcn(): string
    {
        return FiscalInvoice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Factură fiscală')
            ->setEntityLabelInPlural('Facturi fiscale')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $storno = Action::new('storno', 'Storno')
            ->linkToUrl(fn (FiscalInvoice $f): string => $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('storno')
                ->setEntityId($f->getId())
                ->set('_token', $this->csrf->getToken('storno' . $f->getId())->getValue())
                ->generateUrl())
            ->displayIf(static fn (FiscalInvoice $f): bool => FiscalInvoiceStatus::ISSUED === $f->getStatus() && FiscalInvoiceKind::INVOICE === $f->getKind());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $storno)
            ->add(Crud::PAGE_DETAIL, $storno)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('series', 'Serie')->hideOnForm();
        yield TextField::new('number', 'Număr')->hideOnForm();
        yield AssociationField::new('user', 'Client')->hideOnForm();
        yield MoneyField::new('grossTotal', 'Total')->setCurrency('RON')->setStoredAsCents(false)->hideOnForm();
        yield TextField::new('status', 'Status')->formatValue(static fn ($v, FiscalInvoice $f) => $f->getStatus()->value)->hideOnForm();
        yield TextField::new('eInvoiceStatus', 'e-Factura')->formatValue(static fn ($v, FiscalInvoice $f) => $f->getEInvoiceStatus()->value)->hideOnForm();
        yield TextField::new('providerName', 'Provider')->hideOnForm();
        yield DateTimeField::new('issuedAt', 'Emisă')->hideOnForm();
    }

    public function storno(AdminContext $context): Response
    {
        /** @var FiscalInvoice $fiscal */
        $fiscal = $context->getEntity()->getInstance();

        if (!$this->isCsrfTokenValid('storno' . $fiscal->getId(), $context->getRequest()->query->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->fiscalInvoiceService->storno($fiscal, 'Storno administrativ');
            $this->addFlash('success', 'Factură stornată.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Storno eșuat: ' . $e->getMessage());
        }

        $url = $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl();

        return $this->redirect($url);
    }
}
