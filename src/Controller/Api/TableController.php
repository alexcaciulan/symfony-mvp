<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Table\Tabulator\TabulatorRequestParser;
use App\Service\Table\Tabulator\TabulatorResponseFactory;
use App\Service\Table\TableDataService;
use App\Service\Table\TableRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Single generic endpoint serving every registered table. The table is selected
 * by its key; rows are produced by the agnostic engine and shaped for Tabulator
 * by the adapter. Adding a new table needs no new controller.
 */
#[Route('/api', name: 'api_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class TableController extends AbstractController
{
    #[Route('/table/{key}', name: 'table_data', methods: ['GET'], requirements: ['key' => '[a-z0-9_]+'])]
    public function data(
        string $key,
        Request $request,
        #[CurrentUser] User $user,
        TableRegistry $registry,
        TableDataService $dataService,
        TabulatorRequestParser $requestParser,
        TabulatorResponseFactory $responseFactory,
        RateLimiterFactory $tableDataLimiter,
    ): JsonResponse {
        if (!$registry->has($key)) {
            return new JsonResponse(['error' => 'table.error.unknown'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$tableDataLimiter->create($user->getUserIdentifier())->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'table.error.rate_limited'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $result = $dataService->query(
            $registry->get($key),
            $user,
            $requestParser->parse($request),
        );

        return new JsonResponse($responseFactory->format($result));
    }
}
