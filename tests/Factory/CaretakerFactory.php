<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Caretaker;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<Caretaker> */
final class CaretakerFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Caretaker::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->name(),
            'contact' => self::faker()->email(),
        ];
    }
}
