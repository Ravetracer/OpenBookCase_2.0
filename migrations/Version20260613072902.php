<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613072902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_bookcase archive table for soft-deleting bookcases (snapshot + reason).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deleted_bookcase (id BLOB NOT NULL, original_id VARCHAR(26) NOT NULL, title VARCHAR(255) DEFAULT NULL, payload CLOB NOT NULL, reason CLOB NOT NULL, deleted_by VARCHAR(255) DEFAULT NULL, deleted_at DATETIME NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE deleted_bookcase');
    }
}
