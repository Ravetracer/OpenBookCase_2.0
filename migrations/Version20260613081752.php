<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613081752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user home map location (lat/lon/zoom) + opt-in flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD COLUMN home_latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN home_longitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN home_zoom INTEGER DEFAULT NULL');
        // DEFAULT 0 so the NOT NULL column can be added to existing rows on SQLite.
        $this->addSql('ALTER TABLE user ADD COLUMN use_home_location BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, notification_channel VARCHAR(16) DEFAULT \'internal\' NOT NULL, legacy_id INTEGER DEFAULT NULL, legacy_password VARCHAR(255) DEFAULT NULL, legacy_user BOOLEAN DEFAULT NULL, legacy_migrated BOOLEAN DEFAULT NULL, language VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO user (id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language) SELECT id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
    }
}
