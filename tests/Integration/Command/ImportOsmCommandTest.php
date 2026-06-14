<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Repository\BookcaseRepository;
use App\Tests\Factory\BookcaseFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ImportOsmCommand uses SQLite PRAGMAs and its own beginTransaction()/commit(),
 * which are illegal inside DAMA's wrapping transaction. We therefore opt this
 * class out of the per-test rollback and clean the relevant tables ourselves.
 *
 * Each fixture is a minimal Overpass JSON export: {"elements":[ ... ]}, with
 * nodes carrying lat/lon and ways/relations carrying a `center`.
 */
#[SkipDatabaseRollback]
final class ImportOsmCommandTest extends KernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->wipe();
        $this->tmpDir = sys_get_temp_dir() . '/obc-osm-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $this->wipe();
        }
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM bookcase_caretaker');
        $conn->executeStatement('DELETE FROM opening_time');
        $conn->executeStatement('DELETE FROM caretaker');
        $conn->executeStatement('DELETE FROM bookcase');
    }

    /** @param list<array<string,mixed>> $elements */
    private function writeFixture(array $elements): string
    {
        $path = $this->tmpDir . '/osm-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['elements' => $elements]));

        return $path;
    }

    private function node(int $id, float $lat, float $lon, array $tags): array
    {
        return ['type' => 'node', 'id' => $id, 'lat' => $lat, 'lon' => $lon, 'tags' => $tags];
    }

    private function runImport(string $file, array $opts = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:import-osm'));
        $tester->execute(array_merge(['--file' => $file], $opts));

        return $tester;
    }

    private function repo(): BookcaseRepository
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(BookcaseRepository::class);
    }

    // ── Inserts ───────────────────────────────────────────────────────────

    public function testInsertsBookcaseAndGivebox(): void
    {
        $file = $this->writeFixture([
            $this->node(1, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Alpha Shelf']),
            $this->node(2, 52.6, 13.5, ['amenity' => 'give_box', 'name' => 'Beta Box']),
        ]);

        $tester = $this->runImport($file);
        $tester->assertCommandIsSuccessful();

        $repo = $this->repo();
        $this->assertSame(2, $repo->count([]));

        $bookcase = $repo->findOneBy(['osmId' => 'n1']);
        $this->assertNotNull($bookcase);
        $this->assertSame('Alpha Shelf', $bookcase->title);
        $this->assertSame(EntryType::Bookcase, $bookcase->entryType);
        $this->assertSame(MapSymbol::Standard, $bookcase->mapSymbol);
        $this->assertSame('osm', $bookcase->source);
        $this->assertFalse($bookcase->titleProvisional, 'a real OSM name is not provisional');
        $this->assertNotNull($bookcase->shortCode);

        $givebox = $repo->findOneBy(['osmId' => 'n2']);
        $this->assertNotNull($givebox);
        $this->assertSame(EntryType::Givebox, $givebox->entryType);
        $this->assertSame(MapSymbol::Givebox, $givebox->mapSymbol);
    }

    public function testWayCentreIsUsedForCoordinates(): void
    {
        $file = $this->writeFixture([
            ['type' => 'way', 'id' => 99, 'center' => ['lat' => 50.1, 'lon' => 8.6],
                'tags' => ['amenity' => 'public_bookcase', 'name' => 'Way Shelf']],
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'w99']);
        $this->assertNotNull($bc, 'way prefixed with "w" and located via center');
        $this->assertEqualsWithDelta(50.1, $bc->position->latitude, 0.0001);
    }

    public function testElementWithoutCoordinatesIsSkipped(): void
    {
        $file = $this->writeFixture([
            ['type' => 'node', 'id' => 5, 'tags' => ['amenity' => 'public_bookcase', 'name' => 'No Coords']],
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();
        $this->assertSame(0, $this->repo()->count([]));
    }

    public function testNonBookcaseAmenityIsSkipped(): void
    {
        $file = $this->writeFixture([
            $this->node(7, 52.5, 13.4, ['amenity' => 'bench', 'name' => 'Just a bench']),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();
        $this->assertSame(0, $this->repo()->count([]));
    }

    // ── Dedup ─────────────────────────────────────────────────────────────

    public function testIdempotentByOsmId(): void
    {
        $file = $this->writeFixture([
            $this->node(10, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Once']),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();
        $this->assertSame(1, $this->repo()->count([]));

        // Second run over the same file: matched by osm id, inserts nothing more.
        $tester = $this->runImport($file);
        $tester->assertCommandIsSuccessful();
        $this->assertSame(1, $this->repo()->count([]));
        $this->assertStringContainsString('Skipped (osm id)', $tester->getDisplay());
    }

    public function testCoordinateProximityBackfillsOsmIdWithoutOverwriting(): void
    {
        // Pre-existing, non-OSM bookcase very close to the OSM point (~11 m away).
        $existing = BookcaseFactory::createOne([
            'title' => 'User Given Title',
            'osmId' => null,
            'source' => null,
        ]);
        // Move it to a precise coordinate via DBAL so we control the distance.
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'UPDATE bookcase SET position_latitude = 52.5, position_longitude = 13.4 WHERE id = ?',
            [$existing->id->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY],
        );

        $file = $this->writeFixture([
            // ~11 m north of the existing row → within the default 40 m threshold.
            $this->node(20, 52.5001, 13.4, ['amenity' => 'public_bookcase', 'name' => 'OSM Title']),
        ]);

        $tester = $this->runImport($file);
        $tester->assertCommandIsSuccessful();

        $repo = $this->repo();
        $this->assertSame(1, $repo->count([]), 'no duplicate inserted');

        $reloaded = $repo->find($existing->id);
        $this->assertSame('User Given Title', $reloaded->title, 'existing data NOT overwritten');
        $this->assertSame('n20', $reloaded->osmId, 'osm id backfilled onto the existing row');
        $this->assertStringContainsString('Matched by coords', $tester->getDisplay());
    }

    public function testFarApartCoordinatesAreNotMerged(): void
    {
        BookcaseFactory::createOne();
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('UPDATE bookcase SET position_latitude = 52.5, position_longitude = 13.4');

        // ~1.1 km away → well beyond 40 m.
        $file = $this->writeFixture([
            $this->node(21, 52.51, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Far Away']),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();
        $this->assertSame(2, $this->repo()->count([]), 'distinct rows kept apart');
    }

    // ── Dry run ───────────────────────────────────────────────────────────

    public function testDryRunWritesNothing(): void
    {
        $file = $this->writeFixture([
            $this->node(30, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Phantom']),
        ]);

        $tester = $this->runImport($file, ['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->assertSame(0, $this->repo()->count([]));
        $this->assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    // ── skip-giveboxes ────────────────────────────────────────────────────

    public function testSkipGiveboxes(): void
    {
        $file = $this->writeFixture([
            $this->node(40, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Keep']),
            $this->node(41, 52.6, 13.5, ['amenity' => 'give_box', 'name' => 'Drop']),
        ]);

        $this->runImport($file, ['--skip-giveboxes' => true])->assertCommandIsSuccessful();

        $repo = $this->repo();
        $this->assertSame(1, $repo->count([]));
        $this->assertNotNull($repo->findOneBy(['osmId' => 'n40']));
        $this->assertNull($repo->findOneBy(['osmId' => 'n41']));
    }

    // ── Anti-spam ─────────────────────────────────────────────────────────

    public function testUrlInNameIsNotStoredAsTitle(): void
    {
        $file = $this->writeFixture([
            $this->node(50, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Visit http://spam.example']),
        ]);

        $tester = $this->runImport($file);
        $tester->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n50']);
        $this->assertNotNull($bc);
        $this->assertStringNotContainsString('spam.example', (string) $bc->title);
        $this->assertSame('Public bookcase', $bc->title, 'generated title used instead');
        $this->assertTrue($bc->titleProvisional);
    }

    // ── Tag mapping ───────────────────────────────────────────────────────

    public function testAddressTagsMapToAddress(): void
    {
        $file = $this->writeFixture([
            $this->node(60, 52.5, 13.4, [
                'amenity' => 'public_bookcase',
                'name' => 'Addr Shelf',
                'addr:street' => 'Hauptstraße',
                'addr:housenumber' => '5',
                'addr:postcode' => '10117',
                'addr:city' => 'Berlin',
            ]),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n60']);
        $this->assertSame('Hauptstraße', $bc->address->street);
        $this->assertSame('5', $bc->address->houseNumber);
        $this->assertSame('10117', $bc->address->zipcode);
        $this->assertSame('Berlin', $bc->address->city);
    }

    /**
     * @return iterable<string, array{0: string, 1: AccessibilityLevel}>
     */
    public static function wheelchairProvider(): iterable
    {
        yield 'yes → Full' => ['yes', AccessibilityLevel::Full];
        yield 'limited → Partial' => ['limited', AccessibilityLevel::Partial];
        yield 'no → None' => ['no', AccessibilityLevel::None];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wheelchairProvider')]
    public function testWheelchairMapsToAccessibilityLevel(string $tag, AccessibilityLevel $expected): void
    {
        $file = $this->writeFixture([
            $this->node(70, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'WC', 'wheelchair' => $tag]),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n70']);
        $this->assertSame($expected, $bc->accessibility->level);
    }

    public function testOpeningHours247CreatesFlaggedOpeningTime(): void
    {
        $file = $this->writeFixture([
            $this->node(80, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Always', 'opening_hours' => '24/7']),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n80']);
        $this->assertCount(1, $bc->openingTimes);
        $ot = $bc->openingTimes->first();
        $this->assertSame('24/7', $ot->open_time);
        $this->assertTrue($ot->twenty_for_seven, '24/7 auto-detected');
    }

    public function testGeneratedTitleWhenNameAbsentIsProvisional(): void
    {
        $file = $this->writeFixture([
            $this->node(90, 52.5, 13.4, ['amenity' => 'public_bookcase']),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n90']);
        $this->assertSame('Public bookcase', $bc->title);
        $this->assertTrue($bc->titleProvisional);
    }

    public function testTitleBuiltFromAddressWhenNoName(): void
    {
        $file = $this->writeFixture([
            $this->node(91, 52.5, 13.4, [
                'amenity' => 'public_bookcase',
                'addr:street' => 'Eichenweg',
                'addr:housenumber' => '7',
                'addr:city' => 'Bonn',
            ]),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n91']);
        $this->assertSame('Public bookcase – Eichenweg 7, Bonn', $bc->title);
        $this->assertTrue($bc->titleProvisional, 'address-derived title still provisional');
    }

    public function testOperatorAndContactCreateCaretaker(): void
    {
        $file = $this->writeFixture([
            $this->node(92, 52.5, 13.4, [
                'amenity' => 'public_bookcase',
                'name' => 'Cared For',
                'operator' => 'Friends of Books',
                'contact:phone' => '+49 30 123456',
            ]),
        ]);

        $this->runImport($file)->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n92']);
        $this->assertCount(1, $bc->caretakers);
        $caretaker = $bc->caretakers->first();
        $this->assertSame('Friends of Books', $caretaker->name);
        $this->assertSame('+49 30 123456', $caretaker->contact);
    }

    // ── --update ──────────────────────────────────────────────────────────

    public function testUpdateRefreshesProvisionalTitleOnOsmRow(): void
    {
        // First import: generated (provisional) title.
        $file1 = $this->writeFixture([
            $this->node(100, 52.5, 13.4, ['amenity' => 'public_bookcase']),
        ]);
        $this->runImport($file1)->assertCommandIsSuccessful();
        $bc = $this->repo()->findOneBy(['osmId' => 'n100']);
        $this->assertTrue($bc->titleProvisional);

        // Re-import with --update and a real name → provisional title gets rewritten.
        $file2 = $this->writeFixture([
            $this->node(100, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Now Named']),
        ]);
        $tester = $this->runImport($file2, ['--update' => true]);
        $tester->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n100']);
        $this->assertSame('Now Named', $bc->title);
        $this->assertFalse($bc->titleProvisional);
        $this->assertStringContainsString('Updated (osm id)', $tester->getDisplay());
    }

    public function testUpdateKeepsUserGivenTitle(): void
    {
        // OSM-sourced row but with a NON-provisional (user-confirmed) title.
        $file1 = $this->writeFixture([
            $this->node(101, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Human Title']),
        ]);
        $this->runImport($file1)->assertCommandIsSuccessful();
        $bc = $this->repo()->findOneBy(['osmId' => 'n101']);
        $this->assertFalse($bc->titleProvisional);

        // --update with a different OSM name must NOT clobber the confirmed title.
        $file2 = $this->writeFixture([
            $this->node(101, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'Changed Upstream']),
        ]);
        $this->runImport($file2, ['--update' => true])->assertCommandIsSuccessful();

        $bc = $this->repo()->findOneBy(['osmId' => 'n101']);
        $this->assertSame('Human Title', $bc->title, 'non-provisional title is preserved');
    }

    public function testUpdateNeverTouchesNonOsmSourcedRows(): void
    {
        // Pre-existing user row, linked by coordinate proximity (source != 'osm').
        $existing = BookcaseFactory::createOne(['title' => 'Mine', 'osmId' => null, 'source' => null]);
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'UPDATE bookcase SET position_latitude = 52.5, position_longitude = 13.4 WHERE id = ?',
            [$existing->id->toBinary()],
            [\Doctrine\DBAL\ParameterType::BINARY],
        );

        // First run links n200 to the existing row (backfill, no edit).
        $file = $this->writeFixture([
            $this->node(200, 52.5, 13.4, ['amenity' => 'public_bookcase', 'name' => 'OSM Wants This']),
        ]);
        $this->runImport($file)->assertCommandIsSuccessful();
        $this->assertSame('Mine', $this->repo()->find($existing->id)->title);

        // --update run: the row is linked but NOT source='osm' → left untouched.
        $this->runImport($file, ['--update' => true])->assertCommandIsSuccessful();
        $this->assertSame('Mine', $this->repo()->find($existing->id)->title);
        $this->assertSame(1, $this->repo()->count([]));
    }

    // ── Error handling ────────────────────────────────────────────────────

    public function testMissingFileFails(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:import-osm'));
        $tester->execute(['--file' => $this->tmpDir . '/does-not-exist.json']);

        $this->assertSame(\Symfony\Component\Console\Command\Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('File not found', $tester->getDisplay());
    }
}
