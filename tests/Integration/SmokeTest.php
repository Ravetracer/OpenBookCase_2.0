<?php declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Bookcase;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validates the whole test harness end-to-end: the kernel boots in the test
 * env, Foundry created the schema, factories persist, DAMA rolls back between
 * tests, and — critically — ULID lookups resolve (the BinaryUlidType/BLOB
 * gotcha that otherwise causes silent 404s).
 *
 * No Foundry traits needed: the registered FoundryExtension (phpunit.xml.dist)
 * resets the DB and boots factories automatically.
 */
final class SmokeTest extends KernelTestCase
{
    public function testKernelBootsInTestEnvironment(): void
    {
        self::bootKernel();

        $this->assertSame('test', self::$kernel->getEnvironment());
    }

    public function testFactoryPersistsAndCountsAreIsolatedBetweenTests(): void
    {
        BookcaseFactory::createMany(3);

        // If DAMA rollback weren't working, a previous test's rows would leak in.
        BookcaseFactory::assert()->count(3);
        UserFactory::assert()->count(0);
    }

    public function testUlidLookupResolves(): void
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'ULID lookup probe']);

        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $found = $em->getRepository(Bookcase::class)->find($bookcase->id);

        // The whole point of BinaryUlidType: this find() must not return null.
        $this->assertNotNull($found, 'ULID find() returned null — BLOB binding regression');
        $this->assertSame('ULID lookup probe', $found->title);
    }
}
