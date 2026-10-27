<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CourtEmailAddress;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Repository\CourtEmailAddressReportRepository;
use App\Repository\CourtEmailAddressRepository;
use App\Repository\CourtRepository;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin state changes on a court address, one confirm page per action, plus the
 * admin's own additions and the decisions on reports. Only the admin decides on
 * an address, since it serves every lawyer's filings at that court; the
 * confirmation statement says the admin checked the address on its source.
 */
#[IsGranted('ROLE_ADMIN')]
class CourtEmailAddressAdminController extends AbstractController
{
    private const ACTIONS = [
        'validate' => 'Validează adresa (verificată pe o sursă oficială)',
        'reject' => 'Respinge adresa',
        'default' => 'Setează ca adresă implicită de depunere',
        'invalidate' => 'Marchează adresa ca invalidă',
        'reopen' => 'Redeschide adresa',
        'purpose' => 'Schimbă scopul adresei',
        'erase' => 'Șterge adresa nominală',
    ];

    public function __construct(
        private readonly CourtEmailAddressRepository $addresses,
        private readonly CourtEmailAddressReportRepository $reports,
        private readonly CourtRepository $courts,
        private readonly CourtEmailAddressService $service,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    #[AdminRoute('/court-email/{id}/{action}', name: 'court_email_action', options: ['requirements' => ['id' => '\d+', 'action' => 'validate|reject|default|invalidate|reopen|purpose|erase'], 'methods' => ['GET', 'POST']])]
    public function __invoke(Request $request, int $id, string $action): Response
    {
        $address = $this->addresses->find($id) ?? throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('court_email_' . $action . '_' . $id, $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Token CSRF invalid.');

                return $this->redirectToRoute('admin_court_email_action', ['id' => $id, 'action' => $action]);
            }

            try {
                $this->apply($action, $address, $request);
            } catch (CourtEmailActionRefusedException $e) {
                $this->addFlash('error', $this->translator->trans($e->messageKey, [], 'messages', 'ro'));

                return $this->redirectToRoute('admin_court_email_action', ['id' => $id, 'action' => $action]);
            }

            $this->addFlash('success', self::ACTIONS[$action] . ': efectuat.');

            return $this->redirect($this->crudUrl($action === 'erase' ? null : $id));
        }

        return $this->render('admin/court_email/action.html.twig', [
            'address' => $address,
            'action' => $action,
            'title' => self::ACTIONS[$action],
            'statement' => $this->service->statementText(),
            'purposes' => CourtEmailAddressCrudController::PURPOSE_LABELS,
            'reasons' => CourtEmailReportReason::cases(),
            'open_reports' => $this->reports->findOpenForAddress($address),
            'back_url' => $this->crudUrl($id),
        ]);
    }

    /** An address the admin adds, confirmed on adding with the validation statement. */
    #[AdminRoute('/court/{courtId}/email/add', name: 'court_email_add', options: ['requirements' => ['courtId' => '\d+'], 'methods' => ['GET', 'POST']])]
    public function add(Request $request, int $courtId): Response
    {
        $court = $this->courts->find($courtId) ?? throw $this->createNotFoundException();
        $backUrl = $this->adminUrlGenerator->unsetAll()->setController(CourtCrudController::class)->setAction(Action::DETAIL)->setEntityId($courtId)->generateUrl();

        if ($request->isMethod('POST')) {
            $payload = $request->getPayload();
            if (!$this->isCsrfTokenValid('court_email_add_' . $courtId, $payload->getString('_token'))) {
                $this->addFlash('error', 'Token CSRF invalid.');

                return $this->redirectToRoute('admin_court_email_add', ['courtId' => $courtId]);
            }
            try {
                $address = $this->service->addByAdmin(
                    $court,
                    $payload->getString('email'),
                    CourtEmailPurpose::tryFrom($payload->getString('purpose')) ?? throw new CourtEmailActionRefusedException('court_address.validation.error.invalid_purpose'),
                    $this->currentUser(),
                    $payload->getBoolean('statement_confirmed'),
                    $payload->getString('source_url'),
                );
            } catch (CourtEmailActionRefusedException $e) {
                $this->addFlash('error', $this->translator->trans($e->messageKey, [], 'messages', 'ro'));

                return $this->redirectToRoute('admin_court_email_add', ['courtId' => $courtId]);
            }
            $this->addFlash('success', 'Adresa a fost adăugată și confirmată.');

            return $this->redirect($this->crudUrl((int) $address->getId()));
        }

        return $this->render('admin/court_email/add.html.twig', [
            'court' => $court,
            'statement' => $this->service->statementText(),
            'purposes' => array_filter(CourtEmailAddressCrudController::PURPOSE_LABELS, static fn (CourtEmailPurpose $p): bool => in_array($p, CourtEmailPurpose::manualChoices(), true)),
            'back_url' => $backUrl,
        ]);
    }

    /** The admin's decision on a report: dismiss it, or take the address out of use. */
    #[AdminRoute('/court-email-report/{id}/{action}', name: 'court_email_report_action', options: ['requirements' => ['id' => '\d+', 'action' => 'dismiss|invalidate'], 'methods' => ['GET', 'POST']])]
    public function report(Request $request, int $id, string $action): Response
    {
        $report = $this->reports->find($id) ?? throw $this->createNotFoundException();
        $backUrl = $this->adminUrlGenerator->unsetAll()->setController(CourtEmailAddressReportCrudController::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('court_email_report_' . $action . '_' . $id, $request->getPayload()->getString('_token'))) {
                $this->addFlash('error', 'Token CSRF invalid.');

                return $this->redirectToRoute('admin_court_email_report_action', ['id' => $id, 'action' => $action]);
            }
            try {
                $this->service->resolveReport($report, $this->currentUser(), $action === 'invalidate');
            } catch (CourtEmailActionRefusedException $e) {
                $this->addFlash('error', $this->translator->trans($e->messageKey, [], 'messages', 'ro'));

                return $this->redirect($backUrl);
            }
            $this->addFlash('success', $action === 'invalidate' ? 'Adresa a fost scoasă din uz.' : 'Raportul a fost respins; adresa rămâne în uz.');

            return $this->redirect($backUrl);
        }

        return $this->render('admin/court_email/report_action.html.twig', [
            'report' => $report,
            'action' => $action,
            'title' => $action === 'invalidate' ? 'Scoate adresa din uz' : 'Respinge raportul',
            'kinds' => array_flip(array_map(static fn ($k): string => $k->value, CourtEmailAddressReportCrudController::KIND_LABELS)),
            'back_url' => $backUrl,
        ]);
    }

    private function apply(string $action, CourtEmailAddress $address, Request $request): void
    {
        $payload = $request->getPayload();
        match ($action) {
            'validate' => $this->service->validate($address, $this->currentUser(), $payload->getBoolean('statement_confirmed')),
            'reject' => $this->service->reject($address),
            'default' => $this->service->setFilingDefault($address),
            'invalidate' => $this->service->markInvalid($address, 'admin:' . (CourtEmailReportReason::tryFrom($payload->getString('reason')) ?? CourtEmailReportReason::OTHER)->value),
            'reopen' => $this->service->reopen($address),
            'purpose' => $this->service->changePurpose(
                $address,
                CourtEmailPurpose::tryFrom($payload->getString('purpose')) ?? throw new CourtEmailActionRefusedException('court_address.validation.error.invalid_purpose'),
                $payload->getString('section_label'),
            ),
            'erase' => $this->service->erasePersonal($address),
        };
    }

    private function crudUrl(?int $id): string
    {
        $generator = $this->adminUrlGenerator->unsetAll()->setController(CourtEmailAddressCrudController::class);

        return $id === null
            ? $generator->setAction(Action::INDEX)->generateUrl()
            : $generator->setAction(Action::DETAIL)->setEntityId($id)->generateUrl();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
