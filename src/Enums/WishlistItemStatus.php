<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 *
 * Date: 19.10.22
 */

namespace App\Enums;

enum WishlistItemStatus: string
{
    case Open = 'open';
    case Dropped = 'dropped';
    case NotFound = 'not_found';
    case Fulfilled = 'fulfilled';

    /**
     * Human-readable label shown on the wishlist badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Dropped => 'Dropped',
            self::NotFound => 'Not found',
            self::Fulfilled => 'Fulfilled',
        };
    }

    /**
     * DaisyUI badge modifier class for this status.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'badge-info',
            self::Dropped => 'badge-warning',
            self::NotFound => 'badge-error',
            self::Fulfilled => 'badge-success',
        };
    }
}
