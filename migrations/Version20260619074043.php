<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin user management: add the `is_suspended` flag to the user table.
 * Suspended accounts are blocked at login (UserChecker).
 */
final class Version20260619074043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.is_suspended flag for admin account suspension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user ADD COLUMN is_suspended BOOLEAN DEFAULT 0 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN is_suspended');
    }
}
