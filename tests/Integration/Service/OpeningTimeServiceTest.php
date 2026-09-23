<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\OpeningTime;
use App\Service\OpeningTimeService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\OpeningTimeFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** /api/v1 opening-time writes: validated before flushing, rejected PATCHes reverted. */
final class OpeningTimeServiceTest extends KernelTestCase
{
    public function testCreateAttachesValidOpeningTime(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $openingTime = new OpeningTime();
        $openingTime->open_time = 'Mo-Fr 08:00-18:00';

        $this->assertSame([], $this->service()->create($bookcase, $openingTime));

        $this->assertTrue($bookcase->openingTimes->contains($openingTime));
        $this->assertSame(['id' => (string) $openingTime->id, 'openTime' => 'Mo-Fr 08:00-18:00', 'twentyFourSeven' => false], $this->service()->toArray($openingTime));
    }

    public function testCreateRejectsOverlongTextWithoutPersisting(): void
    {
        $openingTime = new OpeningTime();
        $openingTime->open_time = str_repeat('x', 256);

        $this->assertSame(['open_time'], array_column($this->service()->create(BookcaseFactory::createOne(), $openingTime), 'field'));
        $this->assertSame(0, $this->em()->getRepository(OpeningTime::class)->count([]));
    }

    public function testRejectedUpdateIsReverted(): void
    {
        $openingTime = OpeningTimeFactory::createOne(['open_time' => 'Sa']);
        $openingTime->open_time = str_repeat('x', 256);

        $this->assertNotSame([], $this->service()->update($openingTime));

        $this->assertSame('Sa', $openingTime->open_time);
    }

    public function testDeleteRemovesRow(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $openingTime = OpeningTimeFactory::createOne(['bookcase' => $bookcase]);
        $this->em()->refresh($bookcase);

        $this->service()->delete($bookcase, $openingTime);

        $this->assertSame(0, $this->em()->getRepository(OpeningTime::class)->count([]));
    }

    private function service(): OpeningTimeService
    {
        return self::getContainer()->get(OpeningTimeService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
