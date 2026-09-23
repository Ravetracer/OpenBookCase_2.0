<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Service\WatchlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — the token user's watchlist for a bookcase. Both actions need the
 * `watchlist.write` scope (role ROLE_OAUTH2_WATCHLIST.WRITE); they add/remove the
 * watch for whoever authorized the app. Idempotent.
 */
#[Route('/api/v1/bookcases/{bookcase}/watch', name: 'api_v1_watch_')]
#[IsGranted('ROLE_OAUTH2_WATCHLIST.WRITE')]
class WatchlistApiController extends AbstractController
{
    public function __construct(
        private readonly WatchlistService $watchlistService,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $this->watchlistService->watch($bookcase, $user);

        return new JsonResponse(['watching' => true]);
    }

    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function remove(Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $this->watchlistService->unwatch($bookcase, $user);

        return new JsonResponse(['watching' => false]);
    }
}
