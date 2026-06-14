<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Bookcase;
use App\Entity\Embeddables\Accessibility;
use App\Entity\Embeddables\Position;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Bookcase>
 */
final class BookcaseFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Bookcase::class;
    }

    protected function defaults(): array|callable
    {
        $position = new Position();
        // Keep coordinates inside Germany so bounding-box tests have a known frame.
        $position->latitude = self::faker()->randomFloat(6, 47.3, 54.9);
        $position->longitude = self::faker()->randomFloat(6, 5.9, 15.0);

        return [
            'title' => self::faker()->streetName().' Bookcase',
            'position' => $position,
            'shortCode' => self::faker()->unique()->regexify('[0-9A-Za-z]{6}'),
            'entryType' => EntryType::Bookcase,
            'mapSymbol' => MapSymbol::Standard,
        ];
    }

    public function at(float $lat, float $lon): self
    {
        $position = new Position();
        $position->latitude = $lat;
        $position->longitude = $lon;

        return $this->with(['position' => $position]);
    }

    public function givebox(): self
    {
        return $this->with([
            'entryType' => EntryType::Givebox,
            'mapSymbol' => MapSymbol::Givebox,
        ]);
    }

    public function inactive(?string $description = null): self
    {
        return $this->afterInstantiate(function (Bookcase $bookcase) use ($description) {
            $bookcase->active->status = ActiveStatus::Inactive;
            $bookcase->active->statusDescription = $description;
        });
    }

    public function withAccessibility(AccessibilityLevel $level): self
    {
        return $this->afterInstantiate(function (Bookcase $bookcase) use ($level) {
            $bookcase->accessibility = new Accessibility();
            $bookcase->accessibility->level = $level;
        });
    }

    public function osm(string $osmId): self
    {
        return $this->with(['osmId' => $osmId, 'source' => 'osm']);
    }
}
