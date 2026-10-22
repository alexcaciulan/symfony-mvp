<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CourtEmailAddress;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Repository\CourtEmailAddressRepository;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin state changes on a court address, one confirm page per action. An admin
 * confirmation uses the same statement as the lawyer's and records the admin.
 */
#[IsGranted('ROLE_ADMIN')]
class CourtEmailAddressAdminController extends AbstractController
{
    private const ACTIONS = [
        'validate' => 'Validează adresa',
        'reject' => 'Respinge adresa',
        'default' => 'Setează ca adresă implicită de depunere',
        'invalidate' => 'Marchează adresa ca invalidă',
        'reopen' => 'Redeschide adresa',
        'purpose' => 'Schimbă scopul adresei',
        'erase' => 'Șterge adresa nominală',
    ];

    public function __construct(
        private readonly CourtEmailAddressRepository $addresses,
        private readonly CourtEmailAddressService $service,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/court-email/{id}/{action}', name: 'admin_court_email_action', requirements: ['id' => '\d+', 'action' => 'validate|reject|default|invalidate|reopen|purpose|erase'], methods: ['GET', 'POST'])]
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
            'back_url' => $this->crudUrl($id),
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
