<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Koh\Poppy\Jose\Base64Url;
use Koh\Poppy\Server\NoRateLimit;
use Koh\SyliusPoppy\Controller\AuthorizeController;
use Koh\SyliusPoppy\Controller\ConnectionsController;
use Koh\SyliusPoppy\Controller\DeviceController;
use Koh\SyliusPoppy\Controller\ShopPages;
use Koh\SyliusPoppy\Tests\Support\PersonalAgent;
use Koh\SyliusPoppy\Tests\Support\ShopKit;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/** The pages a customer sees: Direct Sign-In consent, Device Sign-In, connected agents. */
final class ShopPagesTest extends TestCase
{
    private ShopKit $kit;

    private PersonalAgent $agent;

    /** @var list<array{string, array<string, mixed>}> */
    private array $rendered = [];

    private ?ShopUserInterface $customer = null;

    protected function setUp(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getName')->willReturn('Example Shop');
        $this->kit = new ShopKit($channel);
        $this->agent = new PersonalAgent();
        $this->agent->publish($this->kit->http);
    }

    public function testDirectSignInAsksTheCustomerToSignInThenForConsent(): void
    {
        $controller = new AuthorizeController($this->kit->companies, $this->pages());
        $session = new Session(new MockArraySessionStorage());
        $verifier = Base64Url::encode(random_bytes(32));

        $start = $this->request('GET', '/poppy/authorize', $session, query: [
            'response_type' => 'code',
            'client_id' => $this->agent->clientId,
            'redirect_uri' => $this->agent->redirectUri,
            'scope' => 'poppy:read poppy:write',
            'state' => 'st',
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
        $toConsent = $controller->start($start);
        self::assertInstanceOf(RedirectResponse::class, $toConsent);
        self::assertStringStartsWith('/route/koh_poppy_authorize_consent?request=', $toConsent->getTargetUrl());
        parse_str((string) parse_url($toConsent->getTargetUrl(), \PHP_URL_QUERY), $query);
        $id = \is_string($query['request'] ?? null) ? $query['request'] : '';

        // Not signed in: to the shop's login, and back here afterwards.
        $consent = $this->request('GET', '/poppy/authorize/consent', $session, query: ['request' => $id]);
        $login = $controller->consent($consent);
        self::assertInstanceOf(RedirectResponse::class, $login);
        self::assertSame('/route/sylius_shop_login', $login->getTargetUrl());
        self::assertSame($consent->getUri(), $session->get('_security.shop.target_path'));

        // Signed in: the consent page, framed by nobody.
        $this->customer = $this->customer();
        $page = $controller->consent($consent);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('DENY', $page->headers->get('X-Frame-Options'));
        self::assertSame('@KohSyliusPoppy/shop/consent.html.twig', $this->rendered[0][0]);
        self::assertSame(['poppy:read', 'poppy:write'], $this->rendered[0][1]['scopes']);
        self::assertSame('agent.example', $this->rendered[0][1]['redirect_host']);

        // Without a valid CSRF token nothing happens.
        $forged = $this->request('POST', '/poppy/authorize/consent', $session, query: ['request' => $id], post: ['approve' => '1', 'scopes' => ['poppy:read'], '_csrf_token' => 'wrong']);
        self::assertSame(400, $controller->consent($forged)->getStatusCode());

        // The customer approves one of the two scopes.
        $approve = $this->request('POST', '/poppy/authorize/consent', $session, query: ['request' => $id], post: ['approve' => '1', 'scopes' => ['poppy:read'], '_csrf_token' => 'valid']);
        $back = $controller->consent($approve);
        self::assertInstanceOf(RedirectResponse::class, $back);
        self::assertStringStartsWith($this->agent->redirectUri.'?code=', $back->getTargetUrl());
        self::assertStringContainsString('&state=st&iss='.rawurlencode(ShopKit::ISSUER), $back->getTargetUrl());

        // The request is used up.
        self::assertSame(400, $controller->consent($approve)->getStatusCode());
    }

    public function testAnUnknownAgentIsShownAnErrorWithoutRedirect(): void
    {
        $controller = new AuthorizeController($this->kit->companies, $this->pages());
        $response = $controller->start($this->request('GET', '/poppy/authorize', new Session(new MockArraySessionStorage()), query: [
            'client_id' => 'https://unknown.example/agent.json',
            'redirect_uri' => 'https://evil.example/cb',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertNotInstanceOf(RedirectResponse::class, $response);
    }

    public function testDeviceSignInLooksUpTheCodeAndRecordsTheDecision(): void
    {
        $server = $this->kit->companies->resolve()->server;
        self::assertNotNull($server);
        $now = $this->kit->clock->now();
        $start = $server->deviceAuthorizationEndpoint()->handle((new \Nyholm\Psr7\ServerRequest('POST', ShopKit::ISSUER.'/device_authorization'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new \Nyholm\Psr7\Factory\Psr17Factory())->createStream(http_build_query(['scope' => 'poppy:read'] + $this->agent->clientAuthentication(ShopKit::ISSUER, $now), '', '&', \PHP_QUERY_RFC3986))));
        $device = json_decode((string) $start->getBody(), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($device);
        $userCode = \is_string($device['user_code'] ?? null) ? $device['user_code'] : '';

        $controller = new DeviceController($this->kit->companies, $this->pages(), new NoRateLimit());
        $session = new Session(new MockArraySessionStorage());

        self::assertInstanceOf(RedirectResponse::class, $controller($this->request('GET', '/poppy/device', $session, query: ['user_code' => $userCode])), 'sign in first');

        $this->customer = $this->customer();
        self::assertSame(404, $controller($this->request('GET', '/poppy/device', $session, query: ['user_code' => 'BCDF-GHJK']))->getStatusCode());
        self::assertSame(200, $controller($this->request('GET', '/poppy/device', $session, query: ['user_code' => strtolower($userCode)]))->getStatusCode());
        self::assertSame('@KohSyliusPoppy/shop/device_consent.html.twig', end($this->rendered)[0] ?? null, 'opening the link only shows the request');

        $decided = $controller($this->request('POST', '/poppy/device', $session, post: ['user_code' => $userCode, 'decision' => 'approve', 'scopes' => ['poppy:read'], '_csrf_token' => 'valid']));
        self::assertSame(200, $decided->getStatusCode());
        self::assertSame('approved', end($this->rendered)[1]['outcome'] ?? null);

        $stored = $this->kit->storage->findDeviceAuthorizationByUserCode($userCode);
        self::assertSame('approved', $stored?->status->value);
        self::assertSame('42', $stored->accountId);
    }

    public function testConnectionsListAndDisconnect(): void
    {
        $server = $this->kit->companies->resolve()->server;
        self::assertNotNull($server);
        $sessionModel = new \Koh\Poppy\Server\Model\Session('ses_1', $this->agent->clientId, 'usr_1', $this->kit->clock->now(), $this->kit->clock->now()->modify('+1 day'));
        $this->kit->storage->saveSession($sessionModel);
        $token = new \Koh\Poppy\Server\Model\AccountToken('act_1', hash('sha256', 'pat_x'), $this->agent->clientId, 'usr_1', '42', ['poppy:read'], $this->kit->clock->now());
        $this->kit->storage->saveAccountToken($token);
        $this->kit->storage->saveSession($sessionModel->signIn($token, ['poppy:read']));

        $this->customer = $this->customer();
        $controller = new ConnectionsController($this->kit->companies, $this->pages());
        $session = new Session(new MockArraySessionStorage());

        self::assertSame(200, $controller($this->request('GET', '/poppy/account/agents', $session))->getStatusCode());
        $connections = end($this->rendered)[1]['connections'] ?? [];
        self::assertIsArray($connections);
        self::assertCount(1, $connections);
        self::assertSame('agent.example', $connections[0]['host'] ?? null);

        $done = $controller($this->request('POST', '/poppy/account/agents', $session, post: ['client_id' => $this->agent->clientId, '_csrf_token' => 'valid']));
        self::assertInstanceOf(RedirectResponse::class, $done);
        self::assertNotNull($this->kit->storage->findAccountToken('act_1')?->revokedAt);
        self::assertFalse($this->kit->storage->findSession('ses_1')?->isSignedIn() ?? true, 'its Sessions are signed out');
    }

    private function pages(): ShopPages
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturnCallback(fn (): ?ShopUserInterface => $this->customer);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context): string {
            $this->rendered[] = [$template, $context];

            return $template;
        });

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => 'valid' === $token->getValue());

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $parameters = []): string {
            unset($parameters['_locale']);

            return '/route/'.$route.([] === $parameters ? '' : '?'.http_build_query($parameters));
        });

        $locales = $this->createStub(LocaleContextInterface::class);
        $locales->method('getLocaleCode')->willReturn('de_DE');

        return new ShopPages($security, $twig, $csrf, $urls, $locales);
    }

    private function customer(): ShopUserInterface
    {
        $customer = $this->createStub(ShopUserInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('customer@shop.example');

        return $customer;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    private function request(string $method, string $path, Session $session, array $query = [], array $post = []): Request
    {
        $request = Request::create(ShopKit::BASE.$path.([] === $query ? '' : '?'.http_build_query($query)), $method, $post);
        $request->setSession($session);

        return $request;
    }
}
