<?php declare(strict_types=1);

namespace App\Service;

use App\Config\Locales;
use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enums\MessageType;
use App\Enums\WishlistItemStatus;
use App\Repository\WatchlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wishlist hand-off flow shared by the website and /api/v1: creating a wish
 * (validated before it is persisted), the drop / pick-up / not-found steps and
 * cancelling, plus the system notifications each step emits — translated into
 * every recipient's own language.
 *
 * Status changes return null on success, else `['error' => flash key, 'status' => HTTP status]`.
 */
final class WishlistService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiInput $input,
        private readonly WatchlistItemRepository $watchlistItems,
        private readonly MessageService $messageService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** A new, not yet persisted open wish; optional fields are trimmed, blank → null. */
    public function newItem(Bookcase $bookcase, User $user, string $title, ?string $author, ?string $isbn, ?string $misc): WishlistItem
    {
        $item = new WishlistItem();
        $item->bookcase = $bookcase;
        $item->user = $user;
        $item->status = WishlistItemStatus::Open;
        $item->title = $title;
        $item->author = $this->clean($author);
        $item->isbn = $this->clean($isbn);
        $item->misc = $this->clean($misc);

        return $item;
    }

    /** @return array<string, mixed> the /api/v1 representation */
    public function toApiArray(WishlistItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'title' => $item->title,
            'author' => $item->author,
            'isbn' => $item->isbn,
            'misc' => $item->misc,
            'status' => $item->status->value,
        ];
    }

    /**
     * Validates the wish and persists it only when it is valid.
     *
     * @return list<array{field:string, message:string}> violations; empty when stored
     */
    public function store(WishlistItem $item): array
    {
        $violations = $this->input->violations($item);
        if ($violations === []) {
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        }

        return $violations;
    }

    /**
     * Message every watcher of the wish's bookcase (except its creator) that a
     * new book is wanted there.
     */
    public function notifyWatchersOfNewWish(WishlistItem $item): void
    {
        $bookcase = $item->bookcase;
        $creator = $item->user;
        $creatorId = $creator?->id !== null ? (string) $creator->id : null;

        $recipients = array_filter(
            $this->watchlistItems->findWatcherUsersOf($bookcase),
            static fn (User $u) => $creatorId === null || (string) $u->id !== $creatorId,
        );

        $bookTitle = (string) $item->title;
        $detail = $item->author !== null ? sprintf('%s — %s', $bookTitle, $item->author) : $bookTitle;
        $params = ['%user%' => $creator?->getUserIdentifier(), '%detail%' => $detail, '%bookcase%' => (string) $bookcase->title];

        foreach ($recipients as $recipient) {
            $this->notify($recipient, $bookcase, 'notify.wishlist_new_body', $params, 'notify.wishlist_new_subject', $bookTitle);
        }
    }

    /**
     * Applies a hand-off step by $user: `drop` (anyone, open wish), `fulfill` or
     * `notfound` (requester only, dropped wish — not found reopens it). The other
     * party is notified.
     *
     * @return array{error: string, status: int}|null
     */
    public function changeStatus(WishlistItem $item, User $user, string $action, ?string $comment = null): ?array
    {
        $isRequester = $this->isRequester($item, $user);
        $bookcase = $item->bookcase;
        $bookTitle = (string) $item->title;
        $params = ['%user%' => $user->getUserIdentifier(), '%title%' => $bookTitle, '%bookcase%' => (string) $bookcase->title];

        switch ($action) {
            case 'drop':
                if ($item->status !== WishlistItemStatus::Open) {
                    return ['error' => 'flash.wish_cannot_drop', 'status' => Response::HTTP_CONFLICT];
                }
                $item->status = WishlistItemStatus::Dropped;
                $item->droppedBy = $user;
                $this->entityManager->flush();

                // Tell the requester to come and collect it (unless they dropped it themselves).
                if (!$isRequester && $item->user !== null) {
                    $this->notify($item->user, $bookcase, 'notify.wishlist_dropped_body', $params, 'notify.wishlist_dropped_subject', $bookTitle);
                }

                return null;

            case 'fulfill':
                if (!$isRequester) {
                    return ['error' => 'flash.only_requester_pickup', 'status' => Response::HTTP_FORBIDDEN];
                }
                if ($item->status !== WishlistItemStatus::Dropped) {
                    return ['error' => 'flash.not_awaiting_pickup', 'status' => Response::HTTP_CONFLICT];
                }
                $dropper = $item->droppedBy;
                $item->status = WishlistItemStatus::Fulfilled;
                $this->entityManager->flush();

                if ($dropper !== null) {
                    $this->notify($dropper, $bookcase, 'notify.wishlist_fulfilled_body', $params, 'notify.wishlist_fulfilled_subject', $bookTitle);
                }

                return null;

            case 'notfound':
                if (!$isRequester) {
                    return ['error' => 'flash.only_requester_missing', 'status' => Response::HTTP_FORBIDDEN];
                }
                if ($item->status !== WishlistItemStatus::Dropped) {
                    return ['error' => 'flash.not_awaiting_pickup', 'status' => Response::HTTP_CONFLICT];
                }
                $comment = $this->clean($comment);
                $dropper = $item->droppedBy;

                // Reopen the wish so another donor can drop it again.
                $item->status = WishlistItemStatus::Open;
                $item->droppedBy = null;
                $this->entityManager->flush();

                if ($dropper !== null) {
                    $this->notify($dropper, $bookcase, 'notify.wishlist_notfound_body', $params, 'notify.wishlist_notfound_subject', $bookTitle, $comment);
                }

                return null;

            default:
                return ['error' => 'flash.unknown_action', 'status' => Response::HTTP_BAD_REQUEST];
        }
    }

    /**
     * The requester withdraws their still-open wish.
     *
     * @return array{error: string, status: int}|null
     */
    public function cancel(WishlistItem $item, User $user): ?array
    {
        if (!$this->isRequester($item, $user)) {
            return ['error' => 'flash.only_requester_cancel', 'status' => Response::HTTP_FORBIDDEN];
        }
        if ($item->status !== WishlistItemStatus::Open) {
            return ['error' => 'flash.only_open_cancel', 'status' => Response::HTTP_CONFLICT];
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        return null;
    }

    private function isRequester(WishlistItem $item, User $user): bool
    {
        return $item->user !== null && (string) $item->user->id === (string) $user->id;
    }

    /**
     * One WishlistMatch notification in the recipient's language; a not-found
     * $comment is appended to the body as a note.
     *
     * @param array<string, mixed> $params
     */
    private function notify(User $recipient, Bookcase $bookcase, string $bodyKey, array $params, string $subjectKey, string $bookTitle, ?string $comment = null): void
    {
        $locale = Locales::isSupported($recipient->language) ? $recipient->language : Locales::DEFAULT;
        $body = $this->translator->trans($bodyKey, $params, 'messages', $locale);
        if ($comment !== null) {
            $body .= "\n" . $this->translator->trans('notify.wishlist_notfound_note', ['%comment%' => $comment], 'messages', $locale);
        }

        $this->messageService->notify(
            $recipient,
            $body,
            MessageType::WishlistMatch,
            $this->translator->trans($subjectKey, ['%title%' => $bookTitle], 'messages', $locale),
            $bookcase,
        );
    }

    /** Trimmed text, or null when blank. */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
