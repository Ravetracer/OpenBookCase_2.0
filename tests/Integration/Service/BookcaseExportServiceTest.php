<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\BookcaseExportService;
use App\Tests\Factory\BookcaseFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BookcaseExportServiceTest extends KernelTestCase
{
    public function testPlainStreamIsValidJsonWithAllEntries(): void
    {
        BookcaseFactory::createOne(['title' => 'Alpha', 'shortCode' => 'Abc123']);
        BookcaseFactory::createOne(['title' => 'Beta']);

        $data = json_decode($this->export(new Request()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $data['count']);
        $alpha = array_values(array_filter($data['bookcases'], static fn (array $b) => $b['title'] === 'Alpha'))[0];
        $this->assertStringEndsWith('/Abc123', $alpha['shortUrl']);
        $this->assertSame([], $alpha['caretakers']);
    }

    public function testGzipStreamDecompressesToTheSameDocument(): void
    {
        BookcaseFactory::createOne(['title' => 'Zipped']);

        $gz = $this->export(new Request(['gzip' => '1']));

        $data = json_decode((string) gzdecode($gz), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Zipped', $data['bookcases'][0]['title']);
    }

    public function testFilteredStreamIgnoresNonFiniteUserCoordinates(): void
    {
        BookcaseFactory::createOne(['title' => 'Match me']);
        BookcaseFactory::createOne(['title' => 'Other']);

        $out = $this->export(new Request(['filtered' => '1', 'q' => 'Match', 'sort' => 'distance', 'userLat' => 'INF', 'userLon' => '1e999']));

        $data = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['count']);
        $this->assertSame('Match me', $data['bookcases'][0]['title']);
    }

    private function export(Request $request): string
    {
        $callback = self::getContainer()->get(BookcaseExportService::class)->streamCallback($request, null);
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
