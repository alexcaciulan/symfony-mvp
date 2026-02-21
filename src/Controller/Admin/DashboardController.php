<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\LegalCaseRepository;
use App\Repository\PaymentRepository;
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
        private PaymentRepository $paymentRepository,
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
            'draftCases' => $this->legalCaseRepository->countByStatus('draft'),
            'pendingPaymentCases' => $this->legalCaseRepository->countByStatus('pending_payment'),
            'paidCases' => $this->legalCaseRepository->countByStatus('paid'),
            'submittedCases' => $this->legalCaseRepository->countByStatus('submitted_to_court'),
            'underReviewCases' => $this->legalCaseRepository->countByStatus('under_review'),
            'acceptedCases' => $this->legalCaseRepository->countByStatus('resolved_accepted'),
            'rejectedCases' => $this->legalCaseRepository->countByStatus('resolved_rejected'),
            'revenueThisMonth' => $this->paymentRepository->sumCompletedCurrentMonth(),
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
        yield MenuItem::section('Administrare');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilizatori', 'fas fa-users')->setAction(Action::INDEX);
        yield MenuItem::linkTo(CourtCrudController::class, 'Instanțe', 'fas fa-landmark')->setAction(Action::INDEX);
        yield MenuItem::linkTo(AuditLogCrudController::class, 'Jurnal audit', 'fas fa-clipboard-list')->setAction(Action::INDEX);
    }
}
