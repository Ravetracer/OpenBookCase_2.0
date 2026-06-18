<?php declare(strict_types=1);

namespace App\Tests\Unit\Entity\Embeddables;

use App\Entity\Embeddables\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Guards the coordinate-range constraints on the Position embeddable. Without
 * them a corrupt value (e.g. a lost decimal point turning 13.082462 into
 * 13082462) would persist and silently fall outside every map bounding box —
 * desyncing the navbar total from the on-map count.
 */
final class PositionValidationTest extends TestCase
{
    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testValidCoordinatesPass(): void
    {
        $position = new Position();
        $position->latitude = 54.32958;
        $position->longitude = 13.082462;

        $this->assertCount(0, $this->validator()->validate($position));
    }

    public function testExtremeButValidCoordinatesPass(): void
    {
        $position = new Position();
        $position->latitude = -90.0;
        $position->longitude = 180.0;

        $this->assertCount(0, $this->validator()->validate($position));
    }

    /**
     * @return iterable<string, array{0: float, 1: float, 2: string}>
     */
    public static function outOfRangeProvider(): iterable
    {
        yield 'lost decimal longitude' => [54.32958, 13082462.0, 'longitude'];
        yield 'longitude below -180' => [0.0, -200.0, 'longitude'];
        yield 'latitude above 90' => [200.0, 13.4, 'latitude'];
        yield 'latitude below -90' => [-91.0, 13.4, 'latitude'];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testOutOfRangeCoordinatesAreRejected(float $lat, float $lon, string $offendingField): void
    {
        $position = new Position();
        $position->latitude = $lat;
        $position->longitude = $lon;

        $violations = $this->validator()->validate($position);

        $this->assertGreaterThanOrEqual(1, $violations->count());
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        $this->assertContains($offendingField, $paths);
    }
}
