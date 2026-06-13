<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611065636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__image AS SELECT id, bookcase_id, uploaded_by_id, author, filename, filename_thumbnail, image_size, rotation, updated_at FROM image');
        $this->addSql('DROP TABLE image');
        $this->addSql('CREATE TABLE image (id BLOB NOT NULL, bookcase_id BLOB NOT NULL, uploaded_by_id BLOB DEFAULT NULL, author VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, filename_thumbnail VARCHAR(255) DEFAULT NULL, image_size INTEGER NOT NULL, rotation INTEGER DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C53D045F1FF438FB FOREIGN KEY (bookcase_id) REFERENCES bookcase (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C53D045FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO image (id, bookcase_id, uploaded_by_id, author, filename, filename_thumbnail, image_size, rotation, updated_at) SELECT id, bookcase_id, uploaded_by_id, author, filename, filename_thumbnail, image_size, rotation, updated_at FROM __temp__image');
        $this->addSql('DROP TABLE __temp__image');
        $this->addSql('CREATE INDEX IDX_C53D045F1FF438FB ON image (bookcase_id)');
        $this->addSql('CREATE INDEX IDX_C53D045FA2B28FE8 ON image (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__image AS SELECT id, author, filename, filename_thumbnail, image_size, rotation, updated_at, bookcase_id, uploaded_by_id FROM image');
        $this->addSql('DROP TABLE image');
        $this->addSql('CREATE TABLE image (id BLOB NOT NULL, author VARCHAR(255) NOT NULL, filename VARCHAR(255) NOT NULL, filename_thumbnail VARCHAR(255) DEFAULT NULL, image_size INTEGER NOT NULL, rotation INTEGER DEFAULT NULL, updated_at DATETIME NOT NULL, bookcase_id BLOB NOT NULL, uploaded_by_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C53D045F1FF438FB FOREIGN KEY (bookcase_id) REFERENCES bookcase (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C53D045FA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO image (id, author, filename, filename_thumbnail, image_size, rotation, updated_at, bookcase_id, uploaded_by_id) SELECT id, author, filename, filename_thumbnail, image_size, rotation, updated_at, bookcase_id, uploaded_by_id FROM __temp__image');
        $this->addSql('DROP TABLE __temp__image');
        $this->addSql('CREATE INDEX IDX_C53D045F1FF438FB ON image (bookcase_id)');
        $this->addSql('CREATE INDEX IDX_C53D045FA2B28FE8 ON image (uploaded_by_id)');
    }
}
