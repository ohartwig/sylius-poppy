<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Koh\Poppy\Server\BrowserCookie;
use Koh\Poppy\Server\Scopes;
use Koh\SyliusPoppy\Controller\BrowserSessionController;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * The shop firewall's view of a Personal Agent's browser (Poppy 0.2,
 * section 5).
 *
 * While the browser carries the Session's cookie, the shop handles it as that
 * Session: signed in as the account the Session is signed in to, signed out
 * otherwise — whatever other login the browser holds. So this authenticator
 * runs whenever the cookie is present, and also when a token it created is
 * present without the cookie, to remove it. A failure here never shows an
 * error; it leaves the browser signed out, which is what the Session is.
 *
 * Scopes apply to the website as to the APIs: a Session signed in with
 * `poppy:read` only gets 403 on every request that changes something.
 *
 * Register it on the shop firewall, see the README.
 */
final class BrowserSessionAuthenticator extends AbstractAuthenticator
{
    /** @param UserRepositoryInterface<ShopUserInterface> $users */
    public function __construct(
        private readonly CompanyResolver $companies,
        private readonly BrowserCookie $cookie,
        private readonly UserRepositoryInterface $users,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function supports(Request $request): bool
    {
        if (!$request->cookies->has(BrowserSessionController::COOKIE)) {
            return $this->tokens->getToken() instanceof PoppyBrowserToken;
        }

        // Already signed in as exactly what the Session is: nothing to do.
        $current = $this->tokens->getToken();
        if ($current instanceof PoppyBrowserToken) {
            $state = $this->state($request);

            return null === $state || $state[0] !== $current->getSessionId() || $state[1] !== $current->getUserIdentifier() || $state[2] !== $current->getScopes();
        }

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $state = $this->state($request);
        if (null === $state) {
            throw new CustomUserMessageAuthenticationException('No signed-in Poppy Session.');
        }
        [$sessionId, $identifier, $scopes, $user] = $state;

        $passport = new SelfValidatingPassport(new UserBadge($identifier, static fn (): ShopUserInterface => $user));
        $passport->setAttribute('poppy_session', $sessionId);
        $passport->setAttribute('poppy_scopes', $scopes);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $sessionId = $passport->getAttribute('poppy_session');
        $scopes = $passport->getAttribute('poppy_scopes');

        return new PoppyBrowserToken(
            $user,
            $firewallName,
            array_values($user->getRoles()),
            \is_string($sessionId) ? $sessionId : '',
            \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Signed out, ended, forged or no cookie at all: the browser is a
        // signed-out visitor, including when it held another login before.
        if ($request->cookies->has(BrowserSessionController::COOKIE) || $this->tokens->getToken() instanceof PoppyBrowserToken) {
            $this->tokens->setToken(null);
        }

        return null;
    }

    /**
     * The Session behind the cookie, if it is signed in to an account that is
     * an enabled shop user.
     *
     * @return array{string, string, list<string>, ShopUserInterface}|null
     */
    private function state(Request $request): ?array
    {
        $value = $request->cookies->get(BrowserSessionController::COOKIE);
        $sessionId = \is_string($value) ? $this->cookie->sessionId($value) : null;
        $company = null !== $sessionId ? $this->companies->resolve() : null;
        $server = $company?->server;
        if (null === $sessionId || null === $server || null === $company->browserSessionEndpoint) {
            return null;
        }

        $sessions = $server->browserSessions($company->browserSessionEndpoint, $company->domain);
        $session = $sessions->current($sessionId);
        $accountId = null !== $session ? $sessions->account($session) : null;
        if (null === $session || null === $accountId || !ctype_digit($accountId)) {
            return null;
        }

        $user = $this->users->find((int) $accountId);
        if (!$user instanceof ShopUserInterface || !$user->isEnabled()) {
            return null;
        }

        return [$session->id, $user->getUserIdentifier(), $session->scopes, $user];
    }

    /** Whether the request may change something under these scopes. */
    public static function allows(Request $request, PoppyBrowserToken $token): bool
    {
        return $request->isMethodSafe() || \in_array(Scopes::WRITE, $token->getScopes(), true);
    }
}
