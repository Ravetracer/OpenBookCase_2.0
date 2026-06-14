<?php declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Tests\Factory\BookcaseFactory;
use App\Twig\AppExtension;
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
}
