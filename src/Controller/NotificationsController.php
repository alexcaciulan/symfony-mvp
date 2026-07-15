<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Notification\NotificationCenterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationsController extends AbstractController
{
    public function __construct(
        private readonly NotificationCenterService $center,
    ) {}

    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function index(): Response
    {
        // The full list is the Tabulator DataTable (remote filter/sort/pagination),
        // fed by NotificationTableDefinition. This action just renders the shell.
        return $this->render('notifications/index.html.twig');
    }

    #[Route('/notifications/dropdown', name: 'app_notifications_dropdown', methods: ['GET'])]
    public function dropdown(): Response
    {
        $user = $this->currentUser();

        return $this->render('notifications/_dropdown.html.twig', [
            'notifications' => $this->center->recent($user),
            'unreadCount' => $this->center->unreadCount($user),
        ]);
    }

    #[Route('/notifications/unread-count', name: 'app_notifications_unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        return $this->json(['count' => $this->center->unreadCount($this->currentUser())]);
    }

    #[Route('/notifications/{id}/read', name: 'app_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notification_read', (string) $request->request->get('_token'))) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $notification = $this->center->markRead($id, $this->currentUser());
        if ($notification === null) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirect(
            $notification->getResourceLink()
            ?? $request->headers->get('referer')
            ?? $this->generateUrl('app_notifications'),
        );
    }

    #[Route('/notifications/read-all', name: 'app_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notification_read_all', (string) $request->request->get('_token'))) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $this->center->markAllRead($this->currentUser());

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_notifications'));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
