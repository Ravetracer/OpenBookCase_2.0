<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\WatchlistItem;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<WatchlistItem> */
final class WatchlistItemFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return WatchlistItem::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'bookcase' => BookcaseFactory::new(),
            'user' => UserFactory::new(),
        ];
    }
}
