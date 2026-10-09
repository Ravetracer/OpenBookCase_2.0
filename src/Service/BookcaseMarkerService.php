<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Model\GeoBox;
use App\Repository\BookcaseRepository;
use App\Repository\WishlistItemRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Map marker payloads: the bounding-box shape the map consumes (built from the
 * light array rows of {@see \App\Repository\BookcaseRepository::findByBoundingBoxLight()}),
 * the same shape for a single entity (live marker refresh after a save), the
 * quick-add response, and the public API's reduced marker.
 */
class BookcaseMarkerService
{
    /** Keys of the public API's bbox marker (a subset of the map's marker). */
    private const API_KEYS = ['id', 'title', 'position', 'entryType', 'mapSymbol', 'status', 'accessibility', 'isMobile', 'isBookcrossingZone'];

    public function __construct(
        private readonly RatingService $ratingService,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly BookcaseRepository $bookcaseRepository,
    ) {
    }

    /**
     * One page of the map's bounding-box marker load.
     *
     * `$exclude` is the box the client already holds, so a zoom-out only pulls
     * the newly visible ring. `$after` is the keyset cursor (the previous page's
     * `next`); without it, `$offset` pages the old way. `total` (rows left to
     * load, for the progress badge) is only counted on the first page; `next`
     * is null on the last one.
     *
     * @return array{total: int|null, offset: int, limit: int, next: string|null, markers: list<array<string, mixed>>}
     */
    public function mapPage(GeoBox $box, ?GeoBox $exclude, string $after, int $limit, int $offset): array
    {
        $cursor = Ulid::isValid($after) ? Ulid::fromString($after) : null;
        if ($cursor !== null) {
            $offset = 0;
        }

        $total = $cursor === null && $offset === 0
            ? $this->bookcaseRepository->countByBoundingBox($box->latMin, $box->latMax, $box->lonMin, $box->lonMax, $exclude)
            : null;
        $rows = $this->bookcaseRepository->findByBoundingBoxLight(
            $box->latMin,
            $box->latMax,
            $box->lonMin,
            $box->lonMax,
            $limit,
            $offset,
            $exclude,
            $cursor,
        );

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'next' => count($rows) === $limit ? (string) end($rows)['id'] : null,
            'markers' => array_map($this->fromRow(...), $rows),
        ];
    }

    /**
     * Map marker from a bbox row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function fromRow(array $row): array
    {
        $mapSymbol = $row['mapSymbol'];
        $entryType = $row['entryType'];
        $activeStatus = $row['activeStatus'];

        // Resolve the accessibility level (enum or raw int from array hydration)
        // to the marker colour the map uses, or null when it isn't set.
        $level = $row['accessibilityLevel'];
        if ($level !== null && !$level instanceof AccessibilityLevel) {
            $level = AccessibilityLevel::tryFrom((int) $level);
        }

        return [
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
            // Provenance marker so the map's OSM filter can include/exclude imports.
            'source' => $row['source'],
            'ratingCount' => (int) $row['ratingCount'],
            'ratingAverage' => $row['ratingAverage'] !== null ? round((float) $row['ratingAverage'], 1) : null,
            'openWishlistCount' => (int) $row['openWishlistCount'],
        ];
    }

    /**
     * Public API marker from a bbox row — the map marker without the website-only fields.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function apiFromRow(array $row): array
    {
        return array_intersect_key($this->fromRow($row), array_flip(self::API_KEYS));
    }

    /**
     * Marker for a single entity — same shape as {@see fromRow()} minus `source` —
     * so the front-end can refresh a marker after a save without a full reload.
     *
     * @return array<string, mixed>
     */
    public function fromEntity(Bookcase $bookcase): array
    {
        $stats = $this->ratingService->stats($bookcase);

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

    /**
     * Response body of the website's quick-add create: the new id + the fields the
     * map needs to drop the pin without a reload.
     *
     * @return array<string, mixed>
     */
    public function created(Bookcase $bookcase): array
    {
        return [
            'status' => 'success',
            'id' => (string) $bookcase->id,
            'title' => $bookcase->title,
            'latitude' => $bookcase->position?->latitude,
            'longitude' => $bookcase->position?->longitude,
            'entryType' => $bookcase->entryType->value,
            'mapSymbol' => $bookcase->mapSymbol->value,
            // Filter-relevant fields, so the live-added marker matches the
            // bbox payload shape and isn't dropped by the active map filters.
            'markerStatus' => $bookcase->active?->status->value,
            'accessibility' => $bookcase->accessibility?->level?->markerColor(),
            'isMobile' => $bookcase->isMobile,
            'isBookcrossingZone' => $bookcase->isBookcrossingZone,
        ];
    }
}
