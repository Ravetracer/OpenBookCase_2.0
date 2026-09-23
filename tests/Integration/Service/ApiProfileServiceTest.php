<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\ApiProfileService;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** The /api/v1 home location: coordinates validated first, zoom clamped, label trimmed. */
final class ApiProfileServiceTest extends KernelTestCase
{
    public function testSetHomeClampsZoomAndTruncatesLabel(): void
    {
        $user = UserFactory::createOne(['useHomeLocation' => false]);

        $this->service()->setHome($user, ['latitude' => '52.5', 'longitude' => 13.4, 'zoom' => 99, 'label' => str_repeat('L', 60)]);

        $this->assertSame([
            'latitude' => 52.5,
            'longitude' => 13.4,
            'zoom' => 19,
            'label' => str_repeat('L', 50),
            'useHomeLocation' => false,
        ], $this->service()->homeToArray($user));
    }

    public function testBlankLabelClearsIt(): void
    {
        $user = UserFactory::createOne(['homeLabel' => 'Office']);

        $this->service()->setHome($user, ['latitude' => 1, 'longitude' => 1, 'label' => '  ']);

        $this->assertNull($user->homeLabel);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCoordinates(): iterable
    {
        yield 'missing' => [[]];
        yield 'text' => [['latitude' => 'abc', 'longitude' => 1]];
        yield 'lat range' => [['latitude' => 91, 'longitude' => 1]];
        yield 'lon range' => [['latitude' => 1, 'longitude' => -181]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidCoordinates')]
    public function testInvalidCoordinatesChangeNothing(array $data): void
    {
        $user = UserFactory::createOne(['homeLatitude' => 10.0, 'homeLongitude' => 20.0]);

        try {
            $this->service()->setHome($user, $data + ['zoom' => 5]);
            $this->fail('expected a 400');
        } catch (BadRequestHttpException) {
        }

        $this->assertSame(10.0, $user->homeLatitude);
        $this->assertNotSame(5, $user->homeZoom);
    }

    private function service(): ApiProfileService
    {
        return self::getContainer()->get(ApiProfileService::class);
    }
}
