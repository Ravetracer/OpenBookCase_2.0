<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611154613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE watchlist_item (id BLOB NOT NULL, created_at DATETIME NOT NULL, user_id BLOB NOT NULL, bookcase_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1DEA83F6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1DEA83F61FF438FB FOREIGN KEY (bookcase_id) REFERENCES bookcase (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1DEA83F6A76ED395 ON watchlist_item (user_id)');
        $this->addSql('CREATE INDEX IDX_1DEA83F61FF438FB ON watchlist_item (bookcase_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_watch_user_bookcase ON watchlist_item (user_id, bookcase_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE watchlist_item');
    }
}
