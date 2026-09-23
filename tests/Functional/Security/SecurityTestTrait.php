<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Enums\ApiClientType;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\CaretakerFactory;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\OpeningTimeFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared helpers for the security suite: a "victim" world of private and
 * crowdsourced data, URL placeholder filling, a whole-database fingerprint to
 * prove that a rejected request changed nothing, and a rejection assertion.
 */
trait SecurityTestTrait
{
    /** Tables that legitimately change on a rejected request (request telemetry only). */
    private static array $volatileTables = ['api_usage_log'];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Create a victim user owning one of everything, attached to bookcase {bc}.
     *
     * @return array<string, string> placeholder => id
     */
    private function seedWorld(?User $victim = null): array
    {
        $victim ??= UserFactory::createOne(['email' => 'victim@example.com']);
        $bc = BookcaseFactory::createOne(['title' => 'Victim Bookcase']);
        $other = BookcaseFactory::createOne(['title' => 'Other Bookcase']);
        $image = ImageFactory::createOne(['bookcase' => $bc, 'uploadedBy' => $victim, 'altText' => 'original alt']);
        $wish = WishlistItemFactory::createOne(['bookcase' => $bc, 'user' => $victim]);
        $caretaker = CaretakerFactory::createOne(['name' => 'Original Keeper']);
        $bc->addCaretaker($caretaker);
        $openingTime = OpeningTimeFactory::createOne(['bookcase' => $bc]);
        RatingFactory::createOne(['bookcase' => $bc, 'user' => $victim, 'value' => 5]);
        WatchlistItemFactory::createOne(['bookcase' => $bc, 'user' => $victim]);
        $app = ApiApplicationFactory::createOne([
            'applicant' => $victim,
            'clientType' => ApiClientType::Confidential,
            'oauthPlainSecret' => 'victim-secret-0123456789abcdef',
        ]);
        MessageFactory::createOne(['recipient' => $victim, 'body' => 'Victim private message body']);
        $this->em()->flush();

        return [
            '{bc}' => (string) $bc->id,
            '{other}' => (string) $other->id,
            '{img}' => (string) $image->id,
            '{wish}' => (string) $wish->id,
            '{caretaker}' => (string) $caretaker->id,
            '{ot}' => (string) $openingTime->id,
            '{app}' => (string) $app->id,
            '{user}' => (string) $victim->id,
        ];
    }

    /** @param array<string, string> $map */
    private function fill(string $template, array $map): string
    {
        return strtr($template, $map);
    }

    /**
     * Fingerprint of every row in every table (minus telemetry). Two equal
     * snapshots prove a request did not write anything.
     *
     * @return array<string, string> table => hash of its sorted rows
     */
    private function dbSnapshot(): array
    {
        $em = $this->em();
        $em->clear();
        $conn = $em->getConnection();

        $snapshot = [];
        foreach ($conn->createSchemaManager()->listTableNames() as $table) {
            if (in_array($table, self::$volatileTables, true)) {
                continue;
            }
            $rows = array_map('serialize', $conn->fetchAllAssociative('SELECT * FROM "' . $table . '"'));
            sort($rows);
            $snapshot[$table] = count($rows) . ':' . md5(implode("\n", $rows));
        }
        ksort($snapshot);

        return $snapshot;
    }

    /** @param array<string, string> $before */
    private function assertDbUnchanged(array $before, string $context): void
    {
        $after = $this->dbSnapshot();
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
        $this->assertSame([], $changed, "$context must not change state; changed tables: " . implode(', ', $changed));
    }

    /** 401/403, or a redirect to the login page. */
    private function assertRejected(string $context): void
    {
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $location = (string) $response->headers->get('Location');

        $this->assertTrue(
            in_array($status, [401, 403], true) || ($response->isRedirect() && str_contains($location, '/login')),
            "$context: expected 401/403 or a redirect to /login, got $status" . ($location !== '' ? " → $location" : ''),
        );
    }
}
