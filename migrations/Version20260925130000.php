<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create setting table for runtime app/AI settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE setting (key VARCHAR(100) NOT NULL, value TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(key))
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE setting');
    }
}