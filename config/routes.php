<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusPoppy\Admin\FeedbackController;
use Koh\SyliusPoppy\Controller\AuthorizeController;
use Koh\SyliusPoppy\Controller\BrowserSessionController;
use Koh\SyliusPoppy\Controller\ConnectionsController;
use Koh\SyliusPoppy\Controller\DeviceController;
use Koh\SyliusPoppy\Controller\DiscoveryController;
use Koh\SyliusPoppy\Controller\EndpointController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Three groups, and the firewall each needs is the reason for the split:
 *
 * - the documents and the machine endpoints (well-known, /poppy/token, …)
 *   keep no session — a firewall without security, see the README;
 * - the pages a customer sees (/poppy/authorize, /poppy/device,
 *   /poppy/account/agents) run in the shop firewall, where the customer signs
 *   in;
 * - the feedback list runs in the admin firewall.
 *
 * No _locale prefix: Personal Agents are given these URLs in poppy.json and
 * the issuer metadata, and they must not depend on a language.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_poppy_discovery', '/.well-known/poppy.json')
        ->controller([DiscoveryController::class, 'poppy'])
        ->methods(['GET', 'HEAD']);

    $routes->add('koh_poppy_metadata', '/.well-known/oauth-authorization-server/{issuerPath}')
        ->controller([DiscoveryController::class, 'metadata'])
        ->requirements(['issuerPath' => '.+'])
        ->methods(['GET', 'HEAD']);

    $routes->add('koh_poppy_token', '/poppy/token')
        ->controller([EndpointController::class, 'token'])
        ->methods(['POST']);

    $routes->add('koh_poppy_revoke', '/poppy/revoke')
        ->controller([EndpointController::class, 'revoke'])
        ->methods(['POST']);

    $routes->add('koh_poppy_device_authorization', '/poppy/device_authorization')
        ->controller([EndpointController::class, 'deviceAuthorization'])
        ->methods(['POST']);

    $routes->add('koh_poppy_feedback', '/poppy/feedback')
        ->controller([EndpointController::class, 'feedback'])
        ->methods(['POST']);

    $routes->add('koh_poppy_browser_session', '/poppy/browser-session')
        ->controller(BrowserSessionController::class)
        ->methods(['POST']);

    $routes->add('koh_poppy_authorize', '/poppy/authorize')
        ->controller([AuthorizeController::class, 'start'])
        ->methods(['GET']);

    $routes->add('koh_poppy_authorize_consent', '/poppy/authorize/consent')
        ->controller([AuthorizeController::class, 'consent'])
        ->methods(['GET', 'POST']);

    $routes->add('koh_poppy_device', '/poppy/device')
        ->controller(DeviceController::class)
        ->methods(['GET', 'POST']);

    $routes->add('koh_poppy_account_agents', '/poppy/account/agents')
        ->controller(ConnectionsController::class)
        ->methods(['GET', 'POST']);

    // NOTE: /admin is the default Sylius admin path; with a customised
    // SYLIUS_ADMIN_ROUTING_PATH_NAME, override this route in the application
    // so it stays behind the admin firewall.
    $routes->add('koh_poppy_admin_feedback', '/admin/poppy/feedback')
        ->controller(FeedbackController::class)
        ->methods(['GET']);
};
