<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API access (Phase 1): the api_application table (developer applications for
 * API access, admin-vetted) plus two nullable Message columns (sender_id,
 * api_application_id) that turn an otherwise one-way message into a scoped,
 * reply-enabled admin↔applicant conversation thread.
 */
final class Version20260617130412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_application table + message.sender/api_application for API-access applications and conversations.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_application (id BLOB NOT NULL, app_name VARCHAR(100) NOT NULL, use_case CLOB NOT NULL, client_type VARCHAR(16) NOT NULL, redirect_uris CLOB NOT NULL, requested_scopes CLOB NOT NULL, status VARCHAR(16) NOT NULL, decision_reason CLOB DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, applicant_id BLOB NOT NULL, decided_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_CE2C574F97139001 FOREIGN KEY (applicant_id) REFERENCES user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CE2C574FE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_CE2C574F97139001 ON api_application (applicant_id)');
        $this->addSql('CREATE INDEX IDX_CE2C574FE26B496B ON api_application (decided_by_id)');
        $this->addSql('CREATE INDEX api_application_status ON api_application (status)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__message AS SELECT id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id FROM message');
        $this->addSql('DROP TABLE message');
        $this->addSql('CREATE TABLE message (id BLOB NOT NULL, type VARCHAR(32) NOT NULL, subject VARCHAR(255) DEFAULT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id BLOB NOT NULL, related_bookcase_id BLOB DEFAULT NULL, sender_id BLOB DEFAULT NULL, api_application_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_B6BD307FE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6BD307FD41F467F FOREIGN KEY (related_bookcase_id) REFERENCES bookcase (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6BD307FF624B39D FOREIGN KEY (sender_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6BD307FDAA5A873 FOREIGN KEY (api_application_id) REFERENCES api_application (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO message (id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id) SELECT id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id FROM __temp__message');
        $this->addSql('DROP TABLE __temp__message');
        $this->addSql('CREATE INDEX recipient_read ON message (recipient_id, read_at)');
        $this->addSql('CREATE INDEX IDX_B6BD307FD41F467F ON message (related_bookcase_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FE92F8F78 ON message (recipient_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FF624B39D ON message (sender_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FDAA5A873 ON message (api_application_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE api_application');
        $this->addSql('CREATE TEMPORARY TABLE __temp__message AS SELECT id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id FROM message');
        $this->addSql('DROP TABLE message');
        $this->addSql('CREATE TABLE message (id BLOB NOT NULL, type VARCHAR(32) NOT NULL, subject VARCHAR(255) DEFAULT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, recipient_id BLOB NOT NULL, related_bookcase_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_B6BD307FE92F8F78 FOREIGN KEY (recipient_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B6BD307FD41F467F FOREIGN KEY (related_bookcase_id) REFERENCES bookcase (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO message (id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id) SELECT id, type, subject, body, created_at, read_at, recipient_id, related_bookcase_id FROM __temp__message');
        $this->addSql('DROP TABLE __temp__message');
        $this->addSql('CREATE INDEX IDX_B6BD307FE92F8F78 ON message (recipient_id)');
        $this->addSql('CREATE INDEX IDX_B6BD307FD41F467F ON message (related_bookcase_id)');
        $this->addSql('CREATE INDEX recipient_read ON message (recipient_id, read_at)');
    }
}
