<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\ShortCodeGenerator;
use App\Tests\Factory\BookcaseFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShortCodeGeneratorDbTest extends KernelTestCase
{
    public function testUniqueNeverCollidesWithAnExistingCode(): void
    {
        $existing = BookcaseFactory::createOne(['shortCode' => 'abc123']);
        $generator = self::getContainer()->get(ShortCodeGenerator::class);

        // Generate a batch; none may equal the persisted code.
        for ($i = 0; $i < 25; $i++) {
            $this->assertNotSame($existing->shortCode, $generator->unique());
        }
    }
}
