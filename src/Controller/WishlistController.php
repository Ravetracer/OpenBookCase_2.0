<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enums\MessageType;
use App\Enums\WishlistItemStatus;
use App\Config\Locales;
use App\Repository\WatchlistItemRepository;
use App\Repository\WishlistItemRepository;
use App\Service\MessageService;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-bookcase wishlist: a visitor wishes for a book, a donor drops a copy off,
 * and the requester confirms the hand-off (picked up / not found). Mutating the
 * list emits system notifications via App\Service\MessageService.
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
        private readonly EntityManagerInterface $entityManager,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly WatchlistItemRepository $watchlistItemRepository,
        private readonly MessageService $messageService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Translate a key in the given recipient's language (falling back to default).
     *
     * @param array<string, mixed> $params
     */
    private function transFor(?string $locale, string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'messages', Locales::isSupported($locale) ? $locale : Locales::DEFAULT);
    }

    #[Route('/wishlist', name: 'list_html', methods: ['GET'])]
    public function list(Bookcase $bookcase): Response
    {
        $user = $this->getUser();

        return $this->render('index/wishlist_modal.html.twig', [
            'bookcase' => $bookcase,
            'items' => $this->wishlistItemRepository->findForBookcase($bookcase),
            'currentUserId' => $user instanceof User && $user->id !== null ? (string) $user->id : null,
        ]);
    }

    #[Route('/wishlist', name: 'add', methods: ['POST'])]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $title = trim((string) $request->request->get('title'));
        if ($title === '') {
            return new JsonResponse(['error' => $this->translator->trans('flash.title_required')], Response::HTTP_BAD_REQUEST);
        }

        $item = new WishlistItem();
        $item->bookcase = $bookcase;
        $item->user = $user;
        $item->status = WishlistItemStatus::Open;
        $item->title = $title;
        $item->author = $this->cleanOptional($request->request->get('author'));
        $item->isbn = $this->cleanOptional($request->request->get('isbn'));
        $item->misc = $this->cleanOptional($request->request->get('misc'));

        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->notifyWatchersOfNewWish($bookcase, $item, $user);

        return new JsonResponse([
            'status' => 'success',
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }

    #[Route('/wishlist/{item}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(Request $request, Bookcase $bookcase, WishlistItem $item): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->itemBelongsTo($item, $bookcase)) {
            throw $this->createNotFoundException();
        }

        $action = (string) $request->request->get('action');
        $isRequester = $item->user !== null && (string) $item->user->id === (string) $user->id;
        $bookcaseTitle = (string) $bookcase->title;
        $bookTitle = (string) $item->title;
        $actor = $user->getUserIdentifier();

        switch ($action) {
            case 'drop':
                if ($item->status !== WishlistItemStatus::Open) {
                    return new JsonResponse(['error' => $this->translator->trans('flash.wish_cannot_drop')], Response::HTTP_CONFLICT);
                }
                $item->status = WishlistItemStatus::Dropped;
                $item->droppedBy = $user;
                $this->entityManager->flush();

                // Tell the requester to come and collect it (unless they dropped it themselves).
                if (!$isRequester && $item->user !== null) {
                    $loc = $item->user->language;
                    $params = ['%user%' => $actor, '%title%' => $bookTitle, '%bookcase%' => $bookcaseTitle];
                    $this->messageService->notify(
                        $item->user,
                        $this->transFor($loc, 'notify.wishlist_dropped_body', $params),
                        MessageType::WishlistMatch,
                        $this->transFor($loc, 'notify.wishlist_dropped_subject', ['%title%' => $bookTitle]),
                        $bookcase,
                    );
                }
                break;

            case 'fulfill':
                if (!$isRequester) {
                    return new JsonResponse(['error' => $this->translator->trans('flash.only_requester_pickup')], Response::HTTP_FORBIDDEN);
                }
                if ($item->status !== WishlistItemStatus::Dropped) {
                    return new JsonResponse(['error' => $this->translator->trans('flash.not_awaiting_pickup')], Response::HTTP_CONFLICT);
                }
                $dropper = $item->droppedBy;
                $item->status = WishlistItemStatus::Fulfilled;
                $this->entityManager->flush();

                if ($dropper !== null) {
                    $loc = $dropper->language;
                    $params = ['%user%' => $actor, '%title%' => $bookTitle, '%bookcase%' => $bookcaseTitle];
                    $this->messageService->notify(
                        $dropper,
                        $this->transFor($loc, 'notify.wishlist_fulfilled_body', $params),
                        MessageType::WishlistMatch,
                        $this->transFor($loc, 'notify.wishlist_fulfilled_subject', ['%title%' => $bookTitle]),
                        $bookcase,
                    );
                }
                break;

            case 'notfound':
                if (!$isRequester) {
                    return new JsonResponse(['error' => $this->translator->trans('flash.only_requester_missing')], Response::HTTP_FORBIDDEN);
                }
                if ($item->status !== WishlistItemStatus::Dropped) {
                    return new JsonResponse(['error' => $this->translator->trans('flash.not_awaiting_pickup')], Response::HTTP_CONFLICT);
                }
                $comment = $this->cleanOptional($request->request->get('comment'));
                $dropper = $item->droppedBy;

                // Reopen the wish so another donor can drop it again.
                $item->status = WishlistItemStatus::Open;
                $item->droppedBy = null;
                $this->entityManager->flush();

                if ($dropper !== null) {
                    $loc = $dropper->language;
                    $params = ['%user%' => $actor, '%title%' => $bookTitle, '%bookcase%' => $bookcaseTitle];
                    $body = $this->transFor($loc, 'notify.wishlist_notfound_body', $params);
                    if ($comment !== null) {
                        $body .= "\n" . $this->transFor($loc, 'notify.wishlist_notfound_note', ['%comment%' => $comment]);
                    }
                    $this->messageService->notify(
                        $dropper,
                        $body,
                        MessageType::WishlistMatch,
                        $this->transFor($loc, 'notify.wishlist_notfound_subject', ['%title%' => $bookTitle]),
                        $bookcase,
                    );
                }
                break;

            default:
                return new JsonResponse(['error' => $this->translator->trans('flash.unknown_action')], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status' => 'success',
            'itemStatus' => $item->status->value,
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }

    #[Route('/wishlist/{item}', name: 'delete', methods: ['DELETE'])]
    public function delete(Bookcase $bookcase, WishlistItem $item): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->itemBelongsTo($item, $bookcase)) {
            throw $this->createNotFoundException();
        }

        $isRequester = $item->user !== null && (string) $item->user->id === (string) $user->id;
        if (!$isRequester) {
            return new JsonResponse(['error' => $this->translator->trans('flash.only_requester_cancel')], Response::HTTP_FORBIDDEN);
        }
        if ($item->status !== WishlistItemStatus::Open) {
            return new JsonResponse(['error' => $this->translator->trans('flash.only_open_cancel')], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'success',
            'openCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ], Response::HTTP_OK);
    }

    /**
     * Message every watcher of the bookcase (except the wish's creator) that a
     * new book is wanted here.
     */
    private function notifyWatchersOfNewWish(Bookcase $bookcase, WishlistItem $item, User $creator): void
    {
        $creatorId = $creator->id !== null ? (string) $creator->id : null;

        $recipients = array_filter(
            $this->watchlistItemRepository->findWatcherUsersOf($bookcase),
            static fn (User $u) => $creatorId === null || (string) $u->id !== $creatorId,
        );

        if ($recipients === []) {
            return;
        }

        $bookTitle = (string) $item->title;
        $detail = $item->author !== null ? sprintf('%s — %s', $bookTitle, $item->author) : $bookTitle;
        $actor = $creator->getUserIdentifier();
        $bookcaseTitle = (string) $bookcase->title;

        // Translate per recipient so each watcher reads it in their own language.
        foreach ($recipients as $recipient) {
            $loc = $recipient->language;
            $this->messageService->notify(
                $recipient,
                $this->transFor($loc, 'notify.wishlist_new_body', [
                    '%user%' => $actor,
                    '%detail%' => $detail,
                    '%bookcase%' => $bookcaseTitle,
                ]),
                MessageType::WishlistMatch,
                $this->transFor($loc, 'notify.wishlist_new_subject', ['%title%' => $bookTitle]),
                $bookcase,
            );
        }
    }

    private function itemBelongsTo(WishlistItem $item, Bookcase $bookcase): bool
    {
        return $item->bookcase !== null && (string) $item->bookcase->id === (string) $bookcase->id;
    }

    /**
     * Trim an optional text field, returning null when it is empty.
     */
    private function cleanOptional(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
