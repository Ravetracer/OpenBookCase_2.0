<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20230117135459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__bookcase AS SELECT id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data FROM bookcase');
        $this->addSql('DROP TABLE bookcase');
        $this->addSql('CREATE TABLE bookcase (id BLOB NOT NULL --(DC2Type:ulid)
        , title VARCHAR(255) NOT NULL, webpage VARCHAR(1024) DEFAULT NULL, mobility VARCHAR(255) DEFAULT NULL, entry_type VARCHAR(255) NOT NULL, installation_type VARCHAR(255) DEFAULT NULL, map_symbol VARCHAR(255) NOT NULL, digital_media_allowed BOOLEAN NOT NULL, share_link VARCHAR(128) DEFAULT NULL, legacy_id INTEGER DEFAULT NULL, comment CLOB DEFAULT NULL, position_latitude DOUBLE PRECISION NOT NULL, position_longitude DOUBLE PRECISION NOT NULL, accessibility_level INTEGER DEFAULT NULL, accessibility_description CLOB DEFAULT NULL, active_status VARCHAR(255) NOT NULL, active_status_description CLOB DEFAULT NULL, address_street VARCHAR(255) DEFAULT NULL, address_house_number VARCHAR(255) DEFAULT NULL, address_zipcode VARCHAR(128) DEFAULT NULL, address_city VARCHAR(255) DEFAULT NULL, address_additional_data CLOB DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO bookcase (id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data) SELECT id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data FROM __temp__bookcase');
        $this->addSql('DROP TABLE __temp__bookcase');
        $this->addSql('CREATE INDEX latitude ON bookcase (position_latitude)');
        $this->addSql('CREATE INDEX longitude ON bookcase (position_longitude)');
        $this->addSql('CREATE INDEX legacyId ON bookcase (legacy_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__bookcase AS SELECT id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data FROM bookcase');
        $this->addSql('DROP TABLE bookcase');
        $this->addSql('CREATE TABLE bookcase (id BLOB NOT NULL --(DC2Type:ulid)
        , title VARCHAR(255) NOT NULL, webpage VARCHAR(1024) DEFAULT NULL, mobility VARCHAR(255) DEFAULT NULL, entry_type VARCHAR(255) NOT NULL, installation_type VARCHAR(255) DEFAULT NULL, map_symbol VARCHAR(255) NOT NULL, digital_media_allowed BOOLEAN NOT NULL, share_link VARCHAR(128) DEFAULT NULL, legacy_id INTEGER DEFAULT NULL, comment CLOB DEFAULT NULL, position_latitude DOUBLE PRECISION NOT NULL, position_longitude DOUBLE PRECISION NOT NULL, accessibility_level INTEGER DEFAULT NULL, accessibility_description CLOB DEFAULT NULL, active_status VARCHAR(255) NOT NULL, active_status_description CLOB DEFAULT NULL, address_street VARCHAR(255) DEFAULT NULL, address_house_number VARCHAR(255) DEFAULT NULL, address_zipcode VARCHAR(128) DEFAULT NULL, address_city VARCHAR(255) DEFAULT NULL, address_additional_data CLOB DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('INSERT INTO bookcase (id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data) SELECT id, title, webpage, mobility, entry_type, installation_type, map_symbol, digital_media_allowed, share_link, legacy_id, comment, position_latitude, position_longitude, accessibility_level, accessibility_description, active_status, active_status_description, address_street, address_house_number, address_zipcode, address_city, address_additional_data FROM __temp__bookcase');
        $this->addSql('DROP TABLE __temp__bookcase');
    }
}
