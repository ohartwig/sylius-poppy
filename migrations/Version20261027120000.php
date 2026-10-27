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

    public function up(Schema $schema): void
    {
        PoppySchema::addTo($schema, 'koh_poppy_');
    }

    public function down(Schema $schema): void
    {
        foreach (PoppySchema::tableNames('koh_poppy_') as $table) {
            $schema->dropTable($table);
        }
    }
}
