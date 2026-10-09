<?php declare(strict_types=1);

namespace App\Model;

/**
 * A latitude/longitude rectangle. Used by the map's marker loading to describe
 * the area the client has already loaded (the `exclude` box), so a zoom-out or
 * pan only fetches the newly visible ring instead of the whole view again.
 */
final readonly class GeoBox
{
    public function __construct(
        public float $latMin,
        public float $latMax,
        public float $lonMin,
        public float $lonMax,
    ) {
    }

    /**
     * Parses "latMin,latMax,lonMin,lonMax". Anything malformed (wrong count,
     * non-numeric or non-finite values, min > max) yields null, so a bad box
     * simply means "exclude nothing".
     */
    public static function fromCsv(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = explode(',', $value);
        if (count($parts) !== 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!is_numeric($part) || !is_finite((float) $part)) {
                return null;
            }
            $numbers[] = (float) $part;
        }

        [$latMin, $latMax, $lonMin, $lonMax] = $numbers;
        if ($latMin > $latMax || $lonMin > $lonMax) {
            return null;
        }

        return new self($latMin, $latMax, $lonMin, $lonMax);
    }
}
