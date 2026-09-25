<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_key table for publication API bearer keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE api_key (id UUID NOT NULL, organisation_id UUID NOT NULL, name VARCHAR(255) NOT NULL, key_prefix VARCHAR(12) NOT NULL, key_hash VARCHAR(64) NOT NULL, enabled BOOLEAN NOT NULL, expires_at TIMESTAMPTZ(0) DEFAULT NULL, last_used_at TIMESTAMPTZ(0) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_api_key_hash ON api_key (key_hash)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_api_key_organisation ON api_key (organisation_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE api_key ADD CONSTRAINT fk_api_key_organisation FOREIGN KEY (organisation_id) REFERENCES organisation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_key');
    }
}