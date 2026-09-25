<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TOOI link to subject (canonical government vocabulary)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject ADD tooi_uri VARCHAR(255) DEFAULT NULL, ADD tooi_label VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject DROP tooi_uri, DROP tooi_label');
    }
}