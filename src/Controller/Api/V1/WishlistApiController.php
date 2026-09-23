<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Repository\WishlistItemRepository;
use App\Service\ApiInput;
use App\Service\WishlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — wishlist items of a bookcase. Reading is open; adding needs the
 * `wishlist.write` scope (role ROLE_OAUTH2_WISHLIST.WRITE) and is attributed to the
 * token's user (the wish belongs to whoever authorized the app).
 */
#[Route('/api/v1/bookcases/{bookcase}/wishlist', name: 'api_v1_wishlist_')]
class WishlistApiController extends AbstractController
{
    public function __construct(
        private readonly ApiInput $input,
        private readonly WishlistItemRepository $wishlistItems,
        private readonly WishlistService $wishlistService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = array_map($this->wishlistService->toApiArray(...), $this->wishlistItems->findForBookcase($bookcase));

        return new JsonResponse(['items' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_WISHLIST.WRITE')]
    public function add(Request $request, Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->input->jsonBody($request);

        $title = $this->input->string($data['title'] ?? null);
        if ($title === null) {
            return new ApiProblem('A "title" is required.', Response::HTTP_BAD_REQUEST);
        }

        $item = $this->wishlistService->newItem(
            $bookcase,
            $user,
            $title,
            $this->input->string($data['author'] ?? null),
            $this->input->string($data['isbn'] ?? null),
            $this->input->string($data['misc'] ?? null),
        );
        if ($violations = $this->wishlistService->store($item)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }
        $this->wishlistService->notifyWatchersOfNewWish($item);

        return new JsonResponse([
            'id' => (string) $item->id,
            'openCount' => $this->wishlistItems->countOpen($bookcase),
        ], Response::HTTP_CREATED);
    }
}
