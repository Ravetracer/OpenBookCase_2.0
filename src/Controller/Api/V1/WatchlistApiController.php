<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Repository\WatchlistItemRepository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly WatchlistItemRepository $watchlistItems,
    ) {
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Bookcase $bookcase): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->watchlistItems->findOneByUserAndBookcase($user, $bookcase) === null) {
            $item = new WatchlistItem();
            $item->user = $user;
            $item->bookcase = $bookcase;
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        }

        return new JsonResponse(['watching' => true]);
    }

    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function remove(Bookcase $bookcase): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $item = $this->watchlistItems->findOneByUserAndBookcase($user, $bookcase);
        if ($item !== null) {
            $this->entityManager->remove($item);
            $this->entityManager->flush();
        }

        return new JsonResponse(['watching' => false]);
    }
}
