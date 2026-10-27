<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Koh\Poppy\Jose\Base64Url;
use Koh\Poppy\Server\BrowserCookie;
use Koh\SyliusPoppy\Controller\BrowserSessionController;
use Koh\SyliusPoppy\Controller\EndpointController;
use Koh\SyliusPoppy\Controller\ShopPages;
use Koh\SyliusPoppy\Http\PsrBridge;
use Koh\SyliusPoppy\Security\BrowserSessionAuthenticator;
use Koh\SyliusPoppy\Security\PoppyBrowserToken;
use Koh\SyliusPoppy\Security\ShopApiAuthenticator;
use Koh\SyliusPoppy\Security\SignedOutSession;
use Koh\SyliusPoppy\Tests\Support\PersonalAgent;
use Koh\SyliusPoppy\Tests\Support\ShopKit;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Twig\Environment;

/**
 * The protocol through the Symfony layer: token requests as HttpFoundation
 * requests, a signed-in Session on the Shop API, and a browser joining the
 * Session and being signed in and out with it.
 */
final class ProtocolFlowTest extends TestCase
{
    private ShopKit $kit;

    private PersonalAgent $agent;

    private EndpointController $endpoints;

    protected function setUp(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getName')->willReturn('Example Shop');
        $this->kit = new ShopKit($channel);
        $this->agent = new PersonalAgent();
        $this->agent->publish($this->kit->http);
        $this->endpoints = new EndpointController($this->kit->companies, new PsrBridge(), $this->kit->feedback);
    }

    public function testASessionStartsThroughTheTokenEndpoint(): void
    {
        $body = $this->startSession('usr_1');

        self::assertSame('DPoP', $body['token_type']);
        self::assertFalse($body['signed_in']);
        self::assertNotNull($this->kit->storage->findSession(ShopKit::text($body, 'session_id')));
    }

    public function testAnInvalidTokenRequestAnswersAnOAuthError(): void
    {
        $response = $this->endpoints->token(ShopKit::form('/poppy/token', ['grant_type' => 'password']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', ShopKit::json($response)['error']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheShopApiAcceptsASignedInSessionWithinItsScopes(): void
    {
        $session = ShopKit::text($this->startSession('usr_1'), 'session_id');
        $tokens = $this->signIn($session, ['poppy:read']);
        $accessToken = ShopKit::text($tokens, 'access_token');
        $authenticator = new ShopApiAuthenticator($this->kit->companies, new PsrBridge(), $this->users());

        $read = $this->apiRequest('GET', '/api/v2/shop/orders', $accessToken);
        self::assertTrue($authenticator->supports($read));
        $passport = $authenticator->authenticate($read);
        self::assertInstanceOf(Passport::class, $passport);
        self::assertSame('customer@shop.example', $passport->getUser()->getUserIdentifier());

        $write = $this->apiRequest('POST', '/api/v2/shop/addresses', $accessToken);
        try {
            $authenticator->authenticate($write);
            self::fail('poppy:read must not write');
        } catch (\Koh\SyliusPoppy\Security\PoppyAuthenticationException $exception) {
            $response = $authenticator->onAuthenticationFailure($write, $exception);
            self::assertNotNull($response);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('insufficient_scope', (string) $response->headers->get('WWW-Authenticate'));
        }
    }

    public function testASignedOutSessionContinuesAsGuestOnTheShopApi(): void
    {
        $accessToken = ShopKit::text($this->startSession('usr_1'), 'access_token');
        $authenticator = new ShopApiAuthenticator($this->kit->companies, new PsrBridge(), $this->users());
        $request = $this->apiRequest('GET', '/api/v2/shop/products', $accessToken);

        try {
            $authenticator->authenticate($request);
            self::fail('a signed-out Session authenticates nobody');
        } catch (SignedOutSession $exception) {
            self::assertNull($authenticator->onAuthenticationFailure($request, $exception), 'the request goes on as a guest');
            self::assertInstanceOf(\Koh\Poppy\Resource\SessionContext::class, $request->attributes->get(ShopApiAuthenticator::SESSION_ATTRIBUTE));
        }
    }

    public function testABrokenProofOnTheShopApiIsRefusedWithAChallenge(): void
    {
        $accessToken = ShopKit::text($this->startSession('usr_1'), 'access_token');
        $authenticator = new ShopApiAuthenticator($this->kit->companies, new PsrBridge(), $this->users());
        $request = $this->apiRequest('GET', '/api/v2/shop/products', $accessToken);
        $request->headers->set('DPoP', $this->agent->dpopProof('GET', ShopKit::BASE.'/api/v2/shop/other', $this->kit->clock->now(), $accessToken));

        try {
            $authenticator->authenticate($request);
            self::fail('expected a refusal');
        } catch (\Koh\SyliusPoppy\Security\PoppyAuthenticationException $exception) {
            $response = $authenticator->onAuthenticationFailure($request, $exception);
            self::assertSame(401, $response?->getStatusCode());
            self::assertStringStartsWith('DPoP error="invalid_dpop_proof"', (string) $response->headers->get('WWW-Authenticate'));
        }
    }

    public function testAJwtBearerRequestIsLeftToTheJwtLogin(): void
    {
        $authenticator = new ShopApiAuthenticator($this->kit->companies, new PsrBridge(), $this->users());
        $request = Request::create(ShopKit::BASE.'/api/v2/shop/orders');
        $request->headers->set('Authorization', 'Bearer eyJ.jwt.token');

        self::assertFalse($authenticator->supports($request));
    }

    public function testABrowserJoinsTheSessionAndIsSignedInAndOutWithIt(): void
    {
        $session = ShopKit::text($this->startSession('usr_1'), 'session_id');
        $cookies = new BrowserCookie(str_repeat('c', 32));
        $controller = new BrowserSessionController($this->kit->companies, $cookies, $this->pages());

        $join = ShopKit::form('/poppy/browser-session', [
            'assertion' => $this->agent->browserAssertion(ShopKit::ISSUER.'/browser-session', 'usr_1', $session, 'https://shop.example/de_DE/account', $this->kit->clock->now()),
        ]);
        $response = $controller($join);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example/de_DE/account', $response->headers->get('Location'));
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame($session, $cookies->sessionId((string) $cookie->getValue()));

        $tokens = new TokenStorage();
        $authenticator = new BrowserSessionAuthenticator($this->kit->companies, $cookies, $this->users(), $tokens);
        $page = Request::create(ShopKit::BASE.'/de_DE/account');
        $page->cookies->set(BrowserSessionController::COOKIE, (string) $cookie->getValue());

        // Signed out: the browser is a visitor, whatever login it held before.
        self::assertTrue($authenticator->supports($page));
        try {
            $authenticator->authenticate($page);
            self::fail('a signed-out Session signs nobody in');
        } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $exception) {
            self::assertNull($authenticator->onAuthenticationFailure($page, $exception));
        }

        // Signed in: the browser is the customer, within the Session's scopes.
        $accountToken = ShopKit::text($this->signIn($session, ['poppy:read']), 'refresh_token');
        $passport = $authenticator->authenticate($page);
        $token = $authenticator->createToken($passport, 'shop');
        self::assertInstanceOf(PoppyBrowserToken::class, $token);
        self::assertSame(['poppy:read'], $token->getScopes());
        self::assertSame($session, $token->getSessionId());
        $tokens->setToken($token);
        self::assertFalse($authenticator->supports($page), 'nothing changed, nothing to redo');
        self::assertFalse(BrowserSessionAuthenticator::allows(Request::create(ShopKit::BASE.'/de_DE/account/profile/edit', 'POST'), $token), 'poppy:read may not change anything');
        self::assertTrue(BrowserSessionAuthenticator::allows(Request::create(ShopKit::BASE.'/de_DE/account'), $token));

        // The token survives in the session, but not the sign-out.
        $record = $this->kit->storage->findAccountTokenByHash(hash('sha256', $accountToken));
        self::assertNotNull($record);
        $this->kit->companies->resolve()->server?->disconnector()->revoke($record);
        self::assertTrue($authenticator->supports($page), 'the Session changed');
        try {
            $authenticator->authenticate($page);
            self::fail('signed out again');
        } catch (\Symfony\Component\Security\Core\Exception\AuthenticationException $exception) {
            $authenticator->onAuthenticationFailure($page, $exception);
            self::assertNull($tokens->getToken());
        }

        // And a Poppy login without the cookie is removed as well.
        $tokens->setToken($token);
        $withoutCookie = Request::create(ShopKit::BASE.'/de_DE/account');
        self::assertTrue($authenticator->supports($withoutCookie));
    }

    public function testAForgedCookieSignsNobodyIn(): void
    {
        $authenticator = new BrowserSessionAuthenticator($this->kit->companies, new BrowserCookie(str_repeat('c', 32)), $this->users(), new TokenStorage());
        $page = Request::create(ShopKit::BASE.'/de_DE/account');
        $page->cookies->set(BrowserSessionController::COOKIE, 'ses_x.forged');

        $this->expectException(\Symfony\Component\Security\Core\Exception\AuthenticationException::class);
        $authenticator->authenticate($page);
    }

    public function testFeedbackIsStored(): void
    {
        $accessToken = ShopKit::text($this->startSession('usr_1'), 'access_token');
        $request = Request::create(ShopKit::BASE.'/poppy/feedback', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"kind":"missing","task":"Gift card","description":"No field for it."}');
        $request->headers->set('Authorization', 'DPoP '.$accessToken);
        $request->headers->set('DPoP', $this->agent->dpopProof('POST', ShopKit::BASE.'/poppy/feedback', $this->kit->clock->now(), $accessToken));

        self::assertSame(202, $this->endpoints->feedback($request)->getStatusCode());
        self::assertSame('Gift card', $this->kit->feedback->latest()[0]['task'] ?? null);
    }

    /** @return array<string, mixed> */
    private function startSession(string $userId): array
    {
        $now = $this->kit->clock->now();
        $response = $this->endpoints->token(ShopKit::form('/poppy/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->agent->sessionAssertion(ShopKit::ISSUER, $userId, $now),
        ] + $this->agent->clientAuthentication(ShopKit::ISSUER, $now), $this->agent->dpopProof('POST', ShopKit::ISSUER.'/token', $now)));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return ShopKit::json($response);
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, mixed>
     */
    private function signIn(string $sessionId, array $scopes): array
    {
        $signIn = $this->kit->companies->resolve()->server?->directSignIn();
        self::assertNotNull($signIn);
        $verifier = Base64Url::encode(random_bytes(32));
        $redirect = $signIn->approve($signIn->validate([
            'response_type' => 'code',
            'client_id' => $this->agent->clientId,
            'redirect_uri' => $this->agent->redirectUri,
            'scope' => implode(' ', $scopes),
            'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]), '42', $scopes);
        parse_str((string) parse_url($redirect, \PHP_URL_QUERY), $query);

        $now = $this->kit->clock->now();
        $response = $this->endpoints->token(ShopKit::form('/poppy/token', [
            'grant_type' => 'authorization_code',
            'code' => \is_string($query['code'] ?? null) ? $query['code'] : '',
            'redirect_uri' => $this->agent->redirectUri,
            'code_verifier' => $verifier,
            'session_id' => $sessionId,
        ] + $this->agent->clientAuthentication(ShopKit::ISSUER, $now), $this->agent->dpopProof('POST', ShopKit::ISSUER.'/token', $now)));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return ShopKit::json($response);
    }

    private function apiRequest(string $method, string $path, string $accessToken): Request
    {
        $request = Request::create(ShopKit::BASE.$path, $method);
        $request->headers->set('Authorization', 'DPoP '.$accessToken);
        $request->headers->set('DPoP', $this->agent->dpopProof($method, ShopKit::BASE.$path, $this->kit->clock->now(), $accessToken));

        return $request;
    }

    /** @return UserRepositoryInterface<ShopUserInterface> */
    private function users(): UserRepositoryInterface
    {
        $user = $this->createStub(ShopUserInterface::class);
        $user->method('getId')->willReturn(42);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getUserIdentifier')->willReturn('customer@shop.example');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('find')->willReturnCallback(static fn (mixed $id): ?ShopUserInterface => 42 === $id ? $user : null);

        return $users;
    }

    private function pages(): ShopPages
    {
        return new ShopPages(
            $this->createStub(Security::class),
            $this->createStub(Environment::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LocaleContextInterface::class),
        );
    }
}
