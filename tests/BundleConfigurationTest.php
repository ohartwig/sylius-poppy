<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Koh\SyliusPoppy\Controller\DiscoveryController;
use Koh\SyliusPoppy\KohSyliusPoppyBundle;
use Koh\SyliusPoppy\Poppy\Settings;
use Koh\SyliusPoppy\Security\BrowserSessionAuthenticator;
use Koh\SyliusPoppy\Security\ShopApiAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class BundleConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);
        $config = $container->getParameter('koh_poppy.config');
        self::assertIsArray($config);
        $settings = Settings::fromConfig($config);

        self::assertTrue($settings->enabled);
        self::assertTrue($settings->server);
        self::assertNull($settings->issuer);
        self::assertSame(['poppy:read', 'poppy:write'], $settings->directScopes);
        self::assertSame(['poppy:read'], $settings->deviceScopes);
        self::assertTrue($settings->browserSession);
        self::assertTrue($settings->feedback);
        self::assertFalse($settings->shopApi, 'the Shop API needs a firewall change, so it is opt-in');
        self::assertSame('/api/v2/shop', $settings->shopApiPrefix);
        self::assertSame(3600, $settings->sessionTokenTtl);
        self::assertSame(7776000, $settings->accountTokenTtl);
        self::assertSame('koh_poppy_', $container->getParameter('koh_poppy.table_prefix'));
        self::assertSame(['limit' => 10, 'interval' => '10 minutes'], $container->getParameter('koh_poppy.rate_limits')['device_page'] ?? null);
    }

    public function testExplicitValues(): void
    {
        $config = $this->load(['koh_poppy' => [
            'issuer' => 'https://shop.example/poppy',
            'domains' => ['shop.example', 'shop.example.at'],
            'sign_in' => ['direct' => ['poppy:read'], 'device' => []],
            'shop_api' => ['enabled' => true, 'prefix' => '/api/v3/shop'],
            'clients' => ['block' => ['bad.example']],
            'account_token_ttl' => 0,
        ]])->getParameter('koh_poppy.config');
        self::assertIsArray($config);
        $settings = Settings::fromConfig($config);

        self::assertSame('https://shop.example/poppy', $settings->issuer);
        self::assertSame(['shop.example', 'shop.example.at'], $settings->domains);
        self::assertSame([], $settings->deviceScopes);
        self::assertTrue($settings->shopApi);
        self::assertSame('/api/v3/shop', $settings->shopApiPrefix);
        self::assertSame(['bad.example'], $settings->blockClients);
        self::assertSame(0, $settings->accountTokenTtl);
    }

    public function testRefusesAnOverlongSessionToken(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['koh_poppy' => ['session_token_ttl' => 86401]]);
    }

    public function testRegistersTheServicesTheApplicationReferences(): void
    {
        $container = $this->load([]);

        foreach ([DiscoveryController::class, BrowserSessionAuthenticator::class, ShopApiAuthenticator::class] as $id) {
            self::assertTrue($container->hasDefinition($id), $id);
        }
        self::assertTrue($container->getDefinition(DiscoveryController::class)->isPublic());
        self::assertSame([['connection' => 'default']], $container->getDefinition(\Koh\SyliusPoppy\Doctrine\PoppySchemaFilter::class)->getTag('doctrine.dbal.schema_filter'));
    }

    /** @param array<string, mixed> $configs */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new KohSyliusPoppyBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        $extension->load(array_values($configs), $container);

        return $container;
    }
}
