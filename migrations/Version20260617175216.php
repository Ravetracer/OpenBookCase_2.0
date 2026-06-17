<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API usage logging (Phase 4): the api_usage_log table — one row per authenticated
 * /api/v1 request (acting app + user, method/route/path, request payload, status).
 */
final class Version20260617175216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_usage_log table for API usage logging.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_usage_log (id BLOB NOT NULL, oauth_client_id VARCHAR(64) DEFAULT NULL, method VARCHAR(10) NOT NULL, route_name VARCHAR(128) DEFAULT NULL, path VARCHAR(255) NOT NULL, request_payload CLOB DEFAULT NULL, status_code SMALLINT NOT NULL, created_at DATETIME NOT NULL, api_application_id BLOB DEFAULT NULL, acting_user_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_89C48F91DAA5A873 FOREIGN KEY (api_application_id) REFERENCES api_application (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_89C48F913EAD8611 FOREIGN KEY (acting_user_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_89C48F91DAA5A873 ON api_usage_log (api_application_id)');
        $this->addSql('CREATE INDEX IDX_89C48F913EAD8611 ON api_usage_log (acting_user_id)');
        $this->addSql('CREATE INDEX api_usage_created ON api_usage_log (created_at)');
        $this->addSql('CREATE INDEX api_usage_client ON api_usage_log (oauth_client_id)');
        $this->addSql('CREATE INDEX api_usage_route ON api_usage_log (route_name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE api_usage_log');
    }
}
