<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611152711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE message (id BLOB NOT NULL, type VARCHAR(32) NOT NULL, subject VARCHAR(255) DEFAULT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id BLOB NOT NULL, related_bookcase_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_B6BD307FE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6BD307FD41F467F FOREIGN KEY (related_bookcase_id) REFERENCES bookcase (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B6BD307FE92F8F78 ON message (recipient_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FD41F467F ON message (related_bookcase_id)');
        $this->addSql('CREATE INDEX recipient_read ON message (recipient_id, read_at)');
        $this->addSql('ALTER TABLE user ADD COLUMN notification_channel VARCHAR(16) DEFAULT \'internal\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE message');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, roles, password, email, is_verified, legacy_id, legacy_password, legacy_user, legacy_migrated, language FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, is_verified BOOLEAN NOT NULL, legacy_id INTEGER DEFAULT NULL, legacy_password VARCHAR(255) DEFAULT NULL, legacy_user BOOLEAN DEFAULT NULL, legacy_migrated BOOLEAN DEFAULT NULL, language VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO user (id, username, roles, password, email, is_verified, legacy_id, legacy_password, legacy_user, legacy_migrated, language) SELECT id, username, roles, password, email, is_verified, legacy_id, legacy_password, legacy_user, legacy_migrated, language FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
    }
}
