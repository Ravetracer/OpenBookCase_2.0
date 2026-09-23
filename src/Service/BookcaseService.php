<?php declare(strict_types=1);

namespace App\Service;

use App\Config\Locales;
use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\DeletedBookcase;
use App\Entity\OpeningTime;
use App\Entity\User;
use App\Enums\MessageType;
use App\Repository\WatchlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bookcase write operations shared by the website (BookcaseController) and the
 * public API (Api\V1\BookcaseApiController): create, edit-save, move, soft-delete,
 * the detail JSON, and the watcher notifications that describe what changed.
 */
class BookcaseService
{
    private const DETAIL_GROUPS = ['bookcase', 'bookcase_detail', 'caretaker', 'address', 'images'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
        private readonly TranslatorInterface $translator,
        private readonly WatchlistItemRepository $watchlistItemRepository,
        private readonly MessageService $messageService,
        private readonly ShortCodeGenerator $shortCodeGenerator,
    ) {
    }

    /** Persist a new entry with a fresh unique short code. */
    public function create(Bookcase $bookcase): void
    {
        $bookcase->shortCode = $this->shortCodeGenerator->unique();
        $this->entityManager->persist($bookcase);
        $this->entityManager->flush();
    }

    /**
     * Save changes made to a managed entry (API PATCH) and tell its watchers what
     * changed. `$before` is the {@see snapshot()} taken before the changes.
     *
     * @param array<string, string> $before
     */
    public function save(Bookcase $bookcase, array $before, ?User $editor): void
    {
        $this->entityManager->persist($bookcase);
        $this->entityManager->flush();

        $this->notifyWatchersOfChange($bookcase, $before, $editor);
    }

    /** The single-bookcase JSON (JMS detail groups), as served by both the website and the API. */
    public function detailJson(Bookcase $bookcase): string
    {
        return $this->serializer->serialize($bookcase, 'json', SerializationContext::create()->setGroups(self::DETAIL_GROUPS));
    }

    /**
     * Save an entry edited through the full form and tell its watchers what
     * changed. `$before` is the {@see snapshot()} taken before the form ran.
     *
     * @param array<string, string> $before
     */
    public function saveEdited(Bookcase $bookcase, array $before, ?User $editor): void
    {
        // The user has reviewed/confirmed the entry, so its title is no longer a
        // provisional auto-generated one (clears the OSM "help name this" prompt).
        $bookcase->titleProvisional = false;

        $this->entityManager->persist($bookcase);
        $this->entityManager->flush();

        $this->notifyWatchersOfChange($bookcase, $before, $editor);
    }

    /** Set a new position (website marker drag + API) and tell the watchers. */
    public function move(Bookcase $bookcase, float $latitude, float $longitude, ?User $editor): void
    {
        $before = $this->snapshot($bookcase);

        $bookcase->position->latitude = $latitude;
        $bookcase->position->longitude = $longitude;
        $this->entityManager->flush();

        $this->notifyWatchersOfChange($bookcase, $before, $editor);
    }

    /**
     * Soft-delete: archive a full snapshot plus the reason into `deleted_bookcase`,
     * then remove the live entry.
     */
    public function archiveAndDelete(Bookcase $bookcase, string $reason, ?User $user): void
    {
        $backup = new DeletedBookcase();
        $backup->originalId = (string) $bookcase->id;
        $backup->title = $bookcase->title;
        $backup->reason = $reason;
        $backup->deletedBy = $user?->getUserIdentifier();
        $backup->payload = json_decode($this->detailJson($bookcase), true) ?? [];

        $this->entityManager->persist($backup);
        $this->entityManager->remove($bookcase);
        $this->entityManager->flush();
    }

    /**
     * A flat, human-readable snapshot of the bookcase's content, keyed by the
     * field label shown to watchers. Two snapshots taken around a save are
     * diffed to describe exactly what changed.
     *
     * @return array<string, string>
     */
    public function snapshot(Bookcase $bookcase): array
    {
        $address = $bookcase->address;
        $accessibility = $bookcase->accessibility;
        $active = $bookcase->active;

        $openingTimes = array_map(
            static fn (OpeningTime $ot) => ($ot->twenty_for_seven ? '24/7' : (string) $ot->open_time),
            $bookcase->openingTimes->toArray(),
        );
        $caretakers = array_map(
            static fn (Caretaker $c) => trim($c->name . ' / ' . $c->contact),
            $bookcase->caretakers->toArray(),
        );

        return [
            'Title' => (string) $bookcase->title,
            'Type' => $bookcase->entryType->value,
            'Map symbol' => $bookcase->mapSymbol->value,
            'Position' => $bookcase->position?->latitude . ', ' . $bookcase->position?->longitude,
            'Address' => trim(implode(' ', array_filter([
                $address?->street, $address?->houseNumber, $address?->zipcode, $address?->city, $address?->additionalData,
            ]))),
            'Webpage' => (string) $bookcase->webpage,
            'Mobility' => $bookcase->isMobile ? 'mobile' : 'fixed',
            'Installation type' => (string) $bookcase->installationType,
            'Digital media allowed' => $bookcase->digitalMediaAllowed ? 'yes' : 'no',
            'Accessibility' => trim(((string) ($accessibility?->level?->value ?? '')) . ' ' . ($accessibility?->description ?? '')),
            'Status' => ($active?->status->value ?? '') . ' ' . ($active?->statusDescription ?? ''),
            'Comment' => (string) $bookcase->comment,
            'Opening times' => implode(' | ', $openingTimes),
            'Caretakers' => implode(' | ', $caretakers),
        ];
    }

    /**
     * Labels whose value differs between two snapshots.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return list<string>
     */
    public function changedFields(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $label => $value) {
            if (($before[$label] ?? null) !== $value) {
                $changed[] = $label;
            }
        }

        return $changed;
    }

    /**
     * Diff the before/after snapshot and message every watcher (except the
     * editor) about what changed, with a deep link to the entry.
     *
     * @param array<string, string> $before
     */
    private function notifyWatchersOfChange(Bookcase $bookcase, array $before, ?User $editor): void
    {
        $changed = $this->changedFields($before, $this->snapshot($bookcase));
        if ($changed === []) {
            return;
        }

        $editorId = $editor?->id !== null ? (string) $editor->id : null;

        $recipients = array_filter(
            $this->watchlistItemRepository->findWatcherUsersOf($bookcase),
            static fn (User $u) => $editorId === null || (string) $u->id !== $editorId,
        );

        if ($recipients === []) {
            return;
        }

        $editorName = $editor?->getUserIdentifier() ?? 'Someone';
        $title = (string) $bookcase->title;
        $fields = implode(', ', $changed);

        // Translate per recipient so each watcher reads it in their own language.
        foreach ($recipients as $recipient) {
            $locale = Locales::isSupported($recipient->language) ? $recipient->language : Locales::DEFAULT;
            $this->messageService->notify(
                $recipient,
                $this->translator->trans(
                    'notify.bookcase_changed_body',
                    ['%user%' => $editorName, '%title%' => $title, '%fields%' => $fields],
                    'messages',
                    $locale,
                ),
                MessageType::BookcaseChanged,
                $this->translator->trans('notify.bookcase_changed_subject', ['%title%' => $title], 'messages', $locale),
                $bookcase,
            );
        }
    }
}
