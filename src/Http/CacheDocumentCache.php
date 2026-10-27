<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Http;

use Koh\Poppy\Http\DocumentCache;
use Psr\Cache\CacheItemPoolInterface;

/** Personal Agents' metadata documents and key sets on the application cache. */
final readonly class CacheDocumentCache implements DocumentCache
{
    public function __construct(private CacheItemPoolInterface $pool)
    {
    }

    public function get(string $key): ?array
    {
        $item = $this->pool->getItem($key);
        $value = $item->isHit() ? $item->get() : null;

        /** @var array<string, mixed>|null */
        return \is_array($value) ? $value : null;
    }

    public function set(string $key, array $document, int $ttl): void
    {
        $item = $this->pool->getItem($key);
        $this->pool->save($item->set($document)->expiresAfter($ttl));
    }
}
