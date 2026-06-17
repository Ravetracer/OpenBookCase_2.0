<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enums\WishlistItemStatus;
use App\Repository\WishlistItemRepository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly WishlistItemRepository $wishlistItems,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = [];
        foreach ($this->wishlistItems->findForBookcase($bookcase) as $item) {
            $items[] = [
                'id' => (string) $item->id,
                'title' => $item->title,
                'author' => $item->author,
                'isbn' => $item->isbn,
                'misc' => $item->misc,
                'status' => $item->status->value,
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_WISHLIST.WRITE')]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = [];
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return new JsonResponse(['error' => 'A "title" is required.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        $item = new WishlistItem();
        $item->bookcase = $bookcase;
        $item->user = $user;
        $item->status = WishlistItemStatus::Open;
        $item->title = $title;
        $item->author = $this->nullableString($data['author'] ?? null);
        $item->isbn = $this->nullableString($data['isbn'] ?? null);
        $item->misc = $this->nullableString($data['misc'] ?? null);

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => (string) $item->id,
            'openCount' => $this->wishlistItems->countOpen($bookcase),
        ], Response::HTTP_CREATED);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
