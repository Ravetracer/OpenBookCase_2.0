<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add image.alt_text — screen-reader description (alt attribute) for photos.
 */
final class Version20260612090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable image.alt_text (screen-reader alt text for photos).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE image ADD COLUMN alt_text VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE image DROP COLUMN alt_text');
    }
}
