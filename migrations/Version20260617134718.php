<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API access (Phase 2): the league/oauth2-server-bundle tables (oauth2_client,
 * oauth2_access_token, oauth2_refresh_token, oauth2_authorization_code) plus two
 * columns linking an ApiApplication to its provisioned OAuth client
 * (oauth_client_id, unique) and holding its show-once secret (oauth_plain_secret).
 */
final class Version20260617134718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth2 server tables + api_application.oauth_client_id/oauth_plain_secret for client provisioning.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth2_access_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes CLOB DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_454D9673C7440455 ON oauth2_access_token (client)');
        $this->addSql('CREATE TABLE oauth2_authorization_code (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes CLOB DEFAULT NULL, revoked BOOLEAN NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_509FEF5FC7440455 ON oauth2_authorization_code (client)');
        $this->addSql('CREATE TABLE oauth2_client (name VARCHAR(128) NOT NULL, secret VARCHAR(128) DEFAULT NULL, redirect_uris CLOB DEFAULT NULL, grants CLOB DEFAULT NULL, scopes CLOB DEFAULT NULL, active BOOLEAN NOT NULL, allow_plain_text_pkce BOOLEAN DEFAULT 0 NOT NULL, identifier VARCHAR(32) NOT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE TABLE oauth2_refresh_token (identifier CHAR(80) NOT NULL, expiry DATETIME NOT NULL, revoked BOOLEAN NOT NULL, access_token CHAR(80) DEFAULT NULL, PRIMARY KEY (identifier), CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4DD90732B6A2DD68 ON oauth2_refresh_token (access_token)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__api_application AS SELECT id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id FROM api_application');
        $this->addSql('DROP TABLE api_application');
        $this->addSql('CREATE TABLE api_application (id BLOB NOT NULL, app_name VARCHAR(100) NOT NULL, use_case CLOB NOT NULL, client_type VARCHAR(16) NOT NULL, redirect_uris CLOB NOT NULL, requested_scopes CLOB NOT NULL, status VARCHAR(16) NOT NULL, decision_reason CLOB DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, applicant_id BLOB NOT NULL, decided_by_id BLOB DEFAULT NULL, oauth_client_id VARCHAR(64) DEFAULT NULL, oauth_plain_secret VARCHAR(128) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_CE2C574F97139001 FOREIGN KEY (applicant_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CE2C574FE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO api_application (id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id) SELECT id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id FROM __temp__api_application');
        $this->addSql('DROP TABLE __temp__api_application');
        $this->addSql('CREATE INDEX api_application_status ON api_application (status)');
        $this->addSql('CREATE INDEX IDX_CE2C574FE26B496B ON api_application (decided_by_id)');
        $this->addSql('CREATE INDEX IDX_CE2C574F97139001 ON api_application (applicant_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CE2C574FDCA49ED ON api_application (oauth_client_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_client');
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('CREATE TEMPORARY TABLE __temp__api_application AS SELECT id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id FROM api_application');
        $this->addSql('DROP TABLE api_application');
        $this->addSql('CREATE TABLE api_application (id BLOB NOT NULL, app_name VARCHAR(100) NOT NULL, use_case CLOB NOT NULL, client_type VARCHAR(16) NOT NULL, redirect_uris CLOB NOT NULL, requested_scopes CLOB NOT NULL, status VARCHAR(16) NOT NULL, decision_reason CLOB DEFAULT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, applicant_id BLOB NOT NULL, decided_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_CE2C574F97139001 FOREIGN KEY (applicant_id) REFERENCES user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CE2C574FE26B496B FOREIGN KEY (decided_by_id) REFERENCES user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO api_application (id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id) SELECT id, app_name, use_case, client_type, redirect_uris, requested_scopes, status, decision_reason, decided_at, created_at, applicant_id, decided_by_id FROM __temp__api_application');
        $this->addSql('DROP TABLE __temp__api_application');
        $this->addSql('CREATE INDEX IDX_CE2C574F97139001 ON api_application (applicant_id)');
        $this->addSql('CREATE INDEX IDX_CE2C574FE26B496B ON api_application (decided_by_id)');
        $this->addSql('CREATE INDEX api_application_status ON api_application (status)');
    }
}
