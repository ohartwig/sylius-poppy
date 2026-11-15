<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Koh\SyliusPoppy\Http\HttpClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

final class HttpClientFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['KOH_POPPY_PROXY', 'HTTPS_PROXY', 'https_proxy'] as $name) {
            $this->saved[$name] = getenv($name);
            $this->setEnv($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setEnv($name, $value === false ? null : $value);
        }
    }

    public function testDirectFetchKeepsThePrivateNetworkGuard(): void
    {
        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, HttpClientFactory::symfonyClient(null));
    }

    public function testDirectFetchRefusesAPrivateAddress(): void
    {
        $this->expectExceptionMessageMatches('/is blocked/');

        HttpClientFactory::symfonyClient(null)->request('GET', 'https://10.0.0.1/agent.json')->getStatusCode();
    }

    /**
     * Through a proxy the address connected to is the proxy's own. In a
     * cluster that is a private one, and the guard refused every fetch
     * ("IP ... is blocked", measured 2026-11-15 on the first shop behind an
     * egress proxy).
     */
    public function testProxiedFetchDoesNotCarryTheGuard(): void
    {
        self::assertNotInstanceOf(NoPrivateNetworkHttpClient::class, HttpClientFactory::symfonyClient('http://egress-proxy:3129'));
    }

    public function testProxiedFetchGoesToTheProxyAndNowhereElse(): void
    {
        // A listener that answers every CONNECT with 403: the request reaching
        // it at all is the proof, and NO_PROXY naming the host must not let
        // the fetch step around it.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $this->setEnv('NO_PROXY', 'agent.example');

        try {
            $client = HttpClientFactory::symfonyClient('http://' . $address);
            $response = $client->request('GET', 'https://agent.example/agent.json');
            $connection = null;
            // The transfer is asynchronous: tick it until the proxy has been asked.
            for ($i = 0; $i < 50 && $connection === null; ++$i) {
                try {
                    foreach ($client->stream($response, 0.05) as $chunk) {
                        // Asking marks the timeout chunk as handled; unasked, it throws when dropped.
                        $chunk->isTimeout();
                        break;
                    }
                } catch (\Throwable) {
                }
                $connection = @stream_socket_accept($server, 0.05) ?: null;
            }
            self::assertNotNull($connection, 'the fetch never reached the proxy');
            $line = (string) fgets($connection);
            fwrite($connection, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
            fclose($connection);
            $response->cancel();

            self::assertStringStartsWith('CONNECT agent.example:443', $line);
        } finally {
            $this->setEnv('NO_PROXY', null);
            fclose($server);
        }
    }

    public function testOwnProxyWinsOverTheEnvironmentProxy(): void
    {
        $this->setEnv('HTTPS_PROXY', 'http://egress-proxy:3128');
        $this->setEnv('KOH_POPPY_PROXY', 'http://egress-proxy:3129');

        self::assertSame('http://egress-proxy:3129', $this->chosenProxy());
    }

    public function testEnvironmentProxyIsUsedWithoutAnOwnOne(): void
    {
        $this->setEnv('HTTPS_PROXY', 'http://egress-proxy:3128');

        self::assertSame('http://egress-proxy:3128', $this->chosenProxy());
    }

    public function testNoProxyAtAllMeansDirect(): void
    {
        self::assertNull($this->chosenProxy());
    }

    /** @return iterable<string, array{mixed}> */
    public static function unset(): iterable
    {
        yield 'null' => [null];
        yield 'false (getenv miss)' => [false];
        yield 'empty' => [''];
        yield 'blank' => ['  '];
    }

    #[DataProvider('unset')]
    public function testUnsetProxyIsNone(mixed $value): void
    {
        self::assertNull(HttpClientFactory::proxyFrom($value));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'no scheme' => ['egress-proxy:3129'];
        yield 'socks' => ['socks5://egress-proxy:1080'];
        yield 'no host' => ['http://'];
    }

    #[DataProvider('invalid')]
    public function testInvalidProxyIsRefused(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('KOH_POPPY_PROXY must be an http:// or https:// URL.');

        HttpClientFactory::proxyFrom($value);
    }

    public function testProxyValueIsTrimmed(): void
    {
        self::assertSame('http://egress-proxy:3129', HttpClientFactory::proxyFrom(" http://egress-proxy:3129\n"));
    }

    private function chosenProxy(): ?string
    {
        return HttpClientFactory::chosenProxy();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);

            return;
        }
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }
}
