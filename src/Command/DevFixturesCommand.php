<?php

namespace App\Command;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\Embeddables\Accessibility;
use App\Entity\Embeddables\Active;
use App\Entity\Embeddables\Address;
use App\Entity\Embeddables\Position;
use App\Entity\OpeningTime;
use App\Entity\Rating;
use App\Entity\User;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Service\ShortCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds the database with realistic sample data so a developer can immediately
 * click through the map, list, detail dialogs, ratings, etc.
 *
 * The curated bookcases deliberately span every state that the UI renders
 * differently: active/inactive, each accessibility traffic-light level (plus
 * "unset"), bookcase vs givebox, every map symbol (standard/givebox/tardis),
 * mobile vs fixed, BookCrossing zone, digital-media flag, structured address vs
 * additional-data-only (address fallback), with/without caretakers, opening
 * times (24/7, fixed, none) and a spread of ratings (none → many).
 *
 *   php bin/console app:dev:fixtures            # seed on top of the current DB
 *   php bin/console app:dev:fixtures --fresh    # reset the DB first, then seed
 *   php bin/console app:dev:fixtures --count=80 # also scatter N extra random pins
 *
 * Test logins (password for all: "password"):
 *   dev   / dev@example.com     (ROLE_USER)
 *   admin / admin@example.com   (ROLE_ADMIN)
 */
#[AsCommand(
    name: 'app:dev:fixtures',
    description: 'Load varied sample bookcases + test users for local development',
)]
class DevFixturesCommand extends Command
{
    private const TEST_PASSWORD = 'password';

    /** Real city centres so the pins land on land and cluster nicely. */
    private const CITIES = [
        ['Berlin', 52.5200, 13.4050, '10117'],
        ['Hamburg', 53.5511, 9.9937, '20095'],
        ['München', 48.1351, 11.5820, '80331'],
        ['Köln', 50.9375, 6.9603, '50667'],
        ['Frankfurt am Main', 50.1109, 8.6821, '60311'],
        ['Stuttgart', 48.7758, 9.1829, '70173'],
        ['Düsseldorf', 51.2277, 6.7735, '40213'],
        ['Leipzig', 51.3397, 12.3731, '04109'],
        ['Dresden', 51.0504, 13.7373, '01067'],
        ['Hannover', 52.3759, 9.7320, '30159'],
        ['Bremen', 53.0793, 8.8017, '28195'],
        ['Nürnberg', 49.4521, 11.0767, '90402'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ShortCodeGenerator $shortCodeGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Drop & recreate the database (app:dev:db-init) before seeding')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of extra random bookcases to scatter for map volume', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('fresh')) {
            $io->section('Resetting the database');
            $sub = new ArrayInput([]);
            $sub->setInteractive(false);
            if (($code = $this->getApplication()->find('app:dev:db-init')->run($sub, $output)) !== Command::SUCCESS) {
                return $code;
            }
        }

        // ── Test users ────────────────────────────────────────────────────
        $dev = $this->makeUser('dev', 'dev@example.com');
        $admin = $this->makeUser('admin', 'admin@example.com', ['ROLE_ADMIN']);
        // A few extra users so ratings have varied authors.
        $reviewers = [$dev, $admin];
        foreach (['anna', 'bjoern', 'clara', 'david', 'eva'] as $name) {
            $reviewers[] = $this->makeUser($name, $name . '@example.com');
        }
        $io->text(sprintf('Created %d test users (password: "%s").', count($reviewers), self::TEST_PASSWORD));

        // ── Curated bookcases (one per interesting state combination) ──────
        $specs = $this->curatedSpecs();
        foreach ($specs as $spec) {
            $bc = $this->buildBookcase($spec);
            // Spread some ratings across the curated set.
            $ratingCount = $spec['ratings'] ?? 0;
            for ($i = 0; $i < $ratingCount; $i++) {
                $rating = new Rating();
                $rating->value = (int) round(($spec['ratingAvg'] ?? 4) + (($i % 3) - 1) * 0.5);
                $rating->value = max(1, min(5, $rating->value));
                $rating->user = $reviewers[$i % count($reviewers)];
                $bc->addRating($rating);
                $this->entityManager->persist($rating);
            }
            $this->entityManager->persist($bc);
        }
        $io->text(sprintf('Created %d curated bookcases covering all states.', count($specs)));

        // ── Extra random pins for map volume / clustering ──────────────────
        $extra = max(0, (int) $input->getOption('count'));
        for ($i = 0; $i < $extra; $i++) {
            $bc = $this->buildBookcase($this->randomSpec($i));
            $this->entityManager->persist($bc);
        }
        if ($extra > 0) {
            $io->text(sprintf('Scattered %d extra random bookcases.', $extra));
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded %d bookcases and %d users. Log in as dev@example.com / "%s".',
            count($specs) + $extra,
            count($reviewers),
            self::TEST_PASSWORD,
        ));

        return Command::SUCCESS;
    }

    private function makeUser(string $username, string $email, array $roles = []): User
    {
        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->roles = $roles;
        $user->isVerified = true;
        $user->setPassword($this->passwordHasher->hashPassword($user, self::TEST_PASSWORD));
        $this->entityManager->persist($user);

        return $user;
    }

    /**
     * Builds a Bookcase from a sparse spec array, applying sensible defaults.
     *
     * @param array<string, mixed> $spec
     */
    private function buildBookcase(array $spec): Bookcase
    {
        $bc = new Bookcase();
        $bc->title = $spec['title'];
        $bc->shortCode = $this->shortCodeGenerator->unique();

        $bc->position = new Position();
        $bc->position->latitude = $spec['lat'];
        $bc->position->longitude = $spec['lon'];

        $bc->entryType = $spec['entryType'] ?? EntryType::Bookcase;
        $bc->mapSymbol = $spec['mapSymbol'] ?? MapSymbol::Standard;
        $bc->isMobile = $spec['isMobile'] ?? false;
        $bc->isBookcrossingZone = $spec['isBookcrossingZone'] ?? false;
        $bc->digitalMediaAllowed = $spec['digitalMediaAllowed'] ?? false;
        $bc->installationType = $spec['installationType'] ?? null;
        $bc->webpage = $spec['webpage'] ?? null;
        $bc->comment = $spec['comment'] ?? null;

        $bc->active = new Active();
        $bc->active->status = $spec['status'] ?? ActiveStatus::Active;
        $bc->active->statusDescription = $spec['statusDescription'] ?? null;

        $bc->accessibility = new Accessibility();
        $bc->accessibility->level = $spec['accessibility'] ?? null;
        $bc->accessibility->description = $spec['accessibilityDescription'] ?? null;

        $bc->address = new Address();
        $bc->address->street = $spec['street'] ?? null;
        $bc->address->houseNumber = $spec['houseNumber'] ?? null;
        $bc->address->zipcode = $spec['zipcode'] ?? null;
        $bc->address->city = $spec['city'] ?? null;
        $bc->address->additionalData = $spec['additionalData'] ?? null;

        if (!empty($spec['caretaker'])) {
            $caretaker = new Caretaker();
            $caretaker->name = $spec['caretaker']['name'] ?? null;
            $caretaker->contact = $spec['caretaker']['contact'] ?? null;
            $caretaker->address = new Address();
            $bc->addCaretaker($caretaker);
        }

        if (!empty($spec['openingTime'])) {
            $ot = new OpeningTime();
            $ot->open_time = $spec['openingTime']['text'] ?? null;
            $ot->twenty_for_seven = $spec['openingTime']['always'] ?? false;
            $bc->addOpeningTime($ot);
            $this->entityManager->persist($ot);
        }

        return $bc;
    }

    /**
     * The curated set: each entry showcases a different combination of states.
     *
     * @return array<int, array<string, mixed>>
     */
    private function curatedSpecs(): array
    {
        return [
            [
                'title' => 'Telefonzelle am Mariannenplatz',
                'lat' => 52.5023, 'lon' => 13.4256, 'city' => 'Berlin', 'zipcode' => '10997',
                'street' => 'Mariannenplatz', 'houseNumber' => '2',
                'mapSymbol' => MapSymbol::Tardis, 'accessibility' => AccessibilityLevel::Full,
                'accessibilityDescription' => 'Ebenerdig, breite Tür, gut erreichbar.',
                'isBookcrossingZone' => true, 'digitalMediaAllowed' => true,
                'installationType' => 'Umgebaute Telefonzelle',
                'comment' => 'Beliebter, gut gepflegter Bücherschrank in einer alten Telefonzelle.',
                'webpage' => 'https://example.org/mariannenplatz',
                'openingTime' => ['always' => true, 'text' => 'Rund um die Uhr zugänglich'],
                'caretaker' => ['name' => 'Nachbarschaftsinitiative Kreuzberg', 'contact' => 'kreuzberg@example.org'],
                'ratings' => 7, 'ratingAvg' => 5,
            ],
            [
                'title' => 'Give-Box Schanzenviertel',
                'lat' => 53.5635, 'lon' => 9.9610, 'city' => 'Hamburg', 'zipcode' => '20357',
                'street' => 'Schulterblatt', 'houseNumber' => '73',
                'entryType' => EntryType::Givebox, 'mapSymbol' => MapSymbol::Givebox,
                'accessibility' => AccessibilityLevel::Partial,
                'accessibilityDescription' => 'Eine flache Stufe am Eingang.',
                'digitalMediaAllowed' => true,
                'installationType' => 'Holzregal mit Tür',
                'comment' => 'Nicht nur Bücher — auch Kleinkram und Spielzeug.',
                'openingTime' => ['always' => false, 'text' => 'Mo–Sa 08:00–20:00'],
                'caretaker' => ['name' => 'Stadtteilladen', 'contact' => '040 1234567'],
                'ratings' => 3, 'ratingAvg' => 4,
            ],
            [
                'title' => 'Bücherschrank am Gärtnerplatz',
                'lat' => 48.1296, 'lon' => 11.5772, 'city' => 'München', 'zipcode' => '80469',
                'street' => 'Gärtnerplatz',
                'accessibility' => AccessibilityLevel::None,
                'accessibilityDescription' => 'Mehrere Stufen, für Rollstühle nicht erreichbar.',
                'status' => ActiveStatus::Inactive,
                'statusDescription' => 'Wegen Sanierung vorübergehend abgebaut.',
                'comment' => 'Kommt nach den Bauarbeiten zurück.',
                'ratings' => 2, 'ratingAvg' => 3,
            ],
            [
                'title' => 'Mobiler Bücherwagen Köln-Ehrenfeld',
                'lat' => 50.9520, 'lon' => 6.9180, 'city' => 'Köln', 'zipcode' => '50823',
                'isMobile' => true,
                'installationType' => 'Lastenrad-Anhänger',
                'comment' => 'Standort wechselt — siehe Webseite für den aktuellen Platz.',
                'webpage' => 'https://example.org/buecherwagen',
                'accessibility' => AccessibilityLevel::Partial,
                'openingTime' => ['always' => false, 'text' => 'Sa 10:00–14:00 (wechselnde Orte)'],
                'ratings' => 1, 'ratingAvg' => 4,
            ],
            [
                // Imported-style entry: empty structured address, info only in additionalData.
                'title' => 'Lesepunkt Frankfurt Bockenheim',
                'lat' => 50.1245, 'lon' => 8.6390, 'city' => null,
                'additionalData' => 'Leipziger Straße, Ecke Kiesstraße — direkt vor der Bäckerei.',
                'accessibility' => AccessibilityLevel::Full,
                'comment' => 'Importierter Eintrag ohne strukturierte Adresse (Fallback-Test).',
                'ratings' => 4, 'ratingAvg' => 4,
            ],
            [
                'title' => 'Bücherregal Stuttgart Hauptbahnhof',
                'lat' => 48.7838, 'lon' => 9.1817, 'city' => 'Stuttgart', 'zipcode' => '70173',
                'street' => 'Arnulf-Klett-Platz', 'houseNumber' => '2',
                'accessibility' => AccessibilityLevel::Full,
                'digitalMediaAllowed' => true,
                'installationType' => 'Stahlschrank',
                'openingTime' => ['always' => true, 'text' => '24/7 in der Bahnhofshalle'],
                'caretaker' => ['name' => 'Bahnhofsmission', 'contact' => 'info@example.org'],
                'ratings' => 0,
            ],
            [
                'title' => 'Give-Box Düsseldorf Flingern',
                'lat' => 51.2300, 'lon' => 6.8050, 'city' => 'Düsseldorf', 'zipcode' => '40233',
                'street' => 'Ackerstraße', 'houseNumber' => '144',
                'entryType' => EntryType::Givebox, 'mapSymbol' => MapSymbol::Givebox,
                'isBookcrossingZone' => true,
                'status' => ActiveStatus::Inactive,
                'statusDescription' => 'Gemeldet: beschädigt, Reparatur ausstehend.',
                'accessibility' => AccessibilityLevel::Partial,
                'ratings' => 2, 'ratingAvg' => 2,
            ],
            [
                'title' => 'Bücherschrank Leipzig Südvorstadt',
                'lat' => 51.3210, 'lon' => 12.3680, 'city' => 'Leipzig', 'zipcode' => '04275',
                'street' => 'Karl-Liebknecht-Straße', 'houseNumber' => '62',
                'accessibility' => AccessibilityLevel::Full,
                'comment' => 'Sehr gut sortiert, viel Belletristik.',
                'openingTime' => ['always' => true, 'text' => 'Jederzeit zugänglich'],
                'ratings' => 9, 'ratingAvg' => 5,
            ],
            [
                'title' => 'Tardis-Schrank Dresden Neustadt',
                'lat' => 51.0660, 'lon' => 13.7530, 'city' => 'Dresden', 'zipcode' => '01099',
                'street' => 'Alaunstraße', 'houseNumber' => '36',
                'mapSymbol' => MapSymbol::Tardis,
                'accessibility' => AccessibilityLevel::None,
                'installationType' => 'Blaue Polizeibox (Nachbau)',
                'comment' => 'Größer von innen. Angeblich.',
                'ratings' => 6, 'ratingAvg' => 5,
            ],
            [
                // Minimal entry: only the required fields, everything else default/empty.
                'title' => 'Bücherkiste Hannover (minimal)',
                'lat' => 52.3705, 'lon' => 9.7332, 'city' => 'Hannover',
                'ratings' => 0,
            ],
        ];
    }

    /**
     * A pseudo-random spec scattered around a city, varying the visible states.
     * Deterministic-ish per index so repeated runs differ but stay sensible.
     *
     * @return array<string, mixed>
     */
    private function randomSpec(int $i): array
    {
        [$city, $lat, $lon, $zip] = self::CITIES[$i % count(self::CITIES)];
        // Jitter within roughly ±5 km of the city centre.
        $lat += (random_int(-500, 500) / 10000);
        $lon += (random_int(-500, 500) / 10000);

        $levels = [null, AccessibilityLevel::None, AccessibilityLevel::Partial, AccessibilityLevel::Full];
        $symbols = [MapSymbol::Standard, MapSymbol::Standard, MapSymbol::Givebox, MapSymbol::Tardis];

        $isGivebox = random_int(0, 4) === 0;

        return [
            'title' => sprintf('%s Bücherschrank #%d', $city, $i + 1),
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'city' => $city,
            'zipcode' => $zip,
            'entryType' => $isGivebox ? EntryType::Givebox : EntryType::Bookcase,
            'mapSymbol' => $isGivebox ? MapSymbol::Givebox : $symbols[random_int(0, 3)],
            'accessibility' => $levels[random_int(0, 3)],
            'status' => random_int(0, 6) === 0 ? ActiveStatus::Inactive : ActiveStatus::Active,
            'isMobile' => random_int(0, 9) === 0,
            'digitalMediaAllowed' => random_int(0, 2) === 0,
        ];
    }
}
