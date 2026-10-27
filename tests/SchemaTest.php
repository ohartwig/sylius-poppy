<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Koh\SyliusPoppy\Doctrine\PoppySchemaFilter;
use Koh\SyliusPoppy\Migrations\Version20261027120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SchemaTest extends TestCase
{
    public function testTheFilterHidesOnlyThePoppyTables(): void
    {
        $filter = new PoppySchemaFilter();

        self::assertFalse($filter('koh_poppy_session'));
        self::assertFalse($filter(new Table('koh_poppy_replay')));
        self::assertTrue($filter('sylius_order'));
        self::assertTrue($filter('app_koh_poppy_session'), 'a prefix, not a substring');
        self::assertFalse((new PoppySchemaFilter('tx_'))('tx_kohpoppy_session'));
    }

    public function testTheMigrationCreatesAndDropsTheTablesWithPlainSql(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new Version20261027120000($connection, new NullLogger());

        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $tables = $connection->createSchemaManager()->listTableNames();
        sort($tables);
        self::assertSame(['koh_poppy_account_token', 'koh_poppy_authorization_code', 'koh_poppy_device_authorization', 'koh_poppy_feedback', 'koh_poppy_replay', 'koh_poppy_session', 'koh_poppy_session_token'], $tables);

        $down = new Version20261027120000($connection, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([], $connection->createSchemaManager()->listTableNames());
    }
}
