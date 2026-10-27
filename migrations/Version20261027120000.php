<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Koh\Poppy\Storage\Dbal\PoppySchema;

/**
 * koh/sylius-poppy: Sessions, Account and Session Tokens, codes, device
 * authorizations, replay records and feedback.
 *
 * The tables come from koh/poppy's PoppySchema, the one definition the TYPO3
 * integration uses too. The prefix is the default `koh_poppy_`; a shop that
 * configured another `table_prefix` writes its own migration.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'koh/sylius-poppy: Personal Agent Protocol tables';
    }

    /**
     * Plain SQL from the platform rather than changes to $schema: the bundle's
     * schema filter hides these tables from Doctrine's schema tools, and a
     * migration that went through them would not see what it created.
     */
    public function up(Schema $schema): void
    {
        $tables = new Schema();
        PoppySchema::addTo($tables, 'koh_poppy_');
        foreach ($tables->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (PoppySchema::tableNames('koh_poppy_') as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
