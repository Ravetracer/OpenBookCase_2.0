<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace the free-text bookcase.mobility with a boolean is_mobile
 * (true = mobile installation, false = fixed). The old column was never
 * populated by the import, so all rows default to fixed (0).
 *
 * Uses the SQLite table-rebuild dance so is_mobile ends up as a plain
 * `BOOLEAN NOT NULL` (no leftover DEFAULT), matching the Doctrine mapping.
 */
final class Version20260612090500 extends AbstractMigration
{
    private const BASE_COLUMNS = 'id, title, webpage, entry_type, installation_type, map_symbol,'
        . ' digital_media_allowed, legacy_id, comment, position_latitude, position_longitude,'
        . ' accessibility_level, accessibility_description, active_status, active_status_description,'
        . ' address_street, address_house_number, address_zipcode, address_city, address_additional_data';

    public function getDescription(): string
    {
        return 'Replace bookcase.mobility (string) with boolean bookcase.is_mobile.';
    }

    public function up(Schema $schema): void
    {
        $cols = self::BASE_COLUMNS;
        $this->addSql("CREATE TEMPORARY TABLE __temp__bookcase AS SELECT $cols FROM bookcase");
        $this->addSql('DROP TABLE bookcase');
        $this->addSql('CREATE TABLE bookcase (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, webpage VARCHAR(1024) DEFAULT NULL, entry_type VARCHAR(255) NOT NULL, installation_type VARCHAR(255) DEFAULT NULL, map_symbol VARCHAR(255) NOT NULL, digital_media_allowed BOOLEAN NOT NULL, legacy_id INTEGER DEFAULT NULL, comment CLOB DEFAULT NULL, position_latitude DOUBLE PRECISION NOT NULL, position_longitude DOUBLE PRECISION NOT NULL, accessibility_level INTEGER DEFAULT NULL, accessibility_description CLOB DEFAULT NULL, active_status VARCHAR(255) NOT NULL, active_status_description CLOB DEFAULT NULL, address_street VARCHAR(255) DEFAULT NULL, address_house_number VARCHAR(255) DEFAULT NULL, address_zipcode VARCHAR(128) DEFAULT NULL, address_city VARCHAR(255) DEFAULT NULL, address_additional_data CLOB DEFAULT NULL, is_mobile BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql("INSERT INTO bookcase ($cols, is_mobile) SELECT $cols, 0 FROM __temp__bookcase");
        $this->addSql('DROP TABLE __temp__bookcase');
        $this->addSql('CREATE INDEX latitude ON bookcase (position_latitude)');
        $this->addSql('CREATE INDEX longitude ON bookcase (position_longitude)');
        $this->addSql('CREATE INDEX legacyId ON bookcase (legacy_id)');
    }

    public function down(Schema $schema): void
    {
        $cols = self::BASE_COLUMNS;
        $this->addSql("CREATE TEMPORARY TABLE __temp__bookcase AS SELECT $cols FROM bookcase");
        $this->addSql('DROP TABLE bookcase');
        $this->addSql('CREATE TABLE bookcase (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, webpage VARCHAR(1024) DEFAULT NULL, entry_type VARCHAR(255) NOT NULL, installation_type VARCHAR(255) DEFAULT NULL, map_symbol VARCHAR(255) NOT NULL, digital_media_allowed BOOLEAN NOT NULL, legacy_id INTEGER DEFAULT NULL, comment CLOB DEFAULT NULL, position_latitude DOUBLE PRECISION NOT NULL, position_longitude DOUBLE PRECISION NOT NULL, accessibility_level INTEGER DEFAULT NULL, accessibility_description CLOB DEFAULT NULL, active_status VARCHAR(255) NOT NULL, active_status_description CLOB DEFAULT NULL, address_street VARCHAR(255) DEFAULT NULL, address_house_number VARCHAR(255) DEFAULT NULL, address_zipcode VARCHAR(128) DEFAULT NULL, address_city VARCHAR(255) DEFAULT NULL, address_additional_data CLOB DEFAULT NULL, mobility VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql("INSERT INTO bookcase ($cols) SELECT $cols FROM __temp__bookcase");
        $this->addSql('DROP TABLE __temp__bookcase');
        $this->addSql('CREATE INDEX latitude ON bookcase (position_latitude)');
        $this->addSql('CREATE INDEX longitude ON bookcase (position_longitude)');
        $this->addSql('CREATE INDEX legacyId ON bookcase (legacy_id)');
    }
}
