<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Repository\BookcaseRepository;
use App\Tests\Factory\BookcaseFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command runs `PRAGMA synchronous = NORMAL` and its own
 * beginTransaction()/commit() — neither of which is allowed inside DAMA's
 * wrapping transaction (SQLite: "Safety level may not be changed inside a
 * transaction"). So this class opts out of the per-test rollback and cleans the
 * bookcase tables itself before each test instead.
 */
#[SkipDatabaseRollback]
final class GenerateShortCodesCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $conn = self::getContainer()->get(Connection::class);
        // Clean slate (no DAMA rollback here).
        $conn->executeStatement('DELETE FROM bookcase_caretaker');
        $conn->executeStatement('DELETE FROM opening_time');
        $conn->executeStatement('DELETE FROM caretaker');
        $conn->executeStatement('DELETE FROM bookcase');
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $conn = self::getContainer()->get(Connection::class);
            $conn->executeStatement('DELETE FROM bookcase_caretaker');
            $conn->executeStatement('DELETE FROM opening_time');
            $conn->executeStatement('DELETE FROM caretaker');
            $conn->executeStatement('DELETE FROM bookcase');
        }
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:generate-short-codes'));
    }

    private function nullShortCode(\App\Entity\Bookcase $bc): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE bookcase SET short_code = NULL WHERE id = ?',
            [$bc->id->toBinary()],
            [ParameterType::BINARY],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testAssignsCodesToBookcasesLackingOne(): void
    {
        $a = BookcaseFactory::createOne();
        $b = BookcaseFactory::createOne();
        $this->nullShortCode($a);
        $this->nullShortCode($b);

        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $repo = self::getContainer()->get(BookcaseRepository::class);
        $codes = [];
        foreach ($repo->findAll() as $bc) {
            $this->assertNotNull($bc->shortCode, 'every bookcase has a code afterwards');
            $this->assertNotSame('', $bc->shortCode);
            $codes[] = $bc->shortCode;
        }

        $this->assertCount(2, $codes);
        $this->assertCount(count($codes), array_unique($codes), 'all codes are unique');
        $this->assertStringContainsString('Assigned', $tester->getDisplay());
    }

    public function testLeavesExistingCodesUntouched(): void
    {
        BookcaseFactory::createOne(['shortCode' => 'KEEP01']);
        $missing = BookcaseFactory::createOne();
        $this->nullShortCode($missing);

        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $repo = self::getContainer()->get(BookcaseRepository::class);

        $this->assertNotNull($repo->findOneBy(['shortCode' => 'KEEP01']), 'pre-existing code preserved');

        $reloadedMissing = $repo->find($missing->id);
        $this->assertNotNull($reloadedMissing->shortCode);
        $this->assertNotSame('KEEP01', $reloadedMissing->shortCode);
    }

    public function testNothingToDoWhenAllHaveCodes(): void
    {
        BookcaseFactory::createOne(['shortCode' => 'AAA111']);

        $tester = $this->tester();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('Nothing to do', $tester->getDisplay());
    }
}
