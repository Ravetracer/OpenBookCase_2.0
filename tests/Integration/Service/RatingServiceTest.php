<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Bookcase;
use App\Service\RatingService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RatingServiceTest extends KernelTestCase
{
    public function testStatsOfUnratedBookcase(): void
    {
        $bookcase = BookcaseFactory::createOne();

        $this->assertSame(['count' => 0, 'average' => 0.0, 'rounded' => 0], $this->service()->stats($bookcase));
        $this->assertSame(['count' => 0, 'average' => 0.0, 'rounded' => 0], $this->service()->publicStats($bookcase));
    }

    public function testStatsAverageAndRounding(): void
    {
        $bookcase = BookcaseFactory::createOne();
        foreach ([5, 4, 4] as $value) {
            RatingFactory::createOne(['bookcase' => $bookcase, 'value' => $value]);
        }
        $bookcase = $this->reload($bookcase);

        $stats = $this->service()->stats($bookcase);
        $this->assertSame(3, $stats['count']);
        $this->assertEqualsWithDelta(13 / 3, $stats['average'], 1e-9);
        $this->assertSame(4, $stats['rounded']);
        $this->assertSame(4.33, $this->service()->publicStats($bookcase)['average']);
    }

    public function testUpsertCreatesThenUpdatesTheSingleUserRating(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $user = UserFactory::createOne();

        $first = $this->service()->upsert($bookcase, $user, 2);
        $this->assertSame(1, $this->service()->stats($bookcase)['count'], 'fresh rating is reflected in memory');

        $second = $this->service()->upsert($bookcase, $user, 5, true, 'Great');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Great', $second->comment);
        $this->assertSame(5, $this->service()->userValue($bookcase, $user));
        $this->assertSame(['count' => 1, 'average' => 5, 'rounded' => 5], $this->service()->stats($bookcase));

        $this->service()->upsert($bookcase, $user, 3);
        $this->assertSame('Great', $second->comment, 'comment untouched without setComment');
    }

    public function testUserValueIsZeroForAnonymousOrUnrated(): void
    {
        $bookcase = BookcaseFactory::createOne();

        $this->assertSame(0, $this->service()->userValue($bookcase, null));
        $this->assertSame(0, $this->service()->userValue($bookcase, UserFactory::createOne()));
    }

    public function testRemoveDeletesOnlyTheUsersRating(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $user = UserFactory::createOne();
        $this->service()->upsert($bookcase, $user, 4);
        $this->service()->upsert($bookcase, UserFactory::createOne(), 2);

        $this->service()->remove($bookcase, $user);
        $this->service()->remove($bookcase, $user); // idempotent

        $this->assertSame(0, $this->service()->userValue($bookcase, $user));
        $this->assertSame(1, $this->service()->stats($bookcase)['count']);
    }

    private function service(): RatingService
    {
        return self::getContainer()->get(RatingService::class);
    }

    private function reload(Bookcase $bookcase): Bookcase
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Bookcase::class)->find($bookcase->id);
    }
}
