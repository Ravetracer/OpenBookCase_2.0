<?php

namespace App\Command;

use App\Service\ShortCodeGenerator;
use App\Service\TwentyFourSevenDetector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports public bookcases / give-boxes from OpenStreetMap (worldwide).
 *
 * Source: an Overpass API JSON export (`amenity=public_bookcase` + `amenity=give_box`),
 * read from --file (default var/osm.json) or fetched live with --fetch.
 *
 * Dedup is two-layered and idempotent:
 *   1. by stable OSM element id (`bookcase.osm_id` = "{n|w|r}{id}") — clean re-runs;
 *   2. else by coordinate proximity (in-memory spatial grid + haversine ≤ --threshold m)
 *      against ALL existing entries (and ones added during the run). A coordinate
 *      match links the existing row to its OSM id (backfill) so future runs match
 *      directly, and otherwise leaves the user's data untouched.
 *
 * Insert-only by default (never overwrites user edits). --update refreshes only
 * OSM-sourced rows (matched by osm_id), and even then keeps a user-given title
 * (it only rewrites titles still flagged provisional).
 *
 * Titles: real OSM `name` → built from addr:* tags → generic ("Public bookcase"),
 * the latter two flagged `title_provisional` for the in-app naming prompt. With
 * --reverse-geocode, un-addressed entries get a street-based title via Photon
 * (throttled — opt-in because of the request volume at world scale).
 *
 * NB: imported OSM data is ODbL — "© OpenStreetMap contributors" is credited on
 * the /licenses page.
 */
#[AsCommand(
    name: 'app:import-osm',
    description: 'Import public bookcases / give-boxes from an OpenStreetMap (Overpass) export, worldwide (insert-only; dedup by osm id + coordinates)',
)]
class ImportOsmCommand extends Command
{
    private const BATCH_SIZE = 200;

    private const BOOKCASE_LABEL = 'Public bookcase';
    private const GIVEBOX_LABEL = 'Give-box';

    private const USER_AGENT = 'OpenBookCase-OSM-Importer/1.0 (+https://openbookcase.de; info@openbookcase.de)';

    /** Spatial grid: "latCell:lonCell" → list<array{0:float lat,1:float lon,2:string binId,3:bool hasOsm}>. */
    private array $grid = [];
    private float $cellDeg = 0.0;
    private float $thresholdM = 40.0;

    /** osm_id ("n123") → array{0:string binId, 1:bool titleProvisional, 2:bool isOsmSourced} for rows carrying an osm_id. */
    private array $existingByOsmId = [];

    /** @var array<string, true> short codes already in use (DB + assigned this run) */
    private array $takenShortCodes = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/osm.json')] private readonly string $defaultJsonFile,
        private readonly Connection $conn,
        private readonly TwentyFourSevenDetector $detector,
        private readonly ShortCodeGenerator $shortCodeGenerator,
        private readonly HttpClientInterface $httpClient,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to the Overpass JSON export', null)
            ->addOption('fetch', null, InputOption::VALUE_NONE, 'Fetch the data live from the Overpass API instead of reading --file')
            ->addOption('overpass-url', null, InputOption::VALUE_REQUIRED, 'Overpass API endpoint', 'https://overpass-api.de/api/interpreter')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Coordinate dedup threshold in metres', '40')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Refresh fields of entries previously imported from OSM (matched by osm id); never touches user-created entries')
            ->addOption('reverse-geocode', null, InputOption::VALUE_NONE, 'For entries without name/address, derive a street-based title via Photon (throttled)')
            ->addOption('skip-giveboxes', null, InputOption::VALUE_NONE, 'Import only public_bookcase, skip give_box')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun         = (bool) $input->getOption('dry-run');
        $update         = (bool) $input->getOption('update');
        $reverseGeocode = (bool) $input->getOption('reverse-geocode');
        $skipGiveboxes  = (bool) $input->getOption('skip-giveboxes');
        $this->thresholdM = max(1.0, (float) $input->getOption('threshold'));
        $this->cellDeg    = $this->thresholdM / 111_320.0; // metres → degrees of latitude

        // ---- Load the OSM data (live fetch or file) -------------------------
        try {
            $elements = $input->getOption('fetch')
                ? $this->fetchFromOverpass($input->getOption('overpass-url'), $skipGiveboxes, $output)
                : $this->readFromFile($input->getOption('file') ?? $this->defaultJsonFile, $output);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Loaded <info>%d</info> OSM elements.', count($elements)));
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no changes will be written.</comment>');
        }
        if ($reverseGeocode) {
            $output->writeln('<comment>Reverse-geocoding enabled — un-addressed entries trigger throttled Photon lookups.</comment>');
        }

        if (!$dryRun) {
            $this->applyPragmas($output);
        }
        $this->preload($output);

        // ---- Import ---------------------------------------------------------
        $bar = new ProgressBar($output, count($elements));
        $bar->start();

        $created = $updatedC = $matchedOsm = $matchedCoords = $linked = 0;
        $skippedNoCoords = $skippedAmenity = $spamTitles = $reverseCalls = $batch = 0;

        if (!$dryRun) {
            $this->conn->beginTransaction();
        }

        foreach ($elements as $el) {
            $entryType = $this->entryType($el['tags']['amenity'] ?? null);
            if ($entryType === null || ($skipGiveboxes && $entryType === 'givebox')) {
                $skippedAmenity++;
                $bar->advance();
                continue;
            }

            [$lat, $lon] = $this->coords($el);
            if ($lat === null || $lon === null) {
                $skippedNoCoords++;
                $bar->advance();
                continue;
            }

            $tags   = $el['tags'] ?? [];
            $osmRef = $this->osmRef($el);

            // 1) Known osm_id → update genuine OSM rows (opt-in), else skip.
            //    Rows only *linked* for dedup (source != 'osm') are never modified.
            if (isset($this->existingByOsmId[$osmRef])) {
                [$binId, $wasProvisional, $isOsmSourced] = $this->existingByOsmId[$osmRef];
                if ($update && $isOsmSourced) {
                    if (!$dryRun) {
                        $this->updateExisting($binId, $wasProvisional, $lat, $lon, $entryType, $tags, $reverseGeocode, $reverseCalls);
                    }
                    $updatedC++;
                } else {
                    $matchedOsm++;
                }
                $bar->advance();
                continue;
            }

            // 2) Coordinate match against existing data → skip (link if unlinked).
            $match = $this->findMatch($lat, $lon);
            if ($match !== null) {
                $matchedCoords++;
                // Backfill the OSM id onto an as-yet-unlinked existing row so future
                // runs match it directly (and don't re-insert a duplicate).
                if (!$match[3]) {
                    if (!$dryRun) {
                        $this->conn->executeStatement(
                            'UPDATE bookcase SET osm_id = ? WHERE id = ? AND osm_id IS NULL',
                            [$osmRef, $match[2]],
                            [ParameterType::STRING, ParameterType::BINARY],
                        );
                    }
                    // Remember the link so the same ref is caught by id next run/iteration.
                    // Not OSM-sourced (it's a pre-existing row) → --update won't touch it.
                    $this->existingByOsmId[$osmRef] = [$match[2], false, false];
                    $linked++;
                }
                $bar->advance();
                continue;
            }

            // 3) New entry → insert.
            $addr = $this->extractAddress($tags);
            $name = $this->cleanTitle($tags['name'] ?? null, $spamTitles);
            if ($name === null && $reverseGeocode && $addr['street'] === null) {
                $rev = $this->reverseGeocode($lat, $lon, $reverseCalls);
                if ($rev !== null) {
                    $addr = $rev;
                }
            }
            [$title, $provisional] = $this->buildTitle($name, $addr, $entryType);

            $binId = (new Ulid())->toBinary();
            if (!$dryRun) {
                $this->insertBookcase($binId, $osmRef, $title, $provisional, $lat, $lon, $entryType, $addr, $tags);
            }
            // Track in-memory so near-identical OSM entries later in the same run
            // dedupe against this one too.
            $this->addToGrid($lat, $lon, $binId, true);
            $this->existingByOsmId[$osmRef] = [$binId, $provisional, true];
            $created++;

            if (!$dryRun && ++$batch >= self::BATCH_SIZE) {
                $batch = 0;
                $this->conn->commit();
                $this->conn->beginTransaction();
            }

            $bar->advance();
        }

        if (!$dryRun) {
            $this->conn->commit();
            $this->conn->executeStatement('PRAGMA foreign_keys = ON');
        }
        $bar->finish();

        // ---- Report ---------------------------------------------------------
        $output->writeln("\n");
        $output->writeln(sprintf('  Created:            <info>%d</info>', $created));
        $output->writeln(sprintf('  Updated (osm id):   <info>%d</info>', $updatedC));
        $output->writeln(sprintf('  Skipped (osm id):   <comment>%d</comment>', $matchedOsm));
        $output->writeln(sprintf('  Matched by coords:  <comment>%d</comment> (of which newly linked: %d)', $matchedCoords, $linked));
        $output->writeln(sprintf('  Skipped (no coords):<comment>%d</comment>', $skippedNoCoords));
        $output->writeln(sprintf('  Skipped (amenity):  <comment>%d</comment>', $skippedAmenity));
        if ($spamTitles > 0) {
            $output->writeln(sprintf('  URL-in-name ignored:<comment>%d</comment> (title generated instead)', $spamTitles));
        }
        if ($reverseGeocode) {
            $output->writeln(sprintf('  Reverse-geocoded:   <info>%d</info>', $reverseCalls));
        }
        $output->writeln($dryRun ? "\n<comment>DRY RUN — nothing was written.</comment>" : "\n<info>Done.</info>");

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function readFromFile(string $file, OutputInterface $output): array
    {
        if (!file_exists($file)) {
            throw new \RuntimeException(sprintf('File not found: %s', $file));
        }
        $output->writeln(sprintf('Reading <info>%s</info> …', $file));
        $raw = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $raw['elements'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function fetchFromOverpass(string $url, bool $skipGiveboxes, OutputInterface $output): array
    {
        $clauses = '( nwr["amenity"="public_bookcase"];';
        if (!$skipGiveboxes) {
            $clauses .= ' nwr["amenity"="give_box"];';
        }
        $clauses .= ' )';
        $query = "[out:json][timeout:600];\n{$clauses};\nout center tags;";

        $output->writeln(sprintf('Fetching worldwide data from <info>%s</info> … (this can take a while)', $url));
        $response = $this->httpClient->request('POST', $url, [
            'body'    => ['data' => $query],
            'timeout' => 600,
            'headers' => ['User-Agent' => self::USER_AGENT],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Overpass returned HTTP %d', $response->getStatusCode()));
        }

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data['elements'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Setup / preload
    // -------------------------------------------------------------------------

    private function applyPragmas(OutputInterface $output): void
    {
        $this->conn->executeStatement('PRAGMA foreign_keys = OFF');
        $this->conn->executeStatement('PRAGMA synchronous = NORMAL');
        $this->conn->executeStatement('PRAGMA journal_mode = WAL');
        $this->conn->executeStatement('PRAGMA cache_size = -65536');
        $output->writeln('<comment>SQLite pragmas applied (FK checks off, WAL mode).</comment>');
    }

    private function preload(OutputInterface $output): void
    {
        // Rows carrying an osm_id: osm_id → [binId, titleProvisional, isOsmSourced].
        // A legacy/user row that was merely *linked* (osm_id backfilled for dedup)
        // has source != 'osm', so --update will leave it untouched.
        foreach ($this->conn->executeQuery(
            'SELECT id, osm_id, title_provisional, source FROM bookcase WHERE osm_id IS NOT NULL',
        )->iterateAssociative() as $r) {
            $this->existingByOsmId[$r['osm_id']] = [$r['id'], (bool) $r['title_provisional'], $r['source'] === 'osm'];
        }

        // Spatial grid over ALL existing coordinates (for proximity dedup).
        foreach ($this->conn->executeQuery(
            'SELECT id, position_latitude AS lat, position_longitude AS lon, osm_id FROM bookcase',
        )->iterateAssociative() as $r) {
            $this->addToGrid((float) $r['lat'], (float) $r['lon'], $r['id'], $r['osm_id'] !== null);
        }

        // Short codes already in use (so new rows get a unique one).
        foreach ($this->conn->executeQuery(
            "SELECT short_code FROM bookcase WHERE short_code IS NOT NULL AND short_code != ''",
        )->iterateColumn() as $code) {
            $this->takenShortCodes[$code] = true;
        }

        $output->writeln(sprintf(
            'Pre-loaded: <info>%d</info> existing entries (<info>%d</info> already from OSM).',
            array_sum(array_map('count', $this->grid)),
            count($this->existingByOsmId),
        ));
    }

    // -------------------------------------------------------------------------
    // Mapping helpers
    // -------------------------------------------------------------------------

    private function entryType(?string $amenity): ?string
    {
        return match ($amenity) {
            'public_bookcase' => 'bookcase',
            'give_box'        => 'givebox',
            default           => null,
        };
    }

    /** @return array{0: ?float, 1: ?float} */
    private function coords(array $el): array
    {
        if (($el['type'] ?? '') === 'node') {
            return [$this->floatOrNull($el['lat'] ?? null), $this->floatOrNull($el['lon'] ?? null)];
        }
        // ways / relations carry a computed centre (Overpass `out center`)
        $c = $el['center'] ?? null;
        return [$this->floatOrNull($c['lat'] ?? null), $this->floatOrNull($c['lon'] ?? null)];
    }

    private function osmRef(array $el): string
    {
        $prefix = match ($el['type'] ?? '') {
            'way'      => 'w',
            'relation' => 'r',
            default    => 'n',
        };
        return $prefix . ($el['id'] ?? '0');
    }

    /** @return array{street: ?string, houseNumber: ?string, zipcode: ?string, city: ?string} */
    private function extractAddress(array $tags): array
    {
        return [
            'street'      => $this->trimOrNull($tags['addr:street'] ?? null, 255),
            'houseNumber' => $this->trimOrNull($tags['addr:housenumber'] ?? null, 255),
            'zipcode'     => $this->trimOrNull($tags['addr:postcode'] ?? null, 128),
            'city'        => $this->trimOrNull($tags['addr:city'] ?? null, 255),
        ];
    }

    /**
     * A real, usable title or null. Drops the "node 12345" viewer artifact and
     * URL-bearing names (anti-spam — links belong in webpage/comment, not titles).
     */
    private function cleanTitle(?string $raw, int &$spamCounter): ?string
    {
        if ($raw === null) {
            return null;
        }
        $t = trim($raw);
        if ($t === '' || preg_match('/^node\s*\d+$/i', $t)) {
            return null;
        }
        if (preg_match('#https?://|www\.#i', $t)) {
            $spamCounter++;
            return null;
        }
        return mb_substr($t, 0, 255);
    }

    /**
     * @param array{street: ?string, houseNumber: ?string, zipcode: ?string, city: ?string} $addr
     * @return array{0: string, 1: bool} [title, provisional]
     */
    private function buildTitle(?string $name, array $addr, string $entryType): array
    {
        if ($name !== null) {
            return [$name, false];
        }

        $label = $entryType === 'givebox' ? self::GIVEBOX_LABEL : self::BOOKCASE_LABEL;

        if ($addr['street'] !== null) {
            $loc = $addr['street']
                . ($addr['houseNumber'] !== null ? ' ' . $addr['houseNumber'] : '')
                . ($addr['city'] !== null ? ', ' . $addr['city'] : '');
            return [mb_substr($label . ' – ' . $loc, 0, 255), true];
        }

        return [$label, true];
    }

    /** First non-empty website-ish tag, capped to the webpage column length. */
    private function webpage(array $tags): ?string
    {
        foreach (['website', 'contact:website', 'url'] as $k) {
            $v = $this->trimOrNull($tags[$k] ?? null, 1024);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    /** Free-text comment from description/note tags. */
    private function comment(array $tags): ?string
    {
        foreach (['description', 'note'] as $k) {
            $v = $this->trimOrNull($tags[$k] ?? null, null);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    /** wheelchair → traffic-light AccessibilityLevel int (1/2/3), else null. */
    private function accessibilityLevel(array $tags): ?int
    {
        return match ($tags['wheelchair'] ?? null) {
            'no'                  => 1, // None
            'limited'             => 2, // Partial
            'yes', 'designated'   => 3, // Full
            default               => null,
        };
    }

    /** First contact detail for the caretaker (phone/email/website). */
    private function caretakerContact(array $tags): ?string
    {
        foreach (['contact:phone', 'phone', 'contact:email', 'email', 'contact:website', 'website'] as $k) {
            $v = $this->trimOrNull($tags[$k] ?? null, 512);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Inserts
    // -------------------------------------------------------------------------

    private function insertBookcase(
        string $binId,
        string $osmRef,
        string $title,
        bool $provisional,
        float $lat,
        float $lon,
        string $entryType,
        array $addr,
        array $tags,
    ): void {
        $shortCode = $this->shortCodeGenerator->randomUniqueIn($this->takenShortCodes);
        $this->takenShortCodes[$shortCode] = true;

        // column => [value, type]
        $cols = [
            'id'                        => [$binId, ParameterType::BINARY],
            'title'                     => [$title, ParameterType::STRING],
            'title_provisional'         => [$provisional ? 1 : 0, ParameterType::INTEGER],
            'source'                    => ['osm', ParameterType::STRING],
            'osm_id'                    => [$osmRef, ParameterType::STRING],
            'webpage'                   => [$this->webpage($tags), ParameterType::STRING],
            'entry_type'                => [$entryType, ParameterType::STRING],
            'installation_type'         => [null, ParameterType::STRING],
            'map_symbol'                => [$entryType === 'givebox' ? 'givebox' : 'standard', ParameterType::STRING],
            'digital_media_allowed'     => [0, ParameterType::INTEGER],
            'comment'                   => [$this->comment($tags), ParameterType::STRING],
            'position_latitude'         => [$lat, ParameterType::STRING],
            'position_longitude'        => [$lon, ParameterType::STRING],
            'accessibility_level'       => [$this->accessibilityLevel($tags), ParameterType::INTEGER],
            'accessibility_description' => [null, ParameterType::STRING],
            'active_status'             => ['active', ParameterType::STRING],
            'active_status_description' => [null, ParameterType::STRING],
            'address_street'            => [$addr['street'], ParameterType::STRING],
            'address_house_number'      => [$addr['houseNumber'], ParameterType::STRING],
            'address_zipcode'           => [$addr['zipcode'], ParameterType::STRING],
            'address_city'              => [$addr['city'], ParameterType::STRING],
            'address_additional_data'   => [null, ParameterType::STRING],
            'is_mobile'                 => [0, ParameterType::INTEGER],
            'short_code'                => [$shortCode, ParameterType::STRING],
            'is_bookcrossing_zone'      => [0, ParameterType::INTEGER],
            'legacy_id'                 => [null, ParameterType::INTEGER],
        ];

        $names  = array_keys($cols);
        $sql    = 'INSERT INTO bookcase (' . implode(', ', $names) . ') VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')';
        $this->conn->executeStatement(
            $sql,
            array_values(array_map(static fn ($c) => $c[0], $cols)),
            array_values(array_map(static fn ($c) => $c[1], $cols)),
        );

        $this->insertOpeningTime($binId, $tags['opening_hours'] ?? null);
        $this->insertCaretaker($binId, $this->trimOrNull($tags['operator'] ?? null, 255), $this->caretakerContact($tags));
    }

    private function insertOpeningTime(string $bookcaseId, ?string $raw): void
    {
        $text = $this->trimOrNull($raw, 255);
        if ($text === null) {
            return;
        }
        $is247 = $this->detector->detect($text) ? 1 : null;
        $this->conn->executeStatement(
            'INSERT INTO opening_time (id, bookcase_id, open_time, twenty_for_seven) VALUES (?, ?, ?, ?)',
            [(new Ulid())->toBinary(), $bookcaseId, $text, $is247],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    private function insertCaretaker(string $bookcaseId, ?string $name, ?string $contact): void
    {
        if ($name === null && $contact === null) {
            return;
        }
        $caretakerId = (new Ulid())->toBinary();
        $this->conn->executeStatement(
            'INSERT INTO caretaker (id, name, contact) VALUES (?, ?, ?)',
            [$caretakerId, $name, $contact],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        $this->conn->executeStatement(
            'INSERT INTO bookcase_caretaker (bookcase_id, caretaker_id) VALUES (?, ?)',
            [$bookcaseId, $caretakerId],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
    }

    // -------------------------------------------------------------------------
    // Update (--update): only OSM-sourced rows, never user-created ones
    // -------------------------------------------------------------------------

    private function updateExisting(
        string $binId,
        bool $wasProvisional,
        float $lat,
        float $lon,
        string $entryType,
        array $tags,
        bool $reverseGeocode,
        int &$reverseCalls,
    ): void {
        $addr = $this->extractAddress($tags);
        $ignoredSpam = 0;
        $name = $this->cleanTitle($tags['name'] ?? null, $ignoredSpam);
        if ($name === null && $reverseGeocode && $addr['street'] === null) {
            $rev = $this->reverseGeocode($lat, $lon, $reverseCalls);
            if ($rev !== null) {
                $addr = $rev;
            }
        }

        $set = [
            'position_latitude'   => [$lat, ParameterType::STRING],
            'position_longitude'  => [$lon, ParameterType::STRING],
            'entry_type'          => [$entryType, ParameterType::STRING],
            'map_symbol'          => [$entryType === 'givebox' ? 'givebox' : 'standard', ParameterType::STRING],
            'webpage'             => [$this->webpage($tags), ParameterType::STRING],
            'comment'             => [$this->comment($tags), ParameterType::STRING],
            'accessibility_level' => [$this->accessibilityLevel($tags), ParameterType::INTEGER],
            'address_street'      => [$addr['street'], ParameterType::STRING],
            'address_house_number'=> [$addr['houseNumber'], ParameterType::STRING],
            'address_zipcode'     => [$addr['zipcode'], ParameterType::STRING],
            'address_city'        => [$addr['city'], ParameterType::STRING],
        ];

        // Only rewrite the title if the user hasn't given it a real one yet.
        if ($wasProvisional) {
            [$title, $provisional] = $this->buildTitle($name, $addr, $entryType);
            $set['title']             = [$title, ParameterType::STRING];
            $set['title_provisional'] = [$provisional ? 1 : 0, ParameterType::INTEGER];
        }

        $assignments = implode(', ', array_map(static fn ($c) => $c . ' = ?', array_keys($set)));
        $values      = array_values(array_map(static fn ($c) => $c[0], $set));
        $types       = array_values(array_map(static fn ($c) => $c[1], $set));
        $values[]    = $binId;
        $types[]     = ParameterType::BINARY;

        $this->conn->executeStatement("UPDATE bookcase SET {$assignments} WHERE id = ?", $values, $types);

        $this->upsertOpeningTime($binId, $tags['opening_hours'] ?? null);
        $this->upsertCaretaker($binId, $this->trimOrNull($tags['operator'] ?? null, 255), $this->caretakerContact($tags));
    }

    private function upsertOpeningTime(string $bookcaseId, ?string $raw): void
    {
        $text = $this->trimOrNull($raw, 255);
        $otId = $this->conn->executeQuery(
            'SELECT id FROM opening_time WHERE bookcase_id = ? LIMIT 1',
            [$bookcaseId],
            [ParameterType::BINARY],
        )->fetchOne();

        if ($text === null) {
            return; // don't wipe an existing opening time when OSM has none
        }
        $is247 = $this->detector->detect($text) ? 1 : null;

        if ($otId !== false) {
            $this->conn->executeStatement(
                'UPDATE opening_time SET open_time = ?, twenty_for_seven = ? WHERE id = ?',
                [$text, $is247, $otId],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BINARY],
            );
        } else {
            $this->insertOpeningTime($bookcaseId, $text);
        }
    }

    private function upsertCaretaker(string $bookcaseId, ?string $name, ?string $contact): void
    {
        if ($name === null && $contact === null) {
            return;
        }
        $caretakerId = $this->conn->executeQuery(
            'SELECT caretaker_id FROM bookcase_caretaker WHERE bookcase_id = ? LIMIT 1',
            [$bookcaseId],
            [ParameterType::BINARY],
        )->fetchOne();

        if ($caretakerId !== false) {
            $this->conn->executeStatement(
                'UPDATE caretaker SET name = ?, contact = ? WHERE id = ?',
                [$name, $contact, $caretakerId],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
            );
        } else {
            $this->insertCaretaker($bookcaseId, $name, $contact);
        }
    }

    // -------------------------------------------------------------------------
    // Reverse geocoding (opt-in, throttled)
    // -------------------------------------------------------------------------

    /** @return array{street: ?string, houseNumber: ?string, zipcode: ?string, city: ?string}|null */
    private function reverseGeocode(float $lat, float $lon, int &$calls): ?array
    {
        // Be polite to the shared Photon instance — ~1 req/s.
        if ($calls > 0) {
            usleep(1_000_000);
        }
        $calls++;

        try {
            $data = $this->httpClient->request('GET', 'https://photon.komoot.io/reverse', [
                'query'   => ['lat' => $lat, 'lon' => $lon, 'lang' => 'en'],
                'timeout' => 15,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ])->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $p = $data['features'][0]['properties'] ?? null;
        if (!is_array($p)) {
            return null;
        }

        $street = $this->trimOrNull($p['street'] ?? $p['name'] ?? null, 255);
        if ($street === null) {
            return null;
        }

        return [
            'street'      => $street,
            'houseNumber' => $this->trimOrNull($p['housenumber'] ?? null, 255),
            'zipcode'     => $this->trimOrNull($p['postcode'] ?? null, 128),
            'city'        => $this->trimOrNull($p['city'] ?? null, 255),
        ];
    }

    // -------------------------------------------------------------------------
    // Spatial grid + haversine
    // -------------------------------------------------------------------------

    private function gridKey(float $lat, float $lon): string
    {
        return ((int) floor($lat / $this->cellDeg)) . ':' . ((int) floor($lon / $this->cellDeg));
    }

    private function addToGrid(float $lat, float $lon, string $binId, bool $hasOsm): void
    {
        $this->grid[$this->gridKey($lat, $lon)][] = [$lat, $lon, $binId, $hasOsm];
    }

    /**
     * Nearest existing point within the threshold, or null. Searches the 3 latitude
     * cells around the candidate and a longitude span widened by 1/cos(lat) to stay
     * correct as longitude cells shrink (in metres) towards the poles.
     *
     * @return array{0: float, 1: float, 2: string, 3: bool}|null
     */
    private function findMatch(float $lat, float $lon): ?array
    {
        $latCell = (int) floor($lat / $this->cellDeg);
        $lonCell = (int) floor($lon / $this->cellDeg);
        $cosLat  = cos(deg2rad($lat));
        $lonRadius = min(40, (int) ceil(1.0 / max($cosLat, 0.01)));

        $best = null;
        $bestDist = $this->thresholdM;

        for ($dLat = -1; $dLat <= 1; $dLat++) {
            for ($dLon = -$lonRadius; $dLon <= $lonRadius; $dLon++) {
                $key = ($latCell + $dLat) . ':' . ($lonCell + $dLon);
                foreach ($this->grid[$key] ?? [] as $point) {
                    $d = $this->haversine($lat, $lon, $point[0], $point[1]);
                    if ($d <= $bestDist) {
                        $bestDist = $d;
                        $best = $point;
                    }
                }
            }
        }

        return $best;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6_371_000.0; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // -------------------------------------------------------------------------
    // Scalar helpers
    // -------------------------------------------------------------------------

    private function floatOrNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    private function trimOrNull(?string $v, ?int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        if ($t === '') {
            return null;
        }
        return $max !== null ? mb_substr($t, 0, $max) : $t;
    }
}
