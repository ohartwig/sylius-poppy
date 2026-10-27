<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\Poppy\Client\ClientPolicy;
use Koh\Poppy\Dpop\NonceSource;
use Koh\Poppy\Feedback\FeedbackSink;
use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\PublicAddressGuard;
use Koh\Poppy\Replay\ReplayGuard;
use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\Storage\PoppyStorage;
use Koh\Poppy\Storage\Dbal\DbalFeedbackSink;
use Koh\Poppy\Storage\Dbal\DbalReplayGuard;
use Koh\Poppy\Storage\Dbal\DbalStorage;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusPoppy\Admin\FeedbackController;
use Koh\SyliusPoppy\Agent\PoppyEndpointProvider;
use Koh\SyliusPoppy\Command\PurgeCommand;
use Koh\SyliusPoppy\Controller\AuthorizeController;
use Koh\SyliusPoppy\Controller\BrowserSessionController;
use Koh\SyliusPoppy\Controller\ConnectionsController;
use Koh\SyliusPoppy\Controller\DeviceController;
use Koh\SyliusPoppy\Controller\DiscoveryController;
use Koh\SyliusPoppy\Controller\EndpointController;
use Koh\SyliusPoppy\Controller\ShopPages;
use Koh\SyliusPoppy\DependencyInjection\ServiceFactory;
use Koh\SyliusPoppy\Http\CacheDocumentCache;
use Koh\SyliusPoppy\Http\HttpClientFactory;
use Koh\SyliusPoppy\Http\PsrBridge;
use Koh\SyliusPoppy\Menu\MenuListener;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Koh\SyliusPoppy\Poppy\Settings;
use Koh\SyliusPoppy\Security\BrowserScopeListener;
use Koh\SyliusPoppy\Security\BrowserSessionAuthenticator;
use Koh\SyliusPoppy\Security\ShopApiAuthenticator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->private();

    $services->set(Settings::class)
        ->factory([Settings::class, 'fromConfig'])
        ->args([param('koh_poppy.config')]);

    // --- Storage: the shop's own database, through DBAL ---------------------
    $services->set(DbalStorage::class)
        ->args([service('doctrine.dbal.default_connection'), param('koh_poppy.table_prefix')]);
    $services->alias(PoppyStorage::class, DbalStorage::class);

    $services->set(DbalReplayGuard::class)
        ->args([service('doctrine.dbal.default_connection'), service(ClockInterface::class), param('koh_poppy.table_prefix')]);
    $services->alias(ReplayGuard::class, DbalReplayGuard::class);

    $services->set(DbalFeedbackSink::class)
        ->args([service('doctrine.dbal.default_connection'), param('koh_poppy.table_prefix')]);
    $services->alias(FeedbackSink::class, DbalFeedbackSink::class);

    // --- Ports -----------------------------------------------------------------
    $services->set('koh_poppy.psr17_factory', Psr17Factory::class);

    $services->set('koh_poppy.http_client')
        ->class(Psr\Http\Client\ClientInterface::class)
        ->factory([HttpClientFactory::class, 'create']);

    $services->set(CacheDocumentCache::class)->args([service('cache.app')]);

    $services->set(JsonFetcher::class)
        ->args([
            service('koh_poppy.http_client'),
            service('koh_poppy.psr17_factory'),
            service(PublicAddressGuard::class),
            service(CacheDocumentCache::class),
        ]);
    $services->set(PublicAddressGuard::class);

    $services->set(ClientPolicy::class)
        ->factory([ServiceFactory::class, 'clientPolicy'])
        ->args([service(Settings::class)]);

    $services->set(RateLimiter::class)
        ->factory([ServiceFactory::class, 'rateLimiter'])
        ->args([param('koh_poppy.rate_limits'), service('cache.app')]);

    $services->set(NonceSource::class)
        ->factory([ServiceFactory::class, 'nonceSource'])
        ->args([param('kernel.secret'), service(ClockInterface::class)]);

    $services->set(BrowserCookie::class)
        ->factory([ServiceFactory::class, 'browserCookie'])
        ->args([param('kernel.secret')]);

    $services->set(PsrBridge::class)->args([service('koh_poppy.psr17_factory')]);

    $services->set(CompanyResolver::class)
        ->args([
            service(AgentContextFactoryInterface::class),
            service(Settings::class),
            service(PoppyStorage::class),
            service(ReplayGuard::class),
            service(ClientPolicy::class),
            service(JsonFetcher::class),
            service(ClockInterface::class),
            service('koh_poppy.psr17_factory'),
            service('koh_poppy.psr17_factory'),
            service(RateLimiter::class),
            service(NonceSource::class),
        ]);

    // --- Controllers -----------------------------------------------------------
    $services->set(ShopPages::class)
        ->args([
            service('security.helper'),
            service('twig'),
            service('security.csrf.token_manager'),
            service('router'),
            service('sylius.context.locale'),
            'shop',
        ]);

    $services->set(DiscoveryController::class)
        ->args([service(CompanyResolver::class), service(AgentJsonResponder::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(EndpointController::class)
        ->args([service(CompanyResolver::class), service(PsrBridge::class), service(FeedbackSink::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(AuthorizeController::class)
        ->args([service(CompanyResolver::class), service(ShopPages::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(DeviceController::class)
        ->args([service(CompanyResolver::class), service(ShopPages::class), service(RateLimiter::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(ConnectionsController::class)
        ->args([service(CompanyResolver::class), service(ShopPages::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(BrowserSessionController::class)
        ->args([service(CompanyResolver::class), service(BrowserCookie::class), service(ShopPages::class)])
        ->public()->tag('controller.service_arguments');

    $services->set(FeedbackController::class)
        ->args([service(DbalFeedbackSink::class), service('twig')])
        ->public()->tag('controller.service_arguments');

    // --- Security: referenced by id from the application's security.yaml -------
    $services->set(BrowserSessionAuthenticator::class)
        ->args([service(CompanyResolver::class), service(BrowserCookie::class), service('sylius.repository.shop_user'), service('security.token_storage')]);

    $services->set(ShopApiAuthenticator::class)
        ->args([service(CompanyResolver::class), service(PsrBridge::class), service('sylius.repository.shop_user')]);

    // After the firewall (priority 8), so it sees the token for this request.
    $services->set(BrowserScopeListener::class)
        ->args([service('security.token_storage')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 7]);

    // --- Shop and admin integration ---------------------------------------------
    $services->set(MenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.shop.account', 'method' => 'account'])
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main', 'method' => 'admin']);

    $services->set(PoppyEndpointProvider::class)->tag('koh_agent.endpoint');

    $services->set(PurgeCommand::class)
        ->args([service(PoppyStorage::class), service(DbalReplayGuard::class), service(ClockInterface::class)])
        ->tag('console.command');
};
