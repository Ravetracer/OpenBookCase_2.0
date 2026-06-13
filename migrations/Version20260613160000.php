<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add boolean bookcase.is_bookcrossing_zone (default 0 / false) — flags an entry
 * as an official BookCrossing release point. Mirrors the legacy "bookcrosserzone"
 * flag; the PhpMyAdmin importer populates it from the legacy export.
 *
 * A plain ADD COLUMN (with a matching DEFAULT 0) keeps the column NOT NULL and
 * the Doctrine mapping (`options: ['default' => false]`) in sync — no SQLite
 * table rebuild needed.
 */
final class Version20260613160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add boolean bookcase.is_bookcrossing_zone (official BookCrossing zone flag).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookcase ADD COLUMN is_bookcrossing_zone BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookcase DROP COLUMN is_bookcrossing_zone');
    }
}
