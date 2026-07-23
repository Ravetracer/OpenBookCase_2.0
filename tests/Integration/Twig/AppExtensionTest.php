<?php declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Tests\Factory\BookcaseFactory;
use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AppExtensionTest extends KernelTestCase
{
    public function testBookcaseCountReflectsLiveTotal(): void
    {
        $extension = self::getContainer()->get(AppExtension::class);
        $this->assertSame(0, $extension->bookcaseCount());

        BookcaseFactory::createMany(4);
        $this->assertSame(4, $extension->bookcaseCount());
    }

    #[DataProvider('externalUrlProvider')]
    public function testExternalUrlNormalisesSchemelessLinks(?string $input, ?string $expected): void
    {
        $extension = self::getContainer()->get(AppExtension::class);
        $this->assertSame($expected, $extension->externalUrl($input));
    }

    public static function externalUrlProvider(): iterable
    {
        yield 'scheme-less host+path gets https' => ['www.walding.at/Buecherinsel', 'https://www.walding.at/Buecherinsel'];
        yield 'bare host gets https'             => ['example.org', 'https://example.org'];
        yield 'https untouched'                  => ['https://example.org/x', 'https://example.org/x'];
        yield 'http untouched'                   => ['http://example.org', 'http://example.org'];
        yield 'uppercase scheme untouched'       => ['HTTPS://example.org', 'HTTPS://example.org'];
        yield 'mailto untouched'                 => ['mailto:hi@example.org', 'mailto:hi@example.org'];
        yield 'tel untouched'                    => ['tel:+43123', 'tel:+43123'];
        yield 'protocol-relative gets https'     => ['//cdn.example.org/a', 'https://cdn.example.org/a'];
        yield 'surrounding whitespace trimmed'   => ['  www.example.org  ', 'https://www.example.org'];
        yield 'empty string stays empty'         => ['', ''];
        yield 'null stays null'                  => [null, null];
    }
}
