<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\DeletedBookcase;
use App\Entity\OpeningTime;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Enums\MessageType;
use App\Form\BookcaseCreateType;
use App\Form\BookcaseType;
use App\Repository\BookcaseRepository;
use App\Repository\RatingRepository;
use App\Repository\WatchlistItemRepository;
use App\Repository\WishlistItemRepository;
use App\Service\MessageService;
use App\Service\ShortCodeGenerator;

use Doctrine\ORM\EntityManagerInterface;

use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/api/bookcase', name: 'api_bookcase_')]
class BookcaseController extends AbstractController
{
    // Marker paging for the map's bounding-box endpoint: default page size and
    // the hard cap a client may request.
    private const MAP_PAGE_DEFAULT = 1500;
    private const MAP_PAGE_MAX = 5000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookcaseRepository $bookcaseRepository,
        private readonly RatingRepository $ratingRepository,
        private readonly WatchlistItemRepository $watchlistItemRepository,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly MessageService $messageService,
        private readonly SerializerInterface $serializer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ShortCodeGenerator $shortCodeGenerator,
    ) {
    }

    private function isWatching(Bookcase $bookcase): bool
    {
        $user = $this->getUser();

        return $user instanceof User
            && $this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase) !== null;
    }

    /**
     * A flat, human-readable snapshot of the bookcase's content, keyed by the
     * field label shown to watchers. Two snapshots taken around a save are
     * diffed to describe exactly what changed.
     *
     * @return array<string, string>
     */
    private function bookcaseSnapshot(Bookcase $bookcase): array
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
     * @return array{count: int, average: float, rounded: int}
     */
    private function ratingStats(Bookcase $bookcase): array
    {
        $values = array_map(static fn (Rating $r) => (int) $r->value, $bookcase->ratings->toArray());
        $count = count($values);
        $average = $count > 0 ? array_sum($values) / $count : 0.0;

        return [
            'count' => $count,
            'average' => $average,
            'rounded' => (int) round($average),
        ];
    }

    private function currentUserRating(Bookcase $bookcase): int
    {
        $user = $this->getUser();
        if ($user === null) {
            return 0;
        }

        return $this->ratingRepository->findOneBy(['bookcase' => $bookcase, 'user' => $user])?->value ?? 0;
    }

    #[Route('/', name: 'retrieve')]
    public function retrieveBookCases(Request $request): JsonResponse
    {
        $latMin = $request->query->get('latMin');
        $latMax = $request->query->get('latMax');
        $lonMin = $request->query->get('lonMin');
        $lonMax = $request->query->get('lonMax');

        if ($latMin === null || $latMax === null || $lonMin === null || $lonMax === null) {
            return new JsonResponse(['error' => 'Missing bounding box parameters.'], Response::HTTP_BAD_REQUEST);
        }

        // Paged loading: the map fetches markers in batches so it can render
        // progressively (and show progress) instead of waiting for one huge
        // response. `limit` is capped; `offset` walks the result set.
        $limit = max(1, min(self::MAP_PAGE_MAX, (int) $request->query->get('limit', self::MAP_PAGE_DEFAULT)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $total = $this->bookcaseRepository->countByBoundingBox(
            (float) $latMin,
            (float) $latMax,
            (float) $lonMin,
            (float) $lonMax,
        );

        $rows = $this->bookcaseRepository->findByBoundingBoxLight(
            (float) $latMin,
            (float) $latMax,
            (float) $lonMin,
            (float) $lonMax,
            $limit,
            $offset,
        );

        // Build the marker payload directly (same shape the map consumes) instead of
        // hydrating full entities + JMS — keeps the wide-bbox response fast.
        $markers = [];
        foreach ($rows as $row) {
            $mapSymbol = $row['mapSymbol'];
            $entryType = $row['entryType'];
            $activeStatus = $row['activeStatus'];

            // Resolve the accessibility level (enum or raw int from array hydration)
            // to the marker colour the map uses, or null when it isn't set.
            $level = $row['accessibilityLevel'];
            if ($level !== null && !$level instanceof AccessibilityLevel) {
                $level = AccessibilityLevel::tryFrom((int) $level);
            }

            $markers[] = [
                'id' => (string) $row['id'],
                'title' => $row['title'],
                'position' => [
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                ],
                'entryType' => $entryType instanceof EntryType ? $entryType->value : $entryType,
                'mapSymbol' => $mapSymbol instanceof MapSymbol ? $mapSymbol->value : $mapSymbol,
                'status' => $activeStatus instanceof ActiveStatus ? $activeStatus->value : $activeStatus,
                'statusDescription' => $row['statusDescription'],
                'accessibility' => $level instanceof AccessibilityLevel ? $level->markerColor() : null,
                'isMobile' => (bool) $row['isMobile'],
                'isBookcrossingZone' => (bool) $row['isBookcrossingZone'],
                'ratingCount' => (int) $row['ratingCount'],
                'ratingAverage' => $row['ratingAverage'] !== null ? round((float) $row['ratingAverage'], 1) : null,
                'openWishlistCount' => (int) $row['openWishlistCount'],
            ];
        }

        return new JsonResponse([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'markers' => $markers,
        ], Response::HTTP_OK);
    }

    /**
     * Open-data dump of every entry as a downloadable JSON file. Location and contact
     * data only — images and per-user ratings are intentionally excluded. Declared
     * before `/{bookcase}` so the literal path wins over the placeholder route.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $bookcases = [];
        foreach ($this->bookcaseRepository->findAllForExport() as $bc) {
            $bookcases[] = [
                'id' => (string) $bc->id,
                'title' => $bc->title,
                'type' => $bc->entryType->value,
                'status' => $bc->active?->status->value,
                'statusDescription' => $bc->active?->statusDescription,
                'position' => [
                    'latitude' => $bc->position?->latitude,
                    'longitude' => $bc->position?->longitude,
                ],
                'address' => [
                    'street' => $bc->address?->street,
                    'houseNumber' => $bc->address?->houseNumber,
                    'zipcode' => $bc->address?->zipcode,
                    'city' => $bc->address?->city,
                    'additionalData' => $bc->address?->additionalData,
                ],
                'webpage' => $bc->webpage,
                'isMobile' => $bc->isMobile,
                'installationType' => $bc->installationType,
                'digitalMediaAllowed' => $bc->digitalMediaAllowed,
                'accessibility' => [
                    'level' => $bc->accessibility?->level?->value,
                    'description' => $bc->accessibility?->description,
                ],
                'comment' => $bc->comment,
                'openingTimes' => array_map(static fn (OpeningTime $ot) => [
                    'openTime' => $ot->open_time,
                    'twentyFourSeven' => $ot->twenty_for_seven,
                ], $bc->openingTimes->toArray()),
                'caretakers' => array_map(static fn (Caretaker $c) => [
                    'name' => $c->name,
                    'contact' => $c->contact,
                ], $bc->caretakers->toArray()),
            ];
        }

        $response = new JsonResponse([
            'count' => count($bookcases),
            'bookcases' => $bookcases,
        ]);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', 'attachment; filename="openbookcase-export.json"');

        return $response;
    }

    /**
     * Type-ahead suggestions for the map search bar (bookcase titles only;
     * address/place lookup is done client-side via Photon). Declared before
     * `/{bookcase}` so the literal path wins over the placeholder route.
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));

        if (mb_strlen($term) < 2) {
            return new JsonResponse([], Response::HTTP_OK);
        }

        $results = [];
        foreach ($this->bookcaseRepository->searchByTitle($term) as $row) {
            $results[] = [
                'id' => (string) $row['id'],
                'title' => $row['title'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }

        return new JsonResponse($results, Response::HTTP_OK);
    }

    /**
     * Quick-add form fragment (HTML) injected into the bookcase modal. Optional
     * `lat`/`lon` prefill the coordinates (map click / geolocation); `editable=1`
     * unlocks the coordinate inputs + address search (navbar entry point).
     * Declared before `/{bookcase}` so the literal path wins.
     */
    #[Route('/new', name: 'new_html', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function newBookcaseHTML(Request $request): Response
    {
        $bookcase = new Bookcase();

        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');
        if ($lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
            $bookcase->position->latitude = (float) $lat;
            $bookcase->position->longitude = (float) $lon;
        }

        $form = $this->createForm(BookcaseCreateType::class, $bookcase);

        return $this->render('index/new_bookcase.html.twig', [
            'form' => $form->createView(),
            'editable' => $request->query->get('editable') === '1',
        ]);
    }

    /**
     * Create a new entry from the minimal quick-add form. Returns the new id +
     * marker fields so the map can drop a pin without a reload.
     */
    #[Route('/create', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createBookcase(Request $request): JsonResponse
    {
        $bookcase = new Bookcase();
        $form = $this->createForm(BookcaseCreateType::class, $bookcase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bookcase->shortCode = $this->shortCodeGenerator->unique();
            $this->entityManager->persist($bookcase);
            $this->entityManager->flush();

            return new JsonResponse([
                'status' => 'success',
                'id' => (string) $bookcase->id,
                'title' => $bookcase->title,
                'latitude' => $bookcase->position?->latitude,
                'longitude' => $bookcase->position?->longitude,
                'entryType' => $bookcase->entryType->value,
                'mapSymbol' => $bookcase->mapSymbol->value,
            ], Response::HTTP_CREATED);
        }

        return new JsonResponse(['status' => 'error', 'errors' => (string) $form->getErrors(true, false)], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/{bookcase}', name: 'retrieve_single', methods: ['GET'])]
    public function retrieveBookcaseDetails(string $bookcase): JsonResponse
    {
        $bc = $this->bookcaseRepository->findOneWithRelations($bookcase);

        if ($bc === null) {
            return new JsonResponse(null, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(
            $this->serializer->serialize($bc, 'json', SerializationContext::create()->setGroups(['bookcase', 'bookcase_detail', 'caretaker', 'address', 'images'])),
            Response::HTTP_OK,
            json: true,
        );
    }

    /**
     * Soft-delete a bookcase: archive a full snapshot (with the user-supplied
     * reason) into `deleted_bookcase`, then remove it from the live table. The
     * reason is mandatory. Shares the `/{bookcase}` path with the GET single
     * route, distinguished by the DELETE method.
     */
    #[Route('/{bookcase}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteBookcase(Request $request, Bookcase $bookcase): JsonResponse
    {
        $reason = trim((string) (($request->toArray()['reason'] ?? null) ?: ''));

        if ($reason === '') {
            return new JsonResponse(
                ['error' => $this->translator->trans('delete_reason_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user = $this->getUser();

        $backup = new DeletedBookcase();
        $backup->originalId = (string) $bookcase->id;
        $backup->title = $bookcase->title;
        $backup->reason = $reason;
        $backup->deletedBy = $user instanceof User ? $user->getUserIdentifier() : null;
        $backup->payload = json_decode(
            $this->serializer->serialize(
                $bookcase,
                'json',
                SerializationContext::create()->setGroups(['bookcase', 'bookcase_detail', 'caretaker', 'address', 'images']),
            ),
            true,
        ) ?? [];

        $this->entityManager->persist($backup);
        $this->entityManager->remove($bookcase);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted'], Response::HTTP_OK);
    }

    /**
     * Marker payload for a single entity — same shape the map's bbox endpoint
     * emits — so the front-end can refresh/drop a marker after a create or save
     * without a full reload.
     *
     * @return array<string, mixed>
     */
    private function markerPayloadFromEntity(Bookcase $bookcase): array
    {
        $stats = $this->ratingStats($bookcase);

        return [
            'id' => (string) $bookcase->id,
            'title' => $bookcase->title,
            'position' => [
                'latitude' => $bookcase->position?->latitude,
                'longitude' => $bookcase->position?->longitude,
            ],
            'entryType' => $bookcase->entryType->value,
            'mapSymbol' => $bookcase->mapSymbol->value,
            'status' => $bookcase->active?->status->value,
            'statusDescription' => $bookcase->active?->statusDescription,
            'accessibility' => $bookcase->accessibility?->level?->markerColor(),
            'isMobile' => $bookcase->isMobile,
            'isBookcrossingZone' => $bookcase->isBookcrossingZone,
            'ratingCount' => $stats['count'],
            'ratingAverage' => $stats['count'] > 0 ? round($stats['average'], 1) : null,
            'openWishlistCount' => $this->wishlistItemRepository->countOpen($bookcase),
        ];
    }

    #[Route('/{bookcase}/html', name: 'retrieve_single_html')]
    public function retrieveBookcaseDetailsHTML(string $bookcase): Response
    {
        $bc = $this->bookcaseRepository->findOneWithRelations($bookcase);

        if ($bc === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(BookcaseType::class, $bc);

        return $this->render('index/bookcase_detail.html.twig', [
            'bookcase' => $bc,
            'form' => $form->createView(),
            'rating' => $this->ratingStats($bc),
            'userRating' => $this->currentUserRating($bc),
            'isWatching' => $this->isWatching($bc),
            'wishlistOpenCount' => $this->wishlistItemRepository->countOpen($bc),
        ]);
    }

    #[Route('/{bookcase}/edit', name: 'retrieve_edit_html')]
    #[IsGranted('ROLE_USER')]
    public function retrieveBookcaseDetailsEditHTML(string $bookcase): Response
    {
        $bc = $this->bookcaseRepository->findOneWithRelations($bookcase);

        if ($bc === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(BookcaseType::class, $bc);

        return $this->render('index/edit_bookcase.html.twig', [
            'bookcase' => $bc,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{bookcase}/photos', name: 'retrieve_photos_html')]
    #[IsGranted('ROLE_USER')]
    public function retrievePhotosHTML(string $bookcase): Response
    {
        $bc = $this->bookcaseRepository->findOneWithRelations($bookcase);

        if ($bc === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('index/photos_modal.html.twig', ['bookcase' => $bc]);
    }

    #[Route('/{bookcase}/save', name: 'save_bookcase', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveBookCase(Request $request, Bookcase $bookcase): JsonResponse
    {
        // Snapshot the current content before the form mutates the entity in place.
        $before = $this->bookcaseSnapshot($bookcase);

        $form = $this->createForm(BookcaseType::class, $bookcase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($bookcase);
            $this->entityManager->flush();

            $this->notifyWatchersOfChange($bookcase, $before);

            return new JsonResponse(
                ['status' => 'success', 'marker' => $this->markerPayloadFromEntity($bookcase)],
                Response::HTTP_OK,
            );
        }

        return new JsonResponse(['status' => 'error', 'errors' => (string) $form->getErrors(true, false)], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Diff the before/after snapshot and message every watcher (except the
     * editor) about what changed, with a deep link to the entry.
     *
     * @param array<string, string> $before
     */
    private function notifyWatchersOfChange(Bookcase $bookcase, array $before): void
    {
        $after = $this->bookcaseSnapshot($bookcase);

        $changed = [];
        foreach ($after as $label => $value) {
            if (($before[$label] ?? null) !== $value) {
                $changed[] = $label;
            }
        }

        if ($changed === []) {
            return;
        }

        $editor = $this->getUser();
        $editorId = $editor instanceof User && $editor->id !== null ? (string) $editor->id : null;

        $recipients = array_filter(
            $this->watchlistItemRepository->findWatcherUsersOf($bookcase),
            static fn (User $u) => $editorId === null || (string) $u->id !== $editorId,
        );

        if ($recipients === []) {
            return;
        }

        $editorName = $editor instanceof User ? $editor->getUserIdentifier() : 'Someone';
        $title = (string) $bookcase->title;
        $fields = implode(', ', $changed);

        // Translate per recipient so each watcher reads it in their own language.
        foreach ($recipients as $recipient) {
            $loc = $recipient instanceof User ? $recipient->language : null;
            $this->messageService->notify(
                $recipient,
                $this->translator->trans(
                    'notify.bookcase_changed_body',
                    ['%user%' => $editorName, '%title%' => $title, '%fields%' => $fields],
                    'messages',
                    \App\Config\Locales::isSupported($loc) ? $loc : \App\Config\Locales::DEFAULT,
                ),
                MessageType::BookcaseChanged,
                $this->translator->trans(
                    'notify.bookcase_changed_subject',
                    ['%title%' => $title],
                    'messages',
                    \App\Config\Locales::isSupported($loc) ? $loc : \App\Config\Locales::DEFAULT,
                ),
                $bookcase,
            );
        }
    }

    #[Route('/{bookcase}/watch', name: 'watch_add', methods: ['POST'])]
    public function addWatch(Bookcase $bookcase): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if ($this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase) === null) {
            $item = new WatchlistItem();
            $item->user = $user;
            $item->bookcase = $bookcase;
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'success', 'watching' => true], Response::HTTP_OK);
    }

    #[Route('/{bookcase}/watch', name: 'watch_remove', methods: ['DELETE'])]
    public function removeWatch(Bookcase $bookcase): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $item = $this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase);
        if ($item !== null) {
            $this->entityManager->remove($item);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'success', 'watching' => false], Response::HTTP_OK);
    }

    #[Route('/{bookcase}/rating', name: 'rate', methods: ['POST'])]
    public function rate(Request $request, Bookcase $bookcase): JsonResponse
    {
        $user = $this->getUser();
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $value = (int) $request->request->get('value');
        if ($value < 1 || $value > 5) {
            return new JsonResponse(['error' => $this->translator->trans('flash.rating_range')], Response::HTTP_BAD_REQUEST);
        }

        // One rating per user per bookcase: update the existing one or create it.
        $rating = $this->ratingRepository->findOneBy(['bookcase' => $bookcase, 'user' => $user]) ?? new Rating();
        $rating->bookcase = $bookcase;
        $rating->user = $user;
        $rating->value = $value;

        $this->entityManager->persist($rating);
        $this->entityManager->flush();

        // ratings is a lazy collection on the freshly-resolved bookcase, so reading it
        // here loads the current rows from the DB (including the one just saved).
        $stats = $this->ratingStats($bookcase);

        return new JsonResponse([
            'status' => 'success',
            'userValue' => $value,
            'average' => $stats['average'],
            'rounded' => $stats['rounded'],
            'count' => $stats['count'],
        ], Response::HTTP_OK);
    }

    /**
     * Persist a new position after the user drags the marker on the map (and
     * confirms the move). Position-only — keeps the payload tiny vs the full
     * save form — but still notifies watchers, since location is a key field.
     */
    #[Route('/{bookcase}/position', name: 'move', methods: ['POST'])]
    public function move(Request $request, Bookcase $bookcase): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $lat = $request->request->get('latitude');
        $lon = $request->request->get('longitude');
        if (!is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lon < -180 || (float) $lon > 180) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_position')], Response::HTTP_BAD_REQUEST);
        }

        $before = $this->bookcaseSnapshot($bookcase);

        $bookcase->position->latitude = (float) $lat;
        $bookcase->position->longitude = (float) $lon;
        $this->entityManager->flush();

        $this->notifyWatchersOfChange($bookcase, $before);

        return new JsonResponse([
            'status' => 'success',
            'latitude' => $bookcase->position->latitude,
            'longitude' => $bookcase->position->longitude,
        ], Response::HTTP_OK);
    }
}
