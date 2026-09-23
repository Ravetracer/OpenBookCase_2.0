<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Model\BookcaseFilter;
use App\Repository\BookcaseRepository;
use App\Repository\WatchlistItemRepository;

use Symfony\Component\HttpFoundation\Request;

/**
 * View data for the public map and list pages (IndexController): the `/list`
 * table state (search, sort, pagination, distance), the watched-ids seed for the
 * map filter, and short-link resolution.
 */
class BookcaseListService
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    public const SORT_KEYS = ['title', 'address', 'type', 'status', 'latitude', 'longitude', 'distance', 'newest'];

    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly BookcaseRepository $bookcases,
        private readonly WatchlistItemRepository $watchlistItems,
    ) {
    }

    /**
     * Bookcase ids the user watches — seeds the map's "only watched" filter.
     * Empty for anonymous visitors.
     *
     * @return string[]
     */
    public function watchedIds(?User $user): array
    {
        return $user !== null ? $this->watchlistItems->findWatchedBookcaseIds($user) : [];
    }

    /**
     * Resolve a short share code (https://obc.onl/{code}) to its bookcase. Falls
     * back to the legacy numeric id so old shortener links keep working.
     */
    public function findByShortCode(string $code): ?Bookcase
    {
        $bookcase = $this->bookcases->findOneBy(['shortCode' => $code]);

        if ($bookcase === null && ctype_digit($code)) {
            $bookcase = $this->bookcases->findOneBy(['legacyId' => (int) $code]);
        }

        return $bookcase;
    }

    /**
     * Shared list state: parse + validate query params, paginate the filtered
     * result, and (when the user's location is known) sort by distance and
     * compute each visible row's distance in km.
     *
     * @return array<string, mixed>
     */
    public function listView(Request $request, ?User $user): array
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

        $filter = BookcaseFilter::fromRequest($request);

        // "Newest additions" hides bulk OpenStreetMap imports by default so a recent
        // import batch can't dominate the list — unless the user explicitly chooses
        // an OSM provenance mode.
        if ($sort === 'newest' && $filter->osm === 'with') {
            $filter = $filter->withOsm('without');
        }
        $communityOnly = $sort === 'newest' && $filter->osm === 'without';

        $watcherId = $user?->id !== null ? (string) $user->id : null;

        $total = $this->bookcases->countFiltered($q !== '' ? $q : null, $filter, $watcherId);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query->get('page', 1), $totalPages));

        $bookcases = $this->bookcases->findFilteredPaginated(
            $q !== '' ? $q : null,
            $sort,
            $dir,
            $userLat,
            $userLon,
            $cosLat,
            $perPage,
            ($page - 1) * $perPage,
            $filter,
            $watcherId,
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
            'filter' => $filter,
            // Canonical filter query params + free-text/sort, for the "export with
            // current filters" links (the JS keeps them current as filters change).
            'exportParams' => $filter->toQueryParams() + array_filter([
                'q' => $q,
                'sort' => $sort,
                'dir' => $dir,
                'userLat' => $userLat !== null ? (string) $userLat : null,
                'userLon' => $userLon !== null ? (string) $userLon : null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /** Great-circle distance between two lat/lon points, in kilometres. */
    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /** A finite float from a query value, else null (rejects INF/NAN such as "1e999"). */
    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
