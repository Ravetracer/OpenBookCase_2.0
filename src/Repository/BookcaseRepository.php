<?php

namespace App\Repository;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\WishlistItemStatus;
use App\Model\BookcaseFilter;
use App\Model\GeoBox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<Bookcase>
 *
 * @method Bookcase|null find($id, $lockMode = null, $lockVersion = null)
 * @method Bookcase|null findOneBy(array $criteria, array $orderBy = null)
 * @method Bookcase[]    findAll()
 * @method Bookcase[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BookcaseRepository extends ServiceEntityRepository
{
    /** Latitude span (degrees) from which the map's bbox read scans by primary key instead of the lat/lon indexes. */
    private const WIDE_BOX_LAT_SPAN = 10.0;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookcase::class);
    }

    /**
     * @return Bookcase[]
     */
    public function findByBoundingBox(
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
    ): array {
        return $this->createQueryBuilder('bc')
            ->where('bc.position.latitude >= :latMin')
            ->andWhere('bc.position.latitude <= :latMax')
            ->andWhere('bc.position.longitude >= :lonMin')
            ->andWhere('bc.position.longitude <= :lonMax')
            ->setParameter('latMin', $latMin)
            ->setParameter('latMax', $latMax)
            ->setParameter('lonMin', $lonMin)
            ->setParameter('lonMax', $lonMax)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lightweight bounding-box read for the map: only the fields a marker needs.
     *
     * Plain DBAL, not DQL: at zoomed-out views a page is thousands of rows, and
     * ORM scalar hydration (enum/ULID conversion per column) cost ~3x the query
     * itself. Enum columns therefore come back as their raw backing values
     * ({@see \App\Service\BookcaseMarkerService::fromRow()} accepts both).
     * Ratings and open wishes are aggregated by per-row subselects rather than
     * joins + GROUP BY, so a page only aggregates its own rows.
     *
     * Paging: `$after` (keyset — the last id of the previous page) never rescans
     * skipped rows; `$offset` remains for the public API. `$exclude` drops
     * everything inside an already-loaded box.
     *
     * @return list<array{id: string, title: string, latitude: float, longitude: float, entryType: string, mapSymbol: string, activeStatus: string, statusDescription: ?string, accessibilityLevel: ?int, isMobile: bool, isBookcrossingZone: bool, source: ?string, ratingAverage: ?float, ratingCount: int, openWishlistCount: int}>
     */
    public function findByBoundingBoxLight(
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
        ?int $limit = null,
        int $offset = 0,
        ?GeoBox $exclude = null,
        ?Ulid $after = null,
    ): array {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select(
                'b.id',
                'b.title',
                'b.position_latitude AS latitude',
                'b.position_longitude AS longitude',
                'b.entry_type AS entryType',
                'b.map_symbol AS mapSymbol',
                'b.active_status AS activeStatus',
                'b.active_status_description AS statusDescription',
                'b.accessibility_level AS accessibilityLevel',
                'b.is_mobile AS isMobile',
                'b.is_bookcrossing_zone AS isBookcrossingZone',
                'b.source',
                '(SELECT AVG(r.value) FROM rating r WHERE r.bookcase_id = b.id) AS ratingAverage',
                '(SELECT COUNT(*) FROM rating r WHERE r.bookcase_id = b.id) AS ratingCount',
                // Only count still-open wishes so the marker can flag "wishes wanted here".
                '(SELECT COUNT(*) FROM wishlist_item w WHERE w.bookcase_id = b.id AND w.status = :open) AS openWishlistCount',
            )
            ->from('bookcase', 'b')
            ->setParameter('open', WishlistItemStatus::Open->value);
        // A wide (zoomed-out) box matches most rows, so walking the primary key
        // in id order beats the latitude index + a full sort on every page
        // (~7x on 60k rows). The unary `+` keeps the planner off the lat/lon
        // indexes; a narrow box keeps using them.
        $wide = $limit !== null && $latMax - $latMin >= self::WIDE_BOX_LAT_SPAN;
        $this->applyBoundingBox($qb, $latMin, $latMax, $lonMin, $lonMax, $exclude, $wide);

        if ($after !== null) {
            $qb->andWhere('b.id > :after')->setParameter('after', $after->toBinary(), ParameterType::BINARY);
        }

        // Stable order so paging never skips or repeats a row.
        if ($limit !== null || $after !== null) {
            $qb->orderBy('b.id', 'ASC');
        }
        if ($limit !== null) {
            $qb->setFirstResult($after !== null ? 0 : max(0, $offset))
                ->setMaxResults($limit);
        }

        return array_map(static fn (array $row): array => [
            'id' => self::ulidKey($row['id']),
            'title' => $row['title'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'entryType' => $row['entryType'],
            'mapSymbol' => $row['mapSymbol'],
            'activeStatus' => $row['activeStatus'],
            'statusDescription' => $row['statusDescription'],
            'accessibilityLevel' => $row['accessibilityLevel'] !== null ? (int) $row['accessibilityLevel'] : null,
            'isMobile' => (bool) $row['isMobile'],
            'isBookcrossingZone' => (bool) $row['isBookcrossingZone'],
            'source' => $row['source'],
            'ratingAverage' => $row['ratingAverage'] !== null ? (float) $row['ratingAverage'] : null,
            'ratingCount' => (int) $row['ratingCount'],
            'openWishlistCount' => (int) $row['openWishlistCount'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Count of entries inside a bounding box (minus an optional already-loaded
     * box). Lets the map show a determinate progress bar while it pages markers
     * in. No joins — just the indexed lat/lon range — so it's cheap.
     */
    public function countByBoundingBox(
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
        ?GeoBox $exclude = null,
    ): int {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('bookcase', 'b');
        $this->applyBoundingBox($qb, $latMin, $latMax, $lonMin, $lonMax, $exclude);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * All bookcases with caretakers & opening times eager-loaded for the open-data
     * JSON export. Images and ratings are intentionally NOT fetched — the export is
     * location/contact data only.
     *
     * @return Bookcase[]
     */
    /**
     * Stream every bookcase one entity at a time (no collection fetch-joins, so
     * `toIterable()` is allowed). The full export is ~56k rows — hydrating them
     * all at once exhausts memory (HTTP 500), so the controller iterates this and
     * clears the EM periodically. Caretakers + opening times are resolved from the
     * lookup maps below rather than lazy-loaded (which would be 100k+ queries).
     *
     * @return iterable<Bookcase>
     */
    public function iterateForExport(): iterable
    {
        return $this->createQueryBuilder('bc')
            ->orderBy('bc.title', SortDirection::Ascending)
            ->getQuery()
            ->toIterable();
    }

    /**
     * Streaming export that mirrors the list view's free-text search, filters and
     * sort, so "export with current filters & sorting" produces exactly the rows
     * the user is looking at. Same memory profile as {@see iterateForExport()} —
     * no collection fetch-joins, so `toIterable()` is valid.
     *
     * @return iterable<Bookcase>
     */
    public function iterateFilteredForExport(
        ?string $q,
        string $sortKey,
        string $dir,
        ?float $uLat,
        ?float $uLon,
        ?float $cosLat,
        BookcaseFilter $filter,
        ?string $watcherId = null,
    ): iterable {
        $qb = $this->createQueryBuilder('bc');
        $this->applyListFilter($qb, $q);
        $this->applyFilterSet($qb, $filter, $watcherId);
        $this->applySort($qb, $sortKey, $dir, $uLat, $uLon, $cosLat);

        return $qb->getQuery()->toIterable();
    }

    /**
     * Caretakers grouped by bookcase ULID (string form), in one bulk query.
     *
     * @return array<string, list<array{name: ?string, contact: ?string}>>
     */
    public function exportCaretakerMap(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT bc.bookcase_id AS bid, c.name AS name, c.contact AS contact
               FROM bookcase_caretaker bc
               JOIN caretaker c ON c.id = bc.caretaker_id',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[self::ulidKey($row['bid'])][] = [
                'name' => $row['name'],
                'contact' => $row['contact'],
            ];
        }

        return $map;
    }

    /**
     * Opening times grouped by bookcase ULID (string form), in one bulk query.
     *
     * @return array<string, list<array{openTime: ?string, twentyFourSeven: ?bool}>>
     */
    public function exportOpeningTimeMap(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT bookcase_id AS bid, open_time AS open_time, twenty_for_seven AS tfs
               FROM opening_time
              WHERE bookcase_id IS NOT NULL',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[self::ulidKey($row['bid'])][] = [
                'openTime' => $row['open_time'],
                'twentyFourSeven' => $row['tfs'] === null ? null : (bool) $row['tfs'],
            ];
        }

        return $map;
    }

    /**
     * Normalise a raw binary ULID column value (BLOB) to the same canonical
     * string `(string) $bookcase->id` produces, so the maps key consistently.
     */
    private static function ulidKey(mixed $raw): string
    {
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }

        return (string) Ulid::fromBinary($raw);
    }

    /**
     * Type-ahead search by title for the map search bar. Case-insensitive
     * substring match; returns just the data the suggestion list + flyTo need.
     *
     * @return array<int, array{id: string, title: string, latitude: float, longitude: float}>
     */
    public function searchByTitle(string $term, int $limit = 8): array
    {
        return $this->createQueryBuilder('bc')
            ->select(
                'bc.id AS id',
                'bc.title AS title',
                'bc.position.latitude AS latitude',
                'bc.position.longitude AS longitude',
            )
            ->where('LOWER(bc.title) LIKE :term')
            ->setParameter('term', '%' . mb_strtolower(mb_substr($term, 0, 200)) . '%')
            ->orderBy('bc.title', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /** Maps a public sort key to its DQL column (whitelist guards against injection). */
    private const SORT_COLUMNS = [
        'title' => 'bc.title',
        'address' => 'bc.address.additionalData',
        'type' => 'bc.entryType',
        'status' => 'bc.active.status',
        'latitude' => 'bc.position.latitude',
        'longitude' => 'bc.position.longitude',
        // ULIDs are time-ordered, so id order == creation order ("Added" column).
        'newest' => 'bc.id',
    ];

    /**
     * Apply the list-view free-text filter (title + every address field) to a
     * query builder. Shared by the paginated fetch and its count.
     */
    private function applyListFilter(\Doctrine\ORM\QueryBuilder $qb, ?string $q): void
    {
        $q = $q !== null ? trim($q) : '';
        if ($q === '') {
            return;
        }

        // Term capped at 200 chars: SQLite rejects over-long LIKE patterns ("pattern too complex").
        $qb->andWhere(
            '(LOWER(bc.title) LIKE :q'
            . ' OR LOWER(bc.address.street) LIKE :q'
            . ' OR LOWER(bc.address.houseNumber) LIKE :q'
            . ' OR LOWER(bc.address.zipcode) LIKE :q'
            . ' OR LOWER(bc.address.city) LIKE :q'
            . ' OR LOWER(bc.address.additionalData) LIKE :q)'
        )->setParameter('q', '%' . mb_strtolower(mb_substr($q, 0, 200)) . '%');
    }

    /**
     * Apply the map-style filter set (accessibility / status / type / mobility /
     * minimum rating / open-wishes / bookcrossing / watched / OSM provenance) to a
     * query builder. Each dimension is a plain WHERE clause — correlated EXISTS /
     * scalar subqueries for the rating & relation filters — so no GROUP BY is
     * needed and the count query stays a simple COUNT. $watcherId is the current
     * user's ULID (string); the "watched" filter matches nothing without it.
     */
    private function applyFilterSet(QueryBuilder $qb, BookcaseFilter $filter, ?string $watcherId): void
    {
        // Accessibility: colour tokens → traffic-light levels; 'unset' = no level set.
        if (count($filter->accessibility) !== count(BookcaseFilter::ACCESSIBILITY)) {
            if ($filter->accessibility === []) {
                $qb->andWhere('1 = 0');
            } else {
                $levels = [];
                $unset = false;
                foreach ($filter->accessibility as $token) {
                    match ($token) {
                        'green' => $levels[] = AccessibilityLevel::Full->value,
                        'yellow' => $levels[] = AccessibilityLevel::Partial->value,
                        'red' => $levels[] = AccessibilityLevel::None->value,
                        'unset' => $unset = true,
                        default => null,
                    };
                }
                $or = [];
                if ($levels !== []) {
                    $or[] = 'bc.accessibility.level IN (:accLevels)';
                    $qb->setParameter('accLevels', $levels);
                }
                if ($unset) {
                    $or[] = 'bc.accessibility.level IS NULL';
                }
                $qb->andWhere('(' . implode(' OR ', $or) . ')');
            }
        }

        // Status (active / inactive) and entry type (bookcase / givebox).
        $this->applyInFilter($qb, 'bc.active.status', $filter->status, BookcaseFilter::STATUS, 'fStatus');
        $this->applyInFilter($qb, 'bc.entryType', $filter->types, BookcaseFilter::TYPES, 'fType');

        // Mobility (fixed = not mobile, mobile = mobile).
        if (count($filter->mobility) !== count(BookcaseFilter::MOBILITY)) {
            if ($filter->mobility === []) {
                $qb->andWhere('1 = 0');
            } elseif (in_array('mobile', $filter->mobility, true)) {
                $qb->andWhere('bc.isMobile = true');
            } else {
                $qb->andWhere('bc.isMobile = false');
            }
        }

        // Minimum average rating — scalar subquery; entries with no ratings (AVG
        // is NULL) never satisfy `>= n`, so they drop out, matching the map.
        if ($filter->minRating > 0) {
            $qb->andWhere(
                '(SELECT AVG(r_f.value) FROM App\Entity\Rating r_f WHERE r_f.bookcase = bc) >= :minRating'
            )->setParameter('minRating', $filter->minRating);
        }

        // Has at least one still-open wish.
        if ($filter->wishlist) {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM App\Entity\WishlistItem wi_f WHERE wi_f.bookcase = bc AND wi_f.status = :openWish)'
            )->setParameter('openWish', WishlistItemStatus::Open->value);
        }

        // Official BookCrossing zone.
        if ($filter->bookcrossing) {
            $qb->andWhere('bc.isBookcrossingZone = true');
        }

        // Only entries the current user watches (nothing for anonymous visitors).
        if ($filter->watching) {
            if ($watcherId === null) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere(
                    'EXISTS (SELECT 1 FROM App\Entity\WatchlistItem wl_f WHERE wl_f.bookcase = bc AND wl_f.user = :watcherId)'
                )->setParameter('watcherId', Ulid::fromString($watcherId), 'ulid');
            }
        }

        // OSM provenance tri-state.
        if ($filter->osm === 'only') {
            $qb->andWhere('bc.source = :osmSource')->setParameter('osmSource', 'osm');
        } elseif ($filter->osm === 'without') {
            $qb->andWhere('(bc.source IS NULL OR bc.source != :osmSource)')->setParameter('osmSource', 'osm');
        }
    }

    /**
     * Restrict $field to a selected token subset. A full selection is a no-op; an
     * empty selection matches nothing (the user unchecked everything in that group).
     *
     * @param list<string> $selected
     * @param list<string> $all
     */
    private function applyInFilter(QueryBuilder $qb, string $field, array $selected, array $all, string $param): void
    {
        if (count($selected) === count($all)) {
            return;
        }
        if ($selected === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere(sprintf('%s IN (:%s)', $field, $param))->setParameter($param, array_values($selected));
    }

    /**
     * Apply the list-view sort. When $sortKey is 'distance' and user coordinates
     * are given, orders by an equirectangular planar approximation (portable —
     * only +,-,* — no DB trig); $cosLat is cos(userLat) precomputed in PHP. A
     * stable secondary order on the (time-ordered) id makes paging deterministic.
     */
    private function applySort(
        QueryBuilder $qb,
        string $sortKey,
        string $dir,
        ?float $uLat,
        ?float $uLon,
        ?float $cosLat,
    ): void {
        $direction = strtolower($dir) === 'desc' ? SortDirection::Descending : SortDirection::Ascending;

        if ($sortKey === 'distance' && $uLat !== null && $uLon !== null && $cosLat !== null) {
            $qb->addSelect(
                '((bc.position.latitude - :uLat) * (bc.position.latitude - :uLat)'
                . ' + ((bc.position.longitude - :uLon) * :cosLat) * ((bc.position.longitude - :uLon) * :cosLat))'
                . ' AS HIDDEN dist'
            )
                ->setParameter('uLat', $uLat)
                ->setParameter('uLon', $uLon)
                ->setParameter('cosLat', $cosLat)
                ->orderBy('dist', $direction);
        } else {
            $column = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['title'];
            $qb->orderBy($column, $direction);
        }

        $qb->addOrderBy('bc.id', SortDirection::Ascending);
    }

    /** Count entries matching the list-view search + filter set (for pagination). */
    public function countFiltered(?string $q, BookcaseFilter $filter, ?string $watcherId = null): int
    {
        $qb = $this->createQueryBuilder('bc')->select('COUNT(bc.id)');
        $this->applyListFilter($qb, $q);
        $this->applyFilterSet($qb, $filter, $watcherId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Paginated list-view fetch: free-text search + filter set + sort.
     *
     * @return Bookcase[]
     */
    public function findFilteredPaginated(
        ?string $q,
        string $sortKey,
        string $dir,
        ?float $uLat,
        ?float $uLon,
        ?float $cosLat,
        int $limit,
        int $offset,
        BookcaseFilter $filter,
        ?string $watcherId = null,
    ): array {
        $qb = $this->createQueryBuilder('bc');
        $this->applyListFilter($qb, $q);
        $this->applyFilterSet($qb, $filter, $watcherId);
        $this->applySort($qb, $sortKey, $dir, $uLat, $uLon, $cosLat);

        return $qb->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }

    /**
     * Loads a single bookcase with all relations JOIN FETCHed in one query,
     * preventing the N+1 lazy-loading that occurs during serialization/rendering.
     */
    public function findOneWithRelations(string $id): ?Bookcase
    {
        try {
            $ulid = Ulid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->createQueryBuilder('bc')
            ->addSelect('caretaker', 'openingTime', 'image', 'rating')
            ->leftJoin('bc.caretakers', 'caretaker')
            ->leftJoin('bc.openingTimes', 'openingTime')
            ->leftJoin('bc.images', 'image')
            ->leftJoin('bc.ratings', 'rating')
            ->where('bc.id = :id')
            ->setParameter('id', $ulid, 'ulid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Restrict to the lat/lon box and, when given, drop the rows inside
     * `$exclude` — the area the map has already loaded.
     *
     * The bounds are inlined as numeric literals rather than bound: DBAL binds
     * floats as strings, and once `$skipIndex` turns the column into an
     * expression (`+col`) it loses its REAL affinity, so SQLite would compare a
     * number against text and match nothing. The values are PHP floats clamped
     * to valid coordinates and formatted by sprintf, so nothing user-supplied
     * reaches the SQL text.
     */
    private function applyBoundingBox(
        DbalQueryBuilder $qb,
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
        ?GeoBox $exclude,
        bool $skipIndex = false,
    ): void {
        $lat = ($skipIndex ? '+' : '') . 'b.position_latitude';
        $lon = ($skipIndex ? '+' : '') . 'b.position_longitude';

        $qb->andWhere(sprintf('%s BETWEEN %s AND %s', $lat, self::lat($latMin), self::lat($latMax)))
            ->andWhere(sprintf('%s BETWEEN %s AND %s', $lon, self::lon($lonMin), self::lon($lonMax)));

        if ($exclude !== null) {
            $qb->andWhere(sprintf(
                'NOT (%s BETWEEN %s AND %s AND %s BETWEEN %s AND %s)',
                $lat, self::lat($exclude->latMin), self::lat($exclude->latMax),
                $lon, self::lon($exclude->lonMin), self::lon($exclude->lonMax),
            ));
        }
    }

    /** Latitude as an SQL numeric literal, clamped to ±90 (also tames ±INF). */
    private static function lat(float $value): string
    {
        return sprintf('%.8F', max(-90.0, min(90.0, $value)));
    }

    /** Longitude as an SQL numeric literal, clamped to ±180 (also tames ±INF). */
    private static function lon(float $value): string
    {
        return sprintf('%.8F', max(-180.0, min(180.0, $value)));
    }
}
