<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Form\BookcaseCreateType;
use App\Form\BookcaseType;
use App\Repository\BookcaseRepository;
use App\Repository\WishlistItemRepository;
use App\Service\BookcaseExportService;
use App\Service\BookcaseMarkerService;
use App\Service\BookcaseService;
use App\Service\RatingService;
use App\Service\WatchlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
        private readonly BookcaseRepository $bookcaseRepository,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly BookcaseService $bookcaseService,
        private readonly BookcaseMarkerService $markerService,
        private readonly BookcaseExportService $exportService,
        private readonly RatingService $ratingService,
        private readonly WatchlistService $watchlistService,
        private readonly TranslatorInterface $translator,
    ) {
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

        $total = $this->bookcaseRepository->countByBoundingBox((float) $latMin, (float) $latMax, (float) $lonMin, (float) $lonMax);
        $rows = $this->bookcaseRepository->findByBoundingBoxLight(
            (float) $latMin,
            (float) $latMax,
            (float) $lonMin,
            (float) $lonMax,
            $limit,
            $offset,
        );

        // Marker payload built from light array rows (no entity hydration / JMS)
        // — keeps the wide-bbox response fast.
        return new JsonResponse([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'markers' => array_map($this->markerService->fromRow(...), $rows),
        ], Response::HTTP_OK);
    }

    /**
     * Open-data dump of every entry as a downloadable, streamed JSON file (see
     * {@see BookcaseExportService}). `?gzip=1` → `.json.gz`. Declared before
     * `/{bookcase}` so the literal path wins over the placeholder route.
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request, #[CurrentUser] ?User $user): Response
    {
        $compress = $request->query->getBoolean('gzip');

        $response = new StreamedResponse($this->exportService->streamCallback($request, $user));
        $filename = $compress ? 'openbookcase-export.json.gz' : 'openbookcase-export.json';
        $response->headers->set('Content-Type', $compress ? 'application/gzip' : 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        // Don't let a reverse proxy buffer the whole stream before flushing.
        $response->headers->set('X-Accel-Buffering', 'no');

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

        return new JsonResponse(array_map(static fn (array $row) => [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
        ], $this->bookcaseRepository->searchByTitle($term)), Response::HTTP_OK);
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
            $this->bookcaseService->create($bookcase);

            return new JsonResponse($this->markerService->created($bookcase), Response::HTTP_CREATED);
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

        return new JsonResponse($this->bookcaseService->detailJson($bc), Response::HTTP_OK, json: true);
    }

    /**
     * Soft-delete a bookcase: archive a full snapshot (with the user-supplied
     * reason) into `deleted_bookcase`, then remove it from the live table. The
     * reason is mandatory. Shares the `/{bookcase}` path with the GET single
     * route, distinguished by the DELETE method.
     */
    #[Route('/{bookcase}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteBookcase(Request $request, Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        $reason = $request->toArray()['reason'] ?? null;
        $reason = is_string($reason) ? trim($reason) : ''; // non-strings ({"a":1}, [..]) are no reason

        if ($reason === '') {
            return new JsonResponse(
                ['error' => $this->translator->trans('delete_reason_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->bookcaseService->archiveAndDelete($bookcase, $reason, $user);

        return new JsonResponse(['status' => 'deleted'], Response::HTTP_OK);
    }

    #[Route('/{bookcase}/html', name: 'retrieve_single_html')]
    public function retrieveBookcaseDetailsHTML(string $bookcase, #[CurrentUser] ?User $user): Response
    {
        $bc = $this->bookcaseRepository->findOneWithRelations($bookcase);

        if ($bc === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(BookcaseType::class, $bc);

        return $this->render('index/bookcase_detail.html.twig', [
            'bookcase' => $bc,
            'form' => $form->createView(),
            'rating' => $this->ratingService->stats($bc),
            'userRating' => $this->ratingService->userValue($bc, $user),
            'isWatching' => $this->watchlistService->isWatching($bc, $user),
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
    public function saveBookCase(Request $request, Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        // Snapshot the current content before the form mutates the entity in place.
        $before = $this->bookcaseService->snapshot($bookcase);

        $form = $this->createForm(BookcaseType::class, $bookcase);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bookcaseService->saveEdited($bookcase, $before, $user);

            return new JsonResponse(
                ['status' => 'success', 'marker' => $this->markerService->fromEntity($bookcase)],
                Response::HTTP_OK,
            );
        }

        return new JsonResponse(['status' => 'error', 'errors' => (string) $form->getErrors(true, false)], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/{bookcase}/watch', name: 'watch_add', methods: ['POST'])]
    public function addWatch(Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $this->watchlistService->watch($bookcase, $user);

        return new JsonResponse(['status' => 'success', 'watching' => true], Response::HTTP_OK);
    }

    #[Route('/{bookcase}/watch', name: 'watch_remove', methods: ['DELETE'])]
    public function removeWatch(Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $this->watchlistService->unwatch($bookcase, $user);

        return new JsonResponse(['status' => 'success', 'watching' => false], Response::HTTP_OK);
    }

    #[Route('/{bookcase}/rating', name: 'rate', methods: ['POST'])]
    public function rate(Request $request, Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $value = (int) $request->request->get('value');
        if ($value < 1 || $value > 5) {
            return new JsonResponse(['error' => $this->translator->trans('flash.rating_range')], Response::HTTP_BAD_REQUEST);
        }

        $this->ratingService->upsert($bookcase, $user, $value);
        $stats = $this->ratingService->stats($bookcase);

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
    public function move(Request $request, Bookcase $bookcase, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        $lat = $request->request->get('latitude');
        $lon = $request->request->get('longitude');
        if (!is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lon < -180 || (float) $lon > 180) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_position')], Response::HTTP_BAD_REQUEST);
        }

        $this->bookcaseService->move($bookcase, (float) $lat, (float) $lon, $user);

        return new JsonResponse([
            'status' => 'success',
            'latitude' => $bookcase->position->latitude,
            'longitude' => $bookcase->position->longitude,
        ], Response::HTTP_OK);
    }
}
