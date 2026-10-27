<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Poppy;

/**
 * The bundle configuration as typed values. Built once from the processed
 * configuration array; the tree in KohSyliusPoppyBundle is where defaults and
 * meanings are documented.
 */
final readonly class Settings
{
    /**
     * @param list<string> $domains
     * @param list<string> $directScopes
     * @param list<string> $deviceScopes
     * @param list<string> $allowClients
     * @param list<string> $blockClients
     */
    public function __construct(
        public bool $enabled = true,
        public bool $server = true,
        public ?string $organizationName = null,
        public ?string $issuer = null,
        public array $domains = [],
        public array $directScopes = ['poppy:read', 'poppy:write'],
        public array $deviceScopes = ['poppy:read'],
        public bool $browserSession = true,
        public bool $feedback = true,
        public bool $shopApi = false,
        public string $shopApiPrefix = '/api/v2/shop',
        public string $shopApiOpenApiPath = '/api/v2/docs.jsonopenapi',
        public string $shopApiDescription = 'Catalogue, cart, checkout, orders and addresses (Sylius Shop API)',
        public int $sessionTokenTtl = 3600,
        public int $sessionLifetime = 2592000,
        public int $accountTokenTtl = 7776000,
        public array $allowClients = [],
        public array $blockClients = [],
        public bool $dpopNonces = false,
    ) {}

    /** @param array<mixed> $config */
    public static function fromConfig(array $config): self
    {
        $signIn = self::map($config, 'sign_in');
        $shopApi = self::map($config, 'shop_api');
        $clients = self::map($config, 'clients');

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            server: (bool) ($config['server'] ?? true),
            organizationName: self::nullableString($config, 'organization_name'),
            issuer: self::nullableString($config, 'issuer'),
            domains: self::strings($config, 'domains'),
            directScopes: self::strings($signIn, 'direct', ['poppy:read', 'poppy:write']),
            deviceScopes: self::strings($signIn, 'device', ['poppy:read']),
            browserSession: (bool) ($config['browser_session'] ?? true),
            feedback: (bool) ($config['feedback'] ?? true),
            shopApi: (bool) ($shopApi['enabled'] ?? false),
            shopApiPrefix: self::nullableString($shopApi, 'prefix') ?? '/api/v2/shop',
            shopApiOpenApiPath: self::nullableString($shopApi, 'openapi_path') ?? '/api/v2/docs.jsonopenapi',
            shopApiDescription: self::nullableString($shopApi, 'description') ?? 'Sylius Shop API',
            sessionTokenTtl: self::integer($config, 'session_token_ttl', 3600),
            sessionLifetime: self::integer($config, 'session_lifetime', 2592000),
            accountTokenTtl: self::integer($config, 'account_token_ttl', 7776000),
            allowClients: self::strings($clients, 'allow'),
            blockClients: self::strings($clients, 'block'),
            dpopNonces: (bool) ($config['dpop_nonces'] ?? false),
        );
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function map(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /** @param array<mixed> $config */
    private static function nullableString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** @param array<mixed> $config */
    private static function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<mixed>  $config
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function strings(array $config, string $key, array $default = []): array
    {
        $value = $config[$key] ?? $default;
        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
