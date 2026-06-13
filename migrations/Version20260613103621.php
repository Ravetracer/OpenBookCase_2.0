<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613103621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional user-chosen label for the home position.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD COLUMN home_label VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language, home_latitude, home_longitude, home_zoom, use_home_location, reset_token_hash, reset_token_expires_at FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, notification_channel VARCHAR(16) DEFAULT \'internal\' NOT NULL, legacy_id INTEGER DEFAULT NULL, legacy_password VARCHAR(255) DEFAULT NULL, legacy_user BOOLEAN DEFAULT NULL, legacy_migrated BOOLEAN DEFAULT NULL, language VARCHAR(255) DEFAULT NULL, home_latitude DOUBLE PRECISION DEFAULT NULL, home_longitude DOUBLE PRECISION DEFAULT NULL, home_zoom INTEGER DEFAULT NULL, use_home_location BOOLEAN DEFAULT 0 NOT NULL, reset_token_hash VARCHAR(64) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO user (id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language, home_latitude, home_longitude, home_zoom, use_home_location, reset_token_hash, reset_token_expires_at) SELECT id, username, roles, password, email, is_verified, notification_channel, legacy_id, legacy_password, legacy_user, legacy_migrated, language, home_latitude, home_longitude, home_zoom, use_home_location, reset_token_hash, reset_token_expires_at FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
    }
}
