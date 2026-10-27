<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Applies a browser Session's scopes to the website: signed in with
 * `poppy:read` alone, the agent's browser may look but not change anything
 * (Poppy 0.2, section 5: "The website applies the Session's scopes").
 *
 * Runs after the firewall, so the token it reads is the one the
 * authenticator has just confirmed for this request.
 */
final readonly class BrowserScopeListener
{
    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokens->getToken();
        if ($token instanceof PoppyBrowserToken && !BrowserSessionAuthenticator::allows($event->getRequest(), $token)) {
            $event->setResponse(new Response('This Personal Agent may view the account but not change it.', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=utf-8']));
        }
    }
}
