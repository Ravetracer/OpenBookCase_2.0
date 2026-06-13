<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the obsolete bookcase.share_link column. Sharing is now done via a
 * deep link to the entry's detail dialog (route app_bookcase_show).
 */
final class Version20260611000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove bookcase.share_link column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookcase DROP COLUMN share_link');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookcase ADD COLUMN share_link VARCHAR(128) DEFAULT NULL');
    }
}
