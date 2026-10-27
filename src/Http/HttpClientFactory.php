<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Http;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The HTTP client that fetches Personal Agents' metadata and key sets.
 *
 * NoPrivateNetworkHttpClient checks the address actually connected to, which
 * closes the gap the pre-flight check in koh/poppy leaves (a DNS answer that
 * changes between check and connect). No redirects, short timeouts: the
 * request runs inside a token request an agent is waiting on.
 */
final class HttpClientFactory
{
    public static function create(): ClientInterface
    {
        return new Psr18Client(new NoPrivateNetworkHttpClient(HttpClient::create([
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 10,
            'headers' => ['User-Agent' => 'koh-sylius-poppy (+https://github.com/ohartwig/sylius-poppy)'],
        ])));
    }
}
