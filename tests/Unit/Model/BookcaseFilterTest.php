<?php declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\BookcaseFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BookcaseFilterTest extends TestCase
{
    public function testDefaultsAreShowEverything(): void
    {
        $filter = new BookcaseFilter();

        $this->assertSame(BookcaseFilter::ACCESSIBILITY, $filter->accessibility);
        $this->assertSame(BookcaseFilter::STATUS, $filter->status);
        $this->assertSame(BookcaseFilter::TYPES, $filter->types);
        $this->assertSame(BookcaseFilter::MOBILITY, $filter->mobility);
        $this->assertSame(0, $filter->minRating);
        $this->assertFalse($filter->wishlist);
        $this->assertFalse($filter->bookcrossing);
        $this->assertFalse($filter->watching);
        $this->assertSame('with', $filter->osm);
        $this->assertFalse($filter->isActive(), 'all-defaults filter is inactive');
    }

    public function testFromRequestAbsentKeysDefaultToAll(): void
    {
        $filter = BookcaseFilter::fromRequest(new Request());

        $this->assertSame(BookcaseFilter::ACCESSIBILITY, $filter->accessibility);
        $this->assertSame(BookcaseFilter::TYPES, $filter->types);
        $this->assertFalse($filter->isActive());
    }

    public function testFromRequestParsesEveryDimension(): void
    {
        $request = new Request([
            'acc' => 'green,red',
            'status' => 'active',
            'type' => 'givebox',
            'mob' => 'mobile',
            'minRating' => '3',
            'wishes' => '1',
            'bcz' => '1',
            'watch' => '1',
            'osm' => 'only',
        ]);

        $filter = BookcaseFilter::fromRequest($request);

        $this->assertSame(['green', 'red'], $filter->accessibility);
        $this->assertSame(['active'], $filter->status);
        $this->assertSame(['givebox'], $filter->types);
        $this->assertSame(['mobile'], $filter->mobility);
        $this->assertSame(3, $filter->minRating);
        $this->assertTrue($filter->wishlist);
        $this->assertTrue($filter->bookcrossing);
        $this->assertTrue($filter->watching);
        $this->assertSame('only', $filter->osm);
        $this->assertTrue($filter->isActive());
    }

    public function testEmptyTokenValueMeansNoneSelected(): void
    {
        // Present-but-empty key = the user unchecked every box in that group.
        $filter = BookcaseFilter::fromRequest(new Request(['type' => '']));

        $this->assertSame([], $filter->types);
        $this->assertTrue($filter->isActive());
    }

    public function testUnknownTokensAndOsmModeAreSanitised(): void
    {
        $filter = BookcaseFilter::fromRequest(new Request([
            'acc' => 'green,bogus',
            'minRating' => '99',
            'osm' => 'nonsense',
        ]));

        $this->assertSame(['green'], $filter->accessibility, 'unknown tokens are dropped');
        $this->assertSame(5, $filter->minRating, 'rating is clamped to 0..5');
        $this->assertSame('with', $filter->osm, 'invalid OSM mode falls back to with');
    }

    public function testWithOsmReturnsAdjustedCopy(): void
    {
        $base = new BookcaseFilter(minRating: 2);
        $adjusted = $base->withOsm('without');

        $this->assertSame('with', $base->osm, 'original is untouched');
        $this->assertSame('without', $adjusted->osm);
        $this->assertSame(2, $adjusted->minRating, 'other dimensions carry over');
    }

    public function testToQueryParamsRoundTrips(): void
    {
        $filter = new BookcaseFilter(
            accessibility: ['green', 'yellow'],
            status: ['active'],
            minRating: 4,
            wishlist: true,
            osm: 'without',
        );

        $reparsed = BookcaseFilter::fromRequest(new Request($filter->toQueryParams()));

        $this->assertSame($filter->accessibility, $reparsed->accessibility);
        $this->assertSame($filter->status, $reparsed->status);
        $this->assertSame($filter->minRating, $reparsed->minRating);
        $this->assertTrue($reparsed->wishlist);
        $this->assertSame('without', $reparsed->osm);
    }
}
