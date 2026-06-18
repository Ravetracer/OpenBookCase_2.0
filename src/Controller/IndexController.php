<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\BookcaseType;
use App\Repository\BookcaseRepository;
use App\Repository\WatchlistItemRepository;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly WatchlistItemRepository $watchlistItemRepository,
    ) {
    }

    // `/map` is served directly (HTTP 200, no redirect) so the many legacy
    // backlinks to https://openbookcase.de/map keep resolving and pass their
    // link equity. base.html.twig emits a rel=canonical pointing at `/` so
    // Google consolidates both URLs into one ranking signal (no duplicate
    // content), while the inbound links still count.
    #[Route('/', name: 'app_index')]
    #[Route('/map', name: 'app_index_map')]
    #[Route('/index', name: 'app_index_legacy')]
    public function index(): Response
    {
        $form = $this->createForm(BookcaseType::class);

        return $this->render('index/index.html.twig', [
            'controller_name' => 'IndexController',
            'bookcase_form' => $form->createView(),
            'watchedIds' => $this->watchedIds(),
        ]);
    }

    #[Route('/bookcase/{bookcase}', name: 'app_bookcase_show')]
    public function showBookcase(string $bookcase, BookcaseRepository $bookcaseRepository): Response
    {
        // Shareable deep link: render the map, then let the frontend center on the
        // entry and open its detail dialog. Unknown ids just fall back to the map.
        $bc = $bookcaseRepository->findOneWithRelations($bookcase);

        return $this->render('index/index.html.twig', [
            'initialBookcase' => $bc,
            'watchedIds' => $this->watchedIds(),
        ]);
    }

    /**
     * Bookcase ids the current user watches — seeds the map's "only watched"
     * filter. Empty for anonymous visitors.
     *
     * @return string[]
     */
    private function watchedIds(): array
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->watchlistItemRepository->findWatchedBookcaseIds($user) : [];
    }

    /**
     * Short share link target (https://obc.onl/{code} → /s/{code}). Resolves the
     * code to a bookcase and renders the same map deep link. Falls back to the
     * legacy numeric id so old shortener links keep working.
     */
    #[Route('/s/{code}', name: 'app_short_link', requirements: ['code' => '[0-9A-Za-z]+'])]
    public function shortLink(string $code, BookcaseRepository $bookcaseRepository): Response
    {
        $bc = $bookcaseRepository->findOneBy(['shortCode' => $code]);

        if ($bc === null && ctype_digit($code)) {
            $bc = $bookcaseRepository->findOneBy(['legacyId' => (int) $code]);
        }

        return $this->render('index/index.html.twig', [
            'initialBookcase' => $bc,
            'watchedIds' => $this->watchedIds(),
        ]);
    }

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    private const SORT_KEYS = ['title', 'address', 'type', 'status', 'latitude', 'longitude', 'distance', 'newest'];

    #[Route('/list', name: 'app_list')]
    public function list(Request $request, BookcaseRepository $bookcaseRepository): Response
    {
        return $this->render('index/list.html.twig', $this->listView($request, $bookcaseRepository));
    }

    /**
     * Just the table + pagination, for the live (AJAX) search/sort/paginate
     * swaps done by the `list` Stimulus controller.
     */
    #[Route('/list/fragment', name: 'app_list_fragment')]
    public function listFragment(Request $request, BookcaseRepository $bookcaseRepository): Response
    {
        return $this->render('index/_list_table.html.twig', $this->listView($request, $bookcaseRepository));
    }

    /**
     * Shared list state: parse + validate query params, paginate the filtered
     * result, and (when the user's location is known) sort by distance and
     * compute each visible row's distance in km.
     *
     * @return array<string, mixed>
     */
    private function listView(Request $request, BookcaseRepository $bookcaseRepository): array
    {
        $q = trim((string) $request->query->get('q', ''));

        $sort = (string) $request->query->get('sort', 'title');
        if (!in_array($sort, self::SORT_KEYS, true)) {
            $sort = 'title';
        }
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query->get('perPage', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        // Distance sort needs the user's coordinates; without them, fall back to title.
        $userLat = $this->floatOrNull($request->query->get('userLat'));
        $userLon = $this->floatOrNull($request->query->get('userLon'));
        $hasLocation = $userLat !== null && $userLon !== null;
        if ($sort === 'distance' && !$hasLocation) {
            $sort = 'title';
        }
        $cosLat = $hasLocation ? cos(deg2rad($userLat)) : null;

        // "Newest additions" is scoped to community-contributed entries so a recent
        // bulk OpenStreetMap import can't dominate the list (the user's intent).
        $communityOnly = $sort === 'newest';

        $total = $bookcaseRepository->countFiltered($q, $communityOnly);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query->get('page', 1), $totalPages));

        $bookcases = $bookcaseRepository->findFilteredPaginated(
            $q !== '' ? $q : null,
            $sort,
            $dir,
            $userLat,
            $userLon,
            $cosLat,
            $perPage,
            ($page - 1) * $perPage,
            $communityOnly,
        );

        // Per-row distance (km) for the visible page only — accurate Haversine.
        $distances = [];
        if ($hasLocation) {
            foreach ($bookcases as $bc) {
                if ($bc->position?->latitude !== null && $bc->position?->longitude !== null) {
                    $distances[(string) $bc->id] = $this->haversineKm(
                        $userLat,
                        $userLon,
                        (float) $bc->position->latitude,
                        (float) $bc->position->longitude,
                    );
                }
            }
        }

        return [
            'bookcases' => $bookcases,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'q' => $q,
            'sort' => $sort,
            'dir' => $dir,
            'communityOnly' => $communityOnly,
            'hasLocation' => $hasLocation,
            'userLat' => $userLat,
            'userLon' => $userLon,
            'distances' => $distances,
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** Great-circle distance between two lat/lon points, in kilometres. */
    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }
}
