<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TwentyFourSevenDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TwentyFourSevenDetectorTest extends TestCase
{
    private TwentyFourSevenDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TwentyFourSevenDetector();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alwaysOpenProvider(): array
    {
        return [
            'slash'              => ['24/7'],
            'spaced slash'       => ['24 / 7'],
            'german round clock' => ['rund um die uhr'],
            'russian'            => ['Круглосуточно'],
            'full clock range'   => ['00:00 - 24:00'],
            'dotted clock range' => ['0.00 - 24.00 Uhr'],
            'shorthand 0-24'     => ['0-24'],
            'shorthand 0-24h'    => ['0-24h'],
            '24h duration'       => ['24h'],
            '24 stunden'         => ['24 Stunden'],
            '24 hours'           => ['24 hours'],
            'mixed case keyword' => ['Immer offen'],
            '23:59 end'          => ['00:00 - 23:59'],
            'mo-so 24'           => ['Mo-So 24 h'],
            '7x24'               => ['7x24'],
            'trailing context'   => ['durchgehend geöffnet - open 24/7'],
        ];
    }

    #[DataProvider('alwaysOpenProvider')]
    public function testDetectsAlwaysOpen(string $input): void
    {
        $this->assertTrue($this->detector->detect($input), sprintf('Expected "%s" to be detected as 24/7', $input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAlwaysOpenProvider(): array
    {
        return [
            'empty'               => [''],
            'dash only'           => ['-'],
            'dot only'            => ['.'],
            'non-midnight start'  => ['05:00 - 24:00'],
            'business hours'      => ['Mo-Fr 09:00 - 18:00'],
            'weekend only'        => ['Sa 10:00 - 14:00'],
            'specific hours'      => ['8 - 20 Uhr'],
        ];
    }

    #[DataProvider('notAlwaysOpenProvider')]
    public function testDoesNotDetectLimitedHours(string $input): void
    {
        $this->assertFalse($this->detector->detect($input), sprintf('Expected "%s" NOT to be detected as 24/7', $input));
    }
}
