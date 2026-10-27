<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Koh\Poppy\Client\ListClientPolicy;
use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\PublicAddressGuard;
use Koh\Poppy\Server\NoRateLimit;
use Koh\Poppy\Storage\Dbal\DbalFeedbackSink;
use Koh\Poppy\Storage\Dbal\DbalReplayGuard;
use Koh\Poppy\Storage\Dbal\DbalStorage;
use Koh\Poppy\Storage\Dbal\PoppySchema;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Koh\SyliusPoppy\Poppy\Settings;
use Nyholm\Psr7\Factory\Psr17Factory;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A shop at https://shop.example with Poppy on, backed by SQLite. */
final class ShopKit
{
    public const BASE = 'https://shop.example';

    public const ISSUER = 'https://shop.example/poppy';

    public readonly Connection $connection;

    public readonly FrozenClock $clock;

    public readonly FakeHttpClient $http;

    public readonly DbalStorage $storage;

    public readonly DbalFeedbackSink $feedback;

    public readonly CompanyResolver $companies;

    public function __construct(ChannelInterface $channel, Settings $settings = new Settings(shopApi: true), string $baseUrl = self::BASE)
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        PoppySchema::addTo($schema, 'koh_poppy_');
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->clock = new FrozenClock();
        $this->http = new FakeHttpClient();
        $this->storage = new DbalStorage($this->connection);
        $this->feedback = new DbalFeedbackSink($this->connection);

        $contexts = new class(new AgentContext($channel, $baseUrl, 'de_DE')) implements AgentContextFactoryInterface {
            public function __construct(private readonly AgentContext $context)
            {
            }

            public function create(?ChannelInterface $channel = null): AgentContext
            {
                return $this->context;
            }
        };

        $factory = new Psr17Factory();
        $this->companies = new CompanyResolver(
            $contexts,
            $settings,
            $this->storage,
            new DbalReplayGuard($this->connection, $this->clock),
            new ListClientPolicy(),
            new JsonFetcher($this->http, $factory, new PublicAddressGuard(static fn (): array => ['93.184.216.34'])),
            $this->clock,
            $factory,
            $factory,
            new NoRateLimit(),
        );
    }

    /** @param array<string, string> $parameters */
    public static function form(string $path, array $parameters, ?string $dpop = null): Request
    {
        $request = Request::create(self::BASE.$path, 'POST', $parameters, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986));
        if (null !== $dpop) {
            $request->headers->set('DPoP', $dpop);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    public static function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $body */
    public static function text(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        \assert(\is_string($value), $key.' is not a string');

        return $value;
    }
}
