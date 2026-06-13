<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611170906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wishlist_item.dropped_by_id (the user who dropped the wished book) for the wishlist hand-off flow.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__wishlist_item AS SELECT id, bookcase_id, user_id, title, isbn, author, misc, status FROM wishlist_item');
        $this->addSql('DROP TABLE wishlist_item');
        $this->addSql('CREATE TABLE wishlist_item (id BLOB NOT NULL, bookcase_id BLOB NOT NULL, user_id BLOB NOT NULL, title VARCHAR(255) DEFAULT NULL, isbn VARCHAR(255) DEFAULT NULL, author VARCHAR(255) DEFAULT NULL, misc CLOB DEFAULT NULL, status VARCHAR(255) NOT NULL, dropped_by_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_6424F4E81FF438FB FOREIGN KEY (bookcase_id) REFERENCES bookcase (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6424F4E8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6424F4E8BA666680 FOREIGN KEY (dropped_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO wishlist_item (id, bookcase_id, user_id, title, isbn, author, misc, status) SELECT id, bookcase_id, user_id, title, isbn, author, misc, status FROM __temp__wishlist_item');
        $this->addSql('DROP TABLE __temp__wishlist_item');
        $this->addSql('CREATE INDEX IDX_6424F4E81FF438FB ON wishlist_item (bookcase_id)');
        $this->addSql('CREATE INDEX IDX_6424F4E8A76ED395 ON wishlist_item (user_id)');
        $this->addSql('CREATE INDEX IDX_6424F4E8BA666680 ON wishlist_item (dropped_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__wishlist_item AS SELECT id, title, isbn, author, misc, status, bookcase_id, user_id FROM wishlist_item');
        $this->addSql('DROP TABLE wishlist_item');
        $this->addSql('CREATE TABLE wishlist_item (id BLOB NOT NULL, title VARCHAR(255) DEFAULT NULL, isbn VARCHAR(255) DEFAULT NULL, author VARCHAR(255) DEFAULT NULL, misc CLOB DEFAULT NULL, status VARCHAR(255) NOT NULL, bookcase_id BLOB NOT NULL, user_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_6424F4E81FF438FB FOREIGN KEY (bookcase_id) REFERENCES bookcase (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_6424F4E8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO wishlist_item (id, title, isbn, author, misc, status, bookcase_id, user_id) SELECT id, title, isbn, author, misc, status, bookcase_id, user_id FROM __temp__wishlist_item');
        $this->addSql('DROP TABLE __temp__wishlist_item');
        $this->addSql('CREATE INDEX IDX_6424F4E81FF438FB ON wishlist_item (bookcase_id)');
        $this->addSql('CREATE INDEX IDX_6424F4E8A76ED395 ON wishlist_item (user_id)');
    }
}
