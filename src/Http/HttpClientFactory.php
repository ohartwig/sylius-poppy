<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Http;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client that fetches Personal Agents' metadata and key sets.
 *
 * No redirects, short timeouts: the request runs inside a token request an
 * agent is waiting on.
 *
 * Direct, NoPrivateNetworkHttpClient checks the address actually connected
 * to, which closes the gap the pre-flight check in koh/poppy leaves (a DNS
 * answer that changes between check and connect).
 *
 * Through a proxy that check cannot stay: the address connected to is the
 * proxy's, which in a cluster is a private one, and every fetch would be
 * refused ("IP ... is blocked"). The proxy resolves the name and connects, so
 * keeping private destinations out is its job there; the pre-flight check in
 * koh/poppy still applies. NO_PROXY is ignored for this client, so that no
 * host can step around the proxy and lose both checks.
 *
 * The proxy is KOH_POPPY_PROXY if set, else HTTPS_PROXY. The own setting
 * exists because an egress proxy that admits only listed hosts cannot admit
 * an agent's domain, which is whatever the agent says it is: such a setup
 * gives this fetch a port of its own.
 */
final class HttpClientFactory
{
    public static function create(): ClientInterface
    {
        return self::createFor(self::chosenProxy());
    }

    /** The proxy this fetch uses: the own setting first, then the environment's. */
    public static function chosenProxy(): ?string
    {
        return self::proxyFrom(self::env('KOH_POPPY_PROXY'), 'KOH_POPPY_PROXY')
            ?? self::proxyFrom(self::env('HTTPS_PROXY') ?? self::env('https_proxy'), 'HTTPS_PROXY');
    }

    public static function createFor(?string $proxy): ClientInterface
    {
        return new Psr18Client(self::symfonyClient($proxy));
    }

    /**
     * Reads a proxy setting: an http:// or https:// URL. Unset or empty means
     * none. Anything else is refused, so a typo cannot silently send the
     * fetch direct or somewhere else.
     */
    public static function proxyFrom(mixed $value, string $name = 'KOH_POPPY_PROXY'): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new \InvalidArgumentException($name . ' must be an http:// or https:// URL.');
        }

        return $value;
    }

    /** @internal exposed for the test of which guard wraps the client */
    public static function symfonyClient(?string $proxy): HttpClientInterface
    {
        $options = [
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
            'headers' => ['User-Agent' => 'koh-sylius-poppy (+https://github.com/ohartwig/sylius-poppy)'],
        ];

        if ($proxy !== null) {
            return HttpClient::create($options + ['proxy' => $proxy, 'no_proxy' => '']);
        }

        // `no_proxy: *` is stated, not inherited: this branch is also reached
        // through createFor(null), and a proxy picked up from the environment
        // behind the guard's back would make it refuse every fetch.
        return new NoPrivateNetworkHttpClient(HttpClient::create($options + ['no_proxy' => '*']));
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
