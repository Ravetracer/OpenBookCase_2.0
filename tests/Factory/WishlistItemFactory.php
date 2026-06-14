<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\WishlistItem;
use App\Enums\WishlistItemStatus;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<WishlistItem> */
final class WishlistItemFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return WishlistItem::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'title' => self::faker()->sentence(3),
            'bookcase' => BookcaseFactory::new(),
            'user' => UserFactory::new(),
            'status' => WishlistItemStatus::Open,
        ];
    }

    public function open(): self    { return $this->with(['status' => WishlistItemStatus::Open]); }
    public function dropped(): self { return $this->with(['status' => WishlistItemStatus::Dropped]); }
}
