<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Category of a system message. Drives the icon shown on the inbox chat bubble
 * and gives the upcoming watchlist/wishlist notification triggers a stable seam.
 */
enum MessageType: string
{
    case Update = 'update';
    case BookcaseChanged = 'bookcase_changed';
    case WishlistMatch = 'wishlist_match';
    case ApiAccess = 'api_access';

    /**
     * Icon name understood by templates/components/icon.html.twig.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Update => 'megaphone',
            self::BookcaseChanged => 'map',
            self::WishlistMatch => 'heart',
            self::ApiAccess => 'key',
        };
    }
}
