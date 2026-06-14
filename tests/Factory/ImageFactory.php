<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Image;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Image>
 *
 * Persists only the DB row (filename/metadata) — it does NOT create a file on
 * disk. Tests that exercise ImageService must place a real file themselves.
 */
final class ImageFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Image::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'author' => self::faker()->name(),
            'filename' => self::faker()->uuid().'.jpg',
            'imageSize' => self::faker()->numberBetween(1000, 500000),
            'updatedAt' => \DateTime::createFromInterface(self::faker()->dateTime()),
            'bookcase' => BookcaseFactory::new(),
            'uploadedBy' => UserFactory::new(),
        ];
    }
}
