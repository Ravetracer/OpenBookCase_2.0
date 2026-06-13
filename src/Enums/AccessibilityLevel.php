<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Wheelchair / reduced-mobility access, shown as a red/yellow/green traffic light.
 * Int-backed so it maps onto the existing integer accessibility_level column.
 */
enum AccessibilityLevel: int
{
    case None = 1;    // not accessible
    case Partial = 2; // partly accessible (with help from others)
    case Full = 3;    // fully accessible

    /** DaisyUI semantic colour for the radio / status component. */
    public function color(): string
    {
        return match ($this) {
            self::None => 'error',
            self::Partial => 'warning',
            self::Full => 'success',
        };
    }

    /** Translation key suffix under `accessibility_level.*`. */
    public function labelKey(): string
    {
        return match ($this) {
            self::None => 'none',
            self::Partial => 'partial',
            self::Full => 'full',
        };
    }

    /** Colour suffix of the map marker icon (marker-icon-accessibility-{color}.png). */
    public function markerColor(): string
    {
        return match ($this) {
            self::None => 'red',
            self::Partial => 'yellow',
            self::Full => 'green',
        };
    }
}
