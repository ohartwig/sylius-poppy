<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Doctrine;

use Doctrine\DBAL\Schema\AbstractAsset;

/**
 * Keeps the Poppy tables out of Doctrine's ORM schema management.
 *
 * They are written through DBAL and have no entity mapping, so
 * `doctrine:schema:validate` and `doctrine:migrations:diff` would otherwise
 * read them as surplus and propose to drop them — and a schema check in CI
 * fails on exactly that. Registered as a tagged filter, so it adds to an
 * application's own `schema_filter` instead of replacing it. The bundle's
 * migration creates the tables with plain SQL and does not depend on what
 * this filter hides.
 */
final readonly class PoppySchemaFilter
{
    public function __construct(private string $prefix = 'koh_poppy_')
    {
    }

    /** @param string|AbstractAsset<\Doctrine\DBAL\Schema\Name> $asset */
    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;

        return !str_starts_with($name, $this->prefix);
    }
}
