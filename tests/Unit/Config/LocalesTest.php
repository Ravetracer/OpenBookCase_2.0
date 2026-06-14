<?php declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\Locales;
use PHPUnit\Framework\TestCase;

final class LocalesTest extends TestCase
{
    public function testDefaultIsEnglish(): void
    {
        $this->assertSame('en', Locales::DEFAULT);
    }

    public function testCodesReturnsAllSixLocaleKeys(): void
    {
        $codes = Locales::codes();
        $this->assertSame(['en', 'de', 'ru', 'nl', 'es', 'fr'], $codes);
        $this->assertContains(Locales::DEFAULT, $codes);
    }

    public function testIsSupported(): void
    {
        $this->assertTrue(Locales::isSupported('de'));
        $this->assertTrue(Locales::isSupported('fr'));
        $this->assertFalse(Locales::isSupported('it'));
        $this->assertFalse(Locales::isSupported(''));
        $this->assertFalse(Locales::isSupported(null));
    }
}
