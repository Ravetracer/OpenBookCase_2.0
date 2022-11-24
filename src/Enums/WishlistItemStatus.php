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
}
