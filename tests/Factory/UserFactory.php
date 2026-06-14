<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 *
 * The default password is a real bcrypt hash of "password" (Symfony's auto
 * hasher is bcrypt), so functional login tests can authenticate with that
 * plaintext. Use ::PLAIN_PASSWORD rather than hard-coding the string.
 */
final class UserFactory extends PersistentObjectFactory
{
    public const PLAIN_PASSWORD = 'password';

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'username' => self::faker()->unique()->userName(),
            'email' => self::faker()->unique()->email(),
            'password' => password_hash(self::PLAIN_PASSWORD, PASSWORD_BCRYPT),
            'isVerified' => true,
            'roles' => [],
        ];
    }

    public function admin(): self
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }

    public function unverified(): self
    {
        return $this->with(['isVerified' => false]);
    }

    /**
     * A legacy account that has not yet migrated: empty password column is not
     * allowed (NOT NULL), so it carries the legacy hash and an empty password.
     */
    public function legacy(string $legacyHash): self
    {
        return $this->with([
            'password' => '',
            'legacyUser' => true,
            'legacyMigrated' => false,
            'legacyPassword' => $legacyHash,
        ]);
    }
}
