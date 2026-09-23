<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Model\BookcaseFilter;
use App\Repository\BookcaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Open-data dump of every entry (location + contact data only — images and
 * per-user ratings are intentionally excluded).
 *
 * The dataset is large (tens of thousands of rows), so the dump is **streamed**
 * one entry at a time and the EM is cleared in batches instead of building the
 * whole payload in memory. `?gzip=1` compresses on the fly (`.json.gz`).
 */
class BookcaseExportService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookcaseRepository $bookcaseRepository,
        #[Autowire('%env(SHORTENER_BASE_URL)%')]
        private readonly string $shortenerBaseUrl = 'https://obc.onl',
    ) {
    }

    /**
     * The callback that writes the export to the output (for a StreamedResponse).
     * Reads `gzip`, and — with `filtered=1` — the list view's search/filter/sort
     * query (`q`, `sort`, `dir`, `userLat`, `userLon` + {@see BookcaseFilter} keys).
     */
    public function streamCallback(Request $request, ?User $user): \Closure
    {
        $compress = $request->query->getBoolean('gzip');

        // Children are resolved once in two small bulk queries, then looked up per
        // row — lazy-loading them per entity would be 100k+ queries.
        $caretakerMap = $this->bookcaseRepository->exportCaretakerMap();
        $openingMap = $this->bookcaseRepository->exportOpeningTimeMap();

        // "Export with current filters & sorting" (filtered=1) mirrors the list
        // view's search/filter/sort; otherwise the dump is the full data set.
        if ($request->query->getBoolean('filtered')) {
            $filter = BookcaseFilter::fromRequest($request);
            $q = trim((string) $request->query->get('q', '')) ?: null;
            $sort = (string) $request->query->get('sort', 'title');
            $dir = (string) $request->query->get('dir', 'asc');
            $uLat = $this->finiteOrNull($request->query->get('userLat'));
            $uLon = $this->finiteOrNull($request->query->get('userLon'));
            $cosLat = $uLat !== null ? cos(deg2rad($uLat)) : null;
            $watcherId = $user?->id !== null ? (string) $user->id : null;

            $count = $this->bookcaseRepository->countFiltered($q, $filter, $watcherId);
            $rows = fn () => $this->bookcaseRepository->iterateFilteredForExport(
                $q, $sort, $dir, $uLat, $uLon, $cosLat, $filter, $watcherId,
            );
        } else {
            $count = $this->bookcaseRepository->count([]);
            $rows = fn () => $this->bookcaseRepository->iterateForExport();
        }

        return function () use ($rows, $caretakerMap, $openingMap, $count, $compress): void {
            // Incremental gzip: compress chunk-by-chunk so neither the JSON nor its
            // compressed form is ever fully held in memory.
            $deflate = $compress ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
            $emit = static function (string $chunk) use ($deflate): void {
                echo $deflate !== null ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;
            };

            $emit('{' . "\n" . '  "count": ' . $count . ',' . "\n" . '  "bookcases": [');

            $first = true;
            $i = 0;
            foreach ($rows() as $bc) {
                $key = (string) $bc->id;
                $entry = $this->entry($bc, $openingMap[$key] ?? [], $caretakerMap[$key] ?? []);

                $emit(($first ? '' : ',') . "\n" . json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $first = false;

                // Free hydrated entities and push bytes to the client in batches so
                // memory stays flat across the whole dump.
                if ((++$i % self::BATCH_SIZE) === 0) {
                    $this->entityManager->clear();
                    if ($deflate !== null) {
                        echo deflate_add($deflate, '', ZLIB_SYNC_FLUSH);
                    }
                    // flush() (SAPI-level) only — NOT ob_flush(), which would empty
                    // any wrapping output buffer (e.g. the test harness capturing this).
                    flush();
                }
            }

            $emit("\n" . '  ]' . "\n" . '}' . "\n");

            if ($deflate !== null) {
                echo deflate_add($deflate, '', ZLIB_FINISH);
            }
            flush();
        };
    }

    /**
     * One exported entry.
     *
     * @param list<mixed> $openingTimes
     * @param list<mixed> $caretakers
     *
     * @return array<string, mixed>
     */
    public function entry(Bookcase $bc, array $openingTimes, array $caretakers): array
    {
        $shortBase = rtrim($this->shortenerBaseUrl, '/');

        return [
            'id' => (string) $bc->id,
            // Short share code + its full obc.onl link (null when an entry
            // somehow has no code).
            'shortCode' => $bc->shortCode,
            'shortUrl' => $bc->shortCode !== null ? $shortBase . '/' . $bc->shortCode : null,
            // Legacy numeric id (from the old system) so consumers can match
            // entries against their own data; null for entries added since.
            'legacyId' => $bc->legacyId,
            // Stable OpenStreetMap element ref ("{n|w|r}{id}") for imported
            // entries; null when not sourced from OSM.
            'osmId' => $bc->osmId,
            // Provenance: 'osm' for OpenStreetMap imports, null otherwise — so
            // consumers know whether an entry comes from OSM.
            'source' => $bc->source,
            'title' => $bc->title,
            'type' => $bc->entryType->value,
            'status' => $bc->active?->status->value,
            'statusDescription' => $bc->active?->statusDescription,
            'position' => [
                'latitude' => $bc->position?->latitude,
                'longitude' => $bc->position?->longitude,
            ],
            'address' => [
                'street' => $bc->address?->street,
                'houseNumber' => $bc->address?->houseNumber,
                'zipcode' => $bc->address?->zipcode,
                'city' => $bc->address?->city,
                'additionalData' => $bc->address?->additionalData,
            ],
            'webpage' => $bc->webpage,
            'isMobile' => $bc->isMobile,
            'installationType' => $bc->installationType,
            'digitalMediaAllowed' => $bc->digitalMediaAllowed,
            'accessibility' => [
                'level' => $bc->accessibility?->level?->value,
                'description' => $bc->accessibility?->description,
            ],
            'comment' => $bc->comment,
            'openingTimes' => $openingTimes,
            'caretakers' => $caretakers,
        ];
    }

    private function finiteOrNull(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
