<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Koh\Poppy\Exception\PoppyError;
use Koh\Poppy\Resource\Challenge;
use Koh\Poppy\Resource\SessionContext;
use Koh\Poppy\Server\Scopes;
use Koh\SyliusPoppy\Http\PsrBridge;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * DPoP Session Tokens on the Sylius Shop API (Poppy 0.2, section 6).
 *
 * A signed-in token authenticates as the customer, within its scopes:
 * `poppy:read` to read, `poppy:write` to change. A signed-out token is valid
 * but authenticates nobody — the request goes on as a guest, which is what the
 * Shop API offers guests (catalogue, guest cart, checkout), and the Session is
 * left on the request for whatever wants to know which agent asked. An invalid
 * token or proof is refused with the challenge the specification prescribes.
 *
 * Bearer tokens are not looked at here: the Shop API is not an MCP server, so
 * Poppy never issues one for it, and `Bearer` stays the JWT login's.
 *
 * Register it on the api_shop firewall, see the README.
 */
final class ShopApiAuthenticator extends AbstractAuthenticator
{
    public const SESSION_ATTRIBUTE = '_poppy_session';

    /** @param UserRepositoryInterface<ShopUserInterface> $users */
    public function __construct(
        private readonly CompanyResolver $companies,
        private readonly PsrBridge $bridge,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization'), 'DPoP ');
    }

    public function authenticate(Request $request): Passport
    {
        $company = $this->companies->resolve();
        if (null === $company->server || null === $company->shopApiPrefix || !str_starts_with($request->getPathInfo(), $company->shopApiPrefix)) {
            throw new PoppyAuthenticationException(PoppyError::invalidToken('Session Tokens are not accepted here.'));
        }

        try {
            $session = $company->server->resourceGuard()->authenticate($this->bridge->request($request), $company->url($request->getPathInfo()));
            $request->attributes->set(self::SESSION_ATTRIBUTE, $session);
            $accountId = $this->requiredAccount($request, $session);
        } catch (PoppyError $error) {
            throw new PoppyAuthenticationException($error);
        }

        if (null === $accountId) {
            throw new SignedOutSession();
        }

        $user = ctype_digit($accountId) ? $this->users->find((int) $accountId) : null;
        if (!$user instanceof ShopUserInterface || !$user->isEnabled()) {
            throw new PoppyAuthenticationException(PoppyError::invalidToken('The account no longer exists.'));
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): ShopUserInterface => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof SignedOutSession) {
            return null;
        }

        $error = $exception instanceof PoppyAuthenticationException ? $exception->error : PoppyError::invalidToken();
        $algorithms = $this->companies->resolve()->server?->dpopAlgorithms() ?? ['ES256'];

        return new JsonResponse($error->body(), $error->status, [
            'WWW-Authenticate' => Challenge::header($error, $algorithms),
            'Cache-Control' => 'no-store',
        ] + $error->headers);
    }

    /**
     * The account a signed-in request acts for, after the scope check; null
     * for a signed-out Session.
     *
     * @throws PoppyError insufficient_scope
     */
    private function requiredAccount(Request $request, SessionContext $session): ?string
    {
        if (!$session->isSignedIn()) {
            return null;
        }

        return $request->isMethodSafe()
            ? $session->requireScopes(Scopes::READ)
            : $session->requireScopes(Scopes::WRITE);
    }
}
