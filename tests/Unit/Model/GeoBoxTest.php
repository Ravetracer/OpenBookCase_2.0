<?php declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\GeoBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeoBoxTest extends TestCase
{
    public function testFromCsvParsesFourNumbers(): void
    {
        $box = GeoBox::fromCsv('47.5, 55,-5.25,15');

        $this->assertNotNull($box);
        $this->assertSame(47.5, $box->latMin);
        $this->assertSame(55.0, $box->latMax);
        $this->assertSame(-5.25, $box->lonMin);
        $this->assertSame(15.0, $box->lonMax);
    }

    /** @return iterable<string, array{?string}> */
    public static function malformed(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'too few' => ['1,2,3'];
        yield 'too many' => ['1,2,3,4,5'];
        yield 'non-numeric' => ['1,2,x,4'];
        yield 'blank part' => ['1,,3,4'];
        yield 'infinite' => ['-1e309,1e309,0,1'];
        yield 'lat min above max' => ['10,5,0,1'];
        yield 'lon min above max' => ['0,1,10,5'];
        yield 'sql' => ["1,2,3,4) OR 1=1 --"];
    }

    #[DataProvider('malformed')]
    public function testFromCsvRejectsMalformedInput(?string $value): void
    {
        $this->assertNull(GeoBox::fromCsv($value));
    }
}
