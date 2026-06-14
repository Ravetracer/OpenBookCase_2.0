<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Bookcase;
use App\Repository\BookcaseRepository;
use App\Service\ShortCodeGenerator;
use PHPUnit\Framework\TestCase;

final class ShortCodeGeneratorTest extends TestCase
{
    public function testRandomHasRequestedLengthAndAlphabet(): void
    {
        $repo = $this->createMock(BookcaseRepository::class);
        $generator = new ShortCodeGenerator($repo);

        $code = $generator->random();
        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]+$/', $code);

        $this->assertSame(10, strlen($generator->random(10)));
    }

    public function testRandomIsReasonablyUnique(): void
    {
        $repo = $this->createMock(BookcaseRepository::class);
        $generator = new ShortCodeGenerator($repo);

        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[$generator->random()] = true;
        }

        // 62^6 space — 100 draws should not realistically collide.
        $this->assertGreaterThan(95, count($codes));
    }

    public function testUniqueRetriesUntilRepositoryReportsFree(): void
    {
        $repo = $this->createMock(BookcaseRepository::class);
        // First two generated codes are "taken", third is free.
        $repo->expects($this->exactly(3))
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls(new Bookcase(), new Bookcase(), null);

        $generator = new ShortCodeGenerator($repo);
        $code = $generator->unique();

        $this->assertSame(6, strlen($code));
    }

    public function testRandomUniqueInAvoidsTakenSet(): void
    {
        $repo = $this->createMock(BookcaseRepository::class);
        $generator = new ShortCodeGenerator($repo);

        $taken = [];
        for ($i = 0; $i < 50; $i++) {
            $code = $generator->randomUniqueIn($taken);
            $this->assertArrayNotHasKey($code, $taken);
            $taken[$code] = true;
        }

        $this->assertCount(50, $taken);
    }
}
