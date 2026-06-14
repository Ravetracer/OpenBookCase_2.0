<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Rating;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<Rating> */
final class RatingFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Rating::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'value' => self::faker()->numberBetween(1, 5),
            'bookcase' => BookcaseFactory::new(),
            'user' => UserFactory::new(),
        ];
    }
}
