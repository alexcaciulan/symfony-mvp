<?php

namespace App\Controller\Admin;

use App\Repository\InvoiceRepository;
use App\Repository\LegalCaseRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private UserRepository $userRepository,
        private LegalCaseRepository $legalCaseRepository,
        private InvoiceRepository $invoiceRepository,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'totalUsers' => $this->userRepository->countAll(),
            'verifiedUsers' => $this->userRepository->countVerified(),
            'unverifiedUsers' => $this->userRepository->countUnverified(),
            'adminUsers' => $this->userRepository->countAdmins(),
            'totalCases' => $this->legalCaseRepository->countAll(),
            'activeCases' => $this->legalCaseRepository->countActive(),
            'rulingsIssuedThisMonth' => $this->legalCaseRepository->countRulingsIssuedThisMonth(),
            'pendingInvoices' => $this->invoiceRepository->countPending(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('RecuperăriCreanțe — Admin')
            ->setFaviconPath('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 128 128%22><text y=%221.2em%22 font-size=%2296%22>⚖️</text></svg>');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Dosare');
        yield MenuItem::linkTo(LegalCaseCrudController::class, 'Dosare', 'fas fa-folder-open')->setAction(Action::INDEX);
        yield MenuItem::linkTo(CreditorCrudController::class, 'Creditori', 'fas fa-hand-holding-usd')->setAction(Action::INDEX);
        yield MenuItem::linkTo(DebtorCrudController::class, 'Debitori', 'fas fa-user-tag')->setAction(Action::INDEX);
        yield MenuItem::linkTo(LegalDeadlineCrudController::class, 'Termene', 'fas fa-calendar-day')->setAction(Action::INDEX);
        yield MenuItem::section('Monetizare');
        yield MenuItem::linkTo(PlanCrudController::class, 'Planuri', 'fas fa-layer-group')->setAction(Action::INDEX);
        yield MenuItem::linkTo(SubscriptionCrudController::class, 'Abonamente', 'fas fa-id-card')->setAction(Action::INDEX);
        yield MenuItem::linkTo(InvoiceCrudController::class, 'Facturi', 'fas fa-file-invoice-dollar')->setAction(Action::INDEX);
        yield MenuItem::linkTo(FiscalInvoiceCrudController::class, 'Facturi fiscale', 'fas fa-file-invoice')->setAction(Action::INDEX);
        yield MenuItem::section('Administrare');
        yield MenuItem::linkTo(AppSettingCrudController::class, 'Setări aplicație', 'fas fa-sliders-h')->setAction(Action::INDEX);
        yield MenuItem::linkTo(UserCrudController::class, 'Utilizatori', 'fas fa-users')->setAction(Action::INDEX);
        yield MenuItem::linkTo(CourtCrudController::class, 'Instanțe', 'fas fa-landmark')->setAction(Action::INDEX);
        yield MenuItem::linkTo(CourtPortalEventCrudController::class, 'Evenimente portal', 'fas fa-satellite-dish')->setAction(Action::INDEX);
        yield MenuItem::linkTo(InterestRateConfigCrudController::class, 'Rate dobândă', 'fas fa-percent')->setAction(Action::INDEX);
        yield MenuItem::linkTo(BnrExchangeRateCrudController::class, 'Cursuri valutare BNR', 'fas fa-money-bill-transfer')->setAction(Action::INDEX);
        yield MenuItem::linkTo(AuditLogCrudController::class, 'Jurnal audit', 'fas fa-clipboard-list')->setAction(Action::INDEX);
    }
}
