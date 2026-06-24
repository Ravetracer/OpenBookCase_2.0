<?php

namespace App\Repository;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\WishlistItemStatus;
use App\Model\BookcaseFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
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
     * Lightweight bounding-box read for the map: only the fields a marker needs,
     * via array hydration (no full entity graph, no embeddable objects). This is
     * ~10x faster than hydrating Bookcase entities when zoomed out, where the
     * box can match thousands of rows.
     *
     * @return array<int, array{id: \Symfony\Component\Uid\Ulid, title: string, latitude: float, longitude: float, mapSymbol: \App\Enums\MapSymbol, openWishlistCount: int}>
     */
    public function findByBoundingBoxLight(
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('bc')
            ->select(
                'bc.id AS id',
                'bc.title AS title',
                'bc.position.latitude AS latitude',
                'bc.position.longitude AS longitude',
                'bc.entryType AS entryType',
                'bc.mapSymbol AS mapSymbol',
                'bc.active.status AS activeStatus',
                'bc.active.statusDescription AS statusDescription',
                'bc.accessibility.level AS accessibilityLevel',
                'bc.isMobile AS isMobile',
                'bc.isBookcrossingZone AS isBookcrossingZone',
                'bc.source AS source',
                // COUNT/AVG are DISTINCT/id-safe: two one-to-many joins (wishes +
                // ratings) cross-multiply rows, so plain COUNT(wi.id) would inflate.
                // AVG is unaffected by the uniform row duplication.
                'AVG(r.value) AS ratingAverage',
                'COUNT(DISTINCT r.id) AS ratingCount',
                'COUNT(DISTINCT wi.id) AS openWishlistCount',
            )
            // Only count still-open wishes so the marker can flag "wishes wanted here".
            ->leftJoin('bc.wishlistItems', 'wi', 'WITH', 'wi.status = :open')
            ->leftJoin('bc.ratings', 'r')
            ->where('bc.position.latitude BETWEEN :latMin AND :latMax')
            ->andWhere('bc.position.longitude BETWEEN :lonMin AND :lonMax')
            // Group by every non-aggregated column for ONLY_FULL_GROUP_BY safety.
            ->groupBy('bc.id')
            ->addGroupBy('bc.title')
            ->addGroupBy('bc.position.latitude')
            ->addGroupBy('bc.position.longitude')
            ->addGroupBy('bc.entryType')
            ->addGroupBy('bc.mapSymbol')
            ->addGroupBy('bc.active.status')
            ->addGroupBy('bc.active.statusDescription')
            ->addGroupBy('bc.accessibility.level')
            ->addGroupBy('bc.isMobile')
            ->addGroupBy('bc.isBookcrossingZone')
            ->addGroupBy('bc.source')
            ->setParameter('open', WishlistItemStatus::Open->value)
            ->setParameter('latMin', $latMin)
            ->setParameter('latMax', $latMax)
            ->setParameter('lonMin', $lonMin)
            ->setParameter('lonMax', $lonMax);

        // Stable order so LIMIT/OFFSET paging never skips or repeats a row. The
        // query is array-hydrated and grouped by bc.id (no fetched collections),
        // so LIMIT applies to grouped rows directly — safe to paginate.
        if ($limit !== null) {
            $qb->orderBy('bc.id', 'ASC')
                ->setFirstResult(max(0, $offset))
                ->setMaxResults($limit);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Count of entries inside a bounding box. Lets the map show a determinate
     * progress bar while it pages markers in. No joins — just the indexed
     * lat/lon range — so it's cheap.
     */
    public function countByBoundingBox(
        float $latMin,
        float $latMax,
        float $lonMin,
        float $lonMax,
    ): int {
        return (int) $this->createQueryBuilder('bc')
            ->select('COUNT(bc.id)')
            ->where('bc.position.latitude BETWEEN :latMin AND :latMax')
            ->andWhere('bc.position.longitude BETWEEN :lonMin AND :lonMax')
            ->setParameter('latMin', $latMin)
            ->setParameter('latMax', $latMax)
            ->setParameter('lonMin', $lonMin)
            ->setParameter('lonMax', $lonMax)
            ->getQuery()
            ->getSingleScalarResult();
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
            ->orderBy('bc.title', 'ASC')
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
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('bc.title', 'ASC')
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

        $qb->andWhere(
            '(LOWER(bc.title) LIKE :q'
            . ' OR LOWER(bc.address.street) LIKE :q'
            . ' OR LOWER(bc.address.houseNumber) LIKE :q'
            . ' OR LOWER(bc.address.zipcode) LIKE :q'
            . ' OR LOWER(bc.address.city) LIKE :q'
            . ' OR LOWER(bc.address.additionalData) LIKE :q)'
        )->setParameter('q', '%' . mb_strtolower($q) . '%');
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
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        if ($sortKey === 'distance' && $uLat !== null && $uLon !== null && $cosLat !== null) {
            $qb->addSelect(
                '((bc.position.latitude - :uLat) * (bc.position.latitude - :uLat)'
                . ' + ((bc.position.longitude - :uLon) * :cosLat) * ((bc.position.longitude - :uLon) * :cosLat))'
                . ' AS HIDDEN dist'
            )
                ->setParameter('uLat', $uLat)
                ->setParameter('uLon', $uLon)
                ->setParameter('cosLat', $cosLat)
                ->orderBy('dist', $dir);
        } else {
            $column = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['title'];
            $qb->orderBy($column, $dir);
        }

        $qb->addOrderBy('bc.id', 'ASC');
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
}
