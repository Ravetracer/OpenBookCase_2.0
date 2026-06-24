<?php declare(strict_types=1);

namespace App\Model;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parsed list/map filter state — the single source of truth shared by the list
 * view, the filtered JSON export, and (mirrored client-side) the map's filter
 * panel. Dimensions mirror that panel:
 *  - accessibility / status / type / mobility — multi-select token sets; a set
 *    containing every token means "no restriction" on that dimension,
 *  - minRating — minimum average rating (0 = any),
 *  - wishlist / bookcrossing / watching — boolean toggles,
 *  - osm — provenance tri-state: `with` (all), `only` (OpenStreetMap imports),
 *    or `without` (community-contributed only).
 *
 * Query keys (so the list, the URL, and the export stay in lockstep):
 *   acc, status, type, mob (comma-separated tokens), minRating (int),
 *   wishes, bcz, watch (1/0), osm (with|only|without).
 * An absent token-set key means "all" (the no-JS / default state); an empty
 * value means "none selected".
 */
final class BookcaseFilter
{
    public const ACCESSIBILITY = ['green', 'yellow', 'red', 'unset'];
    public const STATUS = ['active', 'inactive'];
    public const TYPES = ['bookcase', 'givebox'];
    public const MOBILITY = ['fixed', 'mobile'];
    public const OSM_MODES = ['with', 'only', 'without'];

    /**
     * @param list<string> $accessibility subset of {@see self::ACCESSIBILITY}
     * @param list<string> $status        subset of {@see self::STATUS}
     * @param list<string> $types         subset of {@see self::TYPES}
     * @param list<string> $mobility      subset of {@see self::MOBILITY}
     */
    public function __construct(
        public readonly array $accessibility = self::ACCESSIBILITY,
        public readonly array $status = self::STATUS,
        public readonly array $types = self::TYPES,
        public readonly array $mobility = self::MOBILITY,
        public readonly int $minRating = 0,
        public readonly bool $wishlist = false,
        public readonly bool $bookcrossing = false,
        public readonly bool $watching = false,
        public readonly string $osm = 'with',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $minRating = (int) $request->query->get('minRating', 0);
        $minRating = max(0, min(5, $minRating));

        $osm = (string) $request->query->get('osm', 'with');
        if (!in_array($osm, self::OSM_MODES, true)) {
            $osm = 'with';
        }

        return new self(
            self::tokens($request, 'acc', self::ACCESSIBILITY),
            self::tokens($request, 'status', self::STATUS),
            self::tokens($request, 'type', self::TYPES),
            self::tokens($request, 'mob', self::MOBILITY),
            $minRating,
            $request->query->getBoolean('wishes'),
            $request->query->getBoolean('bcz'),
            $request->query->getBoolean('watch'),
            $osm,
        );
    }

    /**
     * Read a comma-separated token set, intersected with the allowed tokens.
     * An absent key defaults to "all"; an empty value means "none".
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private static function tokens(Request $request, string $key, array $allowed): array
    {
        if (!$request->query->has($key)) {
            return $allowed;
        }

        $raw = trim((string) $request->query->get($key, ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_intersect(explode(',', $raw), $allowed));
    }

    public function withOsm(string $osm): self
    {
        return new self(
            $this->accessibility,
            $this->status,
            $this->types,
            $this->mobility,
            $this->minRating,
            $this->wishlist,
            $this->bookcrossing,
            $this->watching,
            in_array($osm, self::OSM_MODES, true) ? $osm : 'with',
        );
    }

    /**
     * Canonical query representation, so the list URL, the filtered-export links,
     * and a re-parse round-trip cleanly.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        return [
            'acc' => implode(',', $this->accessibility),
            'status' => implode(',', $this->status),
            'type' => implode(',', $this->types),
            'mob' => implode(',', $this->mobility),
            'minRating' => (string) $this->minRating,
            'wishes' => $this->wishlist ? '1' : '0',
            'bcz' => $this->bookcrossing ? '1' : '0',
            'watch' => $this->watching ? '1' : '0',
            'osm' => $this->osm,
        ];
    }

    /** True when any dimension deviates from "show everything". */
    public function isActive(): bool
    {
        return count($this->accessibility) !== count(self::ACCESSIBILITY)
            || count($this->status) !== count(self::STATUS)
            || count($this->types) !== count(self::TYPES)
            || count($this->mobility) !== count(self::MOBILITY)
            || $this->minRating > 0
            || $this->wishlist
            || $this->bookcrossing
            || $this->watching
            || $this->osm !== 'with';
    }
}
