<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Service\BookcaseMarkerService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class BookcaseMarkerServiceTest extends KernelTestCase
{
    public function testFromRowResolvesEnumsAndRawAccessibilityLevel(): void
    {
        $marker = $this->service()->fromRow($this->row(['accessibilityLevel' => 2, 'ratingAverage' => '3.456']));

        $this->assertSame('givebox', $marker['entryType']);
        $this->assertSame('tardis', $marker['mapSymbol']);
        $this->assertSame('inactive', $marker['status']);
        $this->assertSame('yellow', $marker['accessibility']);
        $this->assertTrue($marker['isMobile']);
        $this->assertSame(3.5, $marker['ratingAverage']);
        $this->assertSame(2, $marker['openWishlistCount']);
    }

    public function testFromRowWithoutAccessibilityOrRatings(): void
    {
        $marker = $this->service()->fromRow($this->row(['accessibilityLevel' => null, 'ratingAverage' => null]));

        $this->assertNull($marker['accessibility']);
        $this->assertNull($marker['ratingAverage']);
    }

    public function testApiFromRowKeepsOnlyThePublicKeysInOrder(): void
    {
        $marker = $this->service()->apiFromRow($this->row(['accessibilityLevel' => AccessibilityLevel::Full]));

        $this->assertSame(
            ['id', 'title', 'position', 'entryType', 'mapSymbol', 'status', 'accessibility', 'isMobile', 'isBookcrossingZone'],
            array_keys($marker),
        );
        $this->assertSame('green', $marker['accessibility']);
    }

    public function testFromEntityIncludesRatingAndWishlistCounts(): void
    {
        $bookcase = BookcaseFactory::createOne();
        RatingFactory::createOne(['bookcase' => $bookcase, 'value' => 4]);
        RatingFactory::createOne(['bookcase' => $bookcase, 'value' => 5]);
        WishlistItemFactory::createOne(['bookcase' => $bookcase]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $bookcase = $em->getRepository(Bookcase::class)->find($bookcase->id);

        $marker = $this->service()->fromEntity($bookcase);
        $this->assertSame((string) $bookcase->id, $marker['id']);
        $this->assertSame(2, $marker['ratingCount']);
        $this->assertSame(4.5, $marker['ratingAverage']);
        $this->assertSame(1, $marker['openWishlistCount']);
        $this->assertArrayNotHasKey('source', $marker);
    }

    public function testCreatedPayload(): void
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'New one']);

        $payload = $this->service()->created($bookcase);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('New one', $payload['title']);
        $this->assertSame('active', $payload['markerStatus']);
        $this->assertSame($bookcase->position->latitude, $payload['latitude']);
    }

    private function service(): BookcaseMarkerService
    {
        return self::getContainer()->get(BookcaseMarkerService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return $overrides + [
            'id' => new Ulid(),
            'title' => 'Row',
            'latitude' => 50.0,
            'longitude' => 8.0,
            'entryType' => EntryType::Givebox,
            'mapSymbol' => MapSymbol::Tardis,
            'activeStatus' => ActiveStatus::Inactive,
            'statusDescription' => 'closed',
            'isMobile' => 1,
            'isBookcrossingZone' => 0,
            'source' => 'osm',
            'ratingCount' => '3',
            'ratingAverage' => null,
            'openWishlistCount' => '2',
        ];
    }
}
