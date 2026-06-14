<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\OpeningTime;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<OpeningTime> */
final class OpeningTimeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return OpeningTime::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'open_time' => 'Mo-Fr 09:00-18:00',
            'twenty_for_seven' => false,
            'bookcase' => BookcaseFactory::new(),
        ];
    }
}
