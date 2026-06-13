<?php

namespace App\Repository;

use App\Entity\Bookcase;
use App\Enums\WishlistItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
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
    ): array {
        return $this->createQueryBuilder('bc')
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
            ->setParameter('open', WishlistItemStatus::Open->value)
            ->setParameter('latMin', $latMin)
            ->setParameter('latMax', $latMax)
            ->setParameter('lonMin', $lonMin)
            ->setParameter('lonMax', $lonMax)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * All bookcases with caretakers & opening times eager-loaded for the open-data
     * JSON export. Images and ratings are intentionally NOT fetched — the export is
     * location/contact data only.
     *
     * @return Bookcase[]
     */
    public function findAllForExport(): array
    {
        return $this->createQueryBuilder('bc')
            ->addSelect('caretaker', 'openingTime')
            ->leftJoin('bc.caretakers', 'caretaker')
            ->leftJoin('bc.openingTimes', 'openingTime')
            ->orderBy('bc.title', 'ASC')
            ->getQuery()
            ->getResult();
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
            'LOWER(bc.title) LIKE :q'
            . ' OR LOWER(bc.address.street) LIKE :q'
            . ' OR LOWER(bc.address.houseNumber) LIKE :q'
            . ' OR LOWER(bc.address.zipcode) LIKE :q'
            . ' OR LOWER(bc.address.city) LIKE :q'
            . ' OR LOWER(bc.address.additionalData) LIKE :q'
        )->setParameter('q', '%' . mb_strtolower($q) . '%');
    }

    /** Count entries matching the list-view search filter (for pagination). */
    public function countFiltered(?string $q): int
    {
        $qb = $this->createQueryBuilder('bc')->select('COUNT(bc.id)');
        $this->applyListFilter($qb, $q);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Paginated list-view fetch: free-text search + sort. When $sortKey is
     * 'distance' and user coordinates are given, orders by an equirectangular
     * planar approximation (portable — only +,-,* — no DB trig). $cosLat is
     * cos(userLat) precomputed in PHP.
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
    ): array {
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $qb = $this->createQueryBuilder('bc');
        $this->applyListFilter($qb, $q);

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

        // Stable secondary order so equal keys (and identical distances) paginate deterministically.
        $qb->addOrderBy('bc.id', 'ASC');

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
