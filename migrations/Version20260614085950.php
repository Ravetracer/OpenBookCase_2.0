<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add OSM-import columns to bookcase:
 *   - osm_id            stable OpenStreetMap element ref ("{type}{id}"), unique
 *   - source            provenance marker ('osm' for imported entries)
 *   - title_provisional flags auto-generated titles for the naming prompt
 *
 * Plain ADD COLUMNs (additive, no SQLite table rebuild). The unique constraint
 * on osm_id is added as a separate CREATE UNIQUE INDEX because SQLite forbids a
 * UNIQUE clause on ALTER TABLE ADD COLUMN; multiple NULLs stay allowed, so the
 * many non-OSM rows don't collide. The index name matches Doctrine's generated
 * name (unique: true on the column) to keep schema:validate in sync.
 */
final class Version20260614085950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bookcase.osm_id (unique), source and title_provisional for the OSM import.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookcase ADD COLUMN osm_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bookcase ADD COLUMN source VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE bookcase ADD COLUMN title_provisional BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C41EF968A65EB5CF ON bookcase (osm_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C41EF968A65EB5CF');
        $this->addSql('ALTER TABLE bookcase DROP COLUMN osm_id');
        $this->addSql('ALTER TABLE bookcase DROP COLUMN source');
        $this->addSql('ALTER TABLE bookcase DROP COLUMN title_provisional');
    }
}
