<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\DependencyInjection;

use Koh\Poppy\Client\ClientPolicy;
use Koh\Poppy\Client\ListClientPolicy;
use Koh\Poppy\Dpop\NonceSource;
use Koh\Poppy\Dpop\TimeSlotNonceSource;
use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\SymfonyRateLimiter;
use Koh\SyliusPoppy\Poppy\Settings;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Builds the services whose arguments come from the configuration, so
 * config/services.php stays declarative.
 *
 * @internal
 */
final class ServiceFactory
{
    public static function clientPolicy(Settings $settings): ClientPolicy
    {
        return new ListClientPolicy($settings->allowClients, $settings->blockClients);
    }

    /** @param array<mixed> $limits */
    public static function rateLimiter(array $limits, CacheItemPoolInterface $cache): RateLimiter
    {
        $storage = new CacheStorage($cache);
        $factories = [];
        foreach ($limits as $bucket => $limit) {
            if (!\is_string($bucket) || !\is_array($limit) || !\is_int($limit['limit'] ?? null) || $limit['limit'] <= 0) {
                continue;
            }
            $factories[$bucket] = new RateLimiterFactory([
                'id' => 'koh_poppy_'.$bucket,
                'policy' => 'sliding_window',
                'limit' => $limit['limit'],
                'interval' => \is_string($limit['interval'] ?? null) ? $limit['interval'] : '1 minute',
            ], $storage);
        }

        return new SymfonyRateLimiter($factories);
    }

    /** Derived from the application secret, so every replica issues and accepts the same nonces. */
    public static function nonceSource(string $secret, ClockInterface $clock): NonceSource
    {
        return new TimeSlotNonceSource(hash_hmac('sha256', 'koh-poppy-dpop-nonce', $secret, true), $clock);
    }

    public static function browserCookie(string $secret): BrowserCookie
    {
        return new BrowserCookie(hash_hmac('sha256', 'koh-poppy-browser-cookie', $secret, true));
    }
}
