<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\WishlistItemRepository;
use App\Service\WishlistService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-bookcase wishlist: a visitor wishes for a book, a donor drops a copy off,
 * and the requester confirms the hand-off (picked up / not found). The flow and
 * its notifications live in App\Service\WishlistService.
 *
 *  - Open      created by the requester; every watcher of the bookcase is notified.
 *  - Dropped   any logged-in user marks they dropped a copy; the requester is notified.
 *  - Fulfilled the requester picked it up; the dropper is thanked.
 *  - Not found the requester couldn't find it; the dropper is told and the wish
 *              reopens (status → Open, dropper cleared) so it can be dropped again.
 */
#[Route('/api/bookcase/{bookcase}', name: 'api_wishlist_')]
class WishlistController extends AbstractController
{
    public function __construct(
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly WishlistService $wishlistService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/wishlist', name: 'list_html', methods: ['GET'])]
    public function list(Bookcase $bookcase, #[CurrentUser] ?User $user): Response
    {
        return $this->render('index/wishlist_modal.html.twig', [
            'bookcase' => $bookcase,
            'items' => $this->wishlistItemRepository->findForBookcase($bookcase),
            'currentUserId' => $user?->id !== null ? (string) $user->id : null,
        ]);
    }

    #[Route('/wishlist', name: 'add', methods: ['POST'])]
    public function add(Request $request, Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $title = trim((string) $request->request->get('title'));
        if ($title === '') {
            return new JsonResponse(['error' => $this->translator->trans('flash.title_required')], Response::HTTP_BAD_REQUEST);
        }

        $item = $this->wishlistService->newItem(
            $bookcase,
            $user,
            $title,
            (string) $request->request->get('author'),
            (string) $request->request->get('isbn'),
            (string) $request->request->get('misc'),
        );
        if ($violations = $this->wishlistService->store($item)) {
            return new JsonResponse(['error' => $violations[0]['message']], Response::HTTP_BAD_REQUEST);
        }
        $this->wishlistService->notifyWatchersOfNewWish($item);

        return new JsonResponse([
            'status' => 'success',
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }

    #[Route('/wishlist/{item}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(Request $request, Bookcase $bookcase, WishlistItem $item, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }
        if ((string) $item->bookcase?->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $action = (string) $request->request->get('action');
        $comment = $action === 'notfound' ? (string) $request->request->get('comment') : null;
        if ($error = $this->wishlistService->changeStatus($item, $user, $action, $comment)) {
            return new JsonResponse(['error' => $this->translator->trans($error['error'])], $error['status']);
        }

        return new JsonResponse([
            'status' => 'success',
            'itemStatus' => $item->status->value,
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }

    #[Route('/wishlist/{item}', name: 'delete', methods: ['DELETE'])]
    public function delete(Bookcase $bookcase, WishlistItem $item, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }
        if ((string) $item->bookcase?->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        if ($error = $this->wishlistService->cancel($item, $user)) {
            return new JsonResponse(['error' => $this->translator->trans($error['error'])], $error['status']);
        }

        return new JsonResponse([
            'status' => 'success',
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }
}
