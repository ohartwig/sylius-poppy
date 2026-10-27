<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The account page where a customer sees the Personal Agents connected to
 * their account and disconnects one (Poppy 0.2, 4.9): every Account Token the
 * agent holds is revoked and its Sessions continue signed out.
 */
final readonly class ConnectionsController
{
    private const CSRF_ID = 'koh_poppy_disconnect';

    public function __construct(
        private CompanyResolver $companies,
        private ShopPages $pages,
    ) {}

    public function __invoke(Request $request): Response
    {
        $server = $this->companies->resolve()->server ?? throw new NotFoundHttpException();
        $customer = $this->pages->customer();
        if (null === $customer) {
            return $this->pages->login($request);
        }

        $disconnector = $server->disconnector();
        $accountId = (string) $customer->getId();

        if ($request->isMethod('POST')) {
            if (!$this->pages->csrfValid($request, self::CSRF_ID)) {
                return $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => 'koh_poppy.error.csrf'], 400);
            }

            $disconnector->disconnect($accountId, $request->request->getString('client_id'));

            return new RedirectResponse($this->pages->url('koh_poppy_account_agents'));
        }

        $connections = [];
        foreach ($disconnector->connections($accountId) as $clientId => $tokens) {
            if ([] === $tokens) {
                continue;
            }
            $connections[] = [
                'client_id' => $clientId,
                'host' => parse_url($clientId, \PHP_URL_HOST),
                'scopes' => array_values(array_unique(array_merge(...array_map(static fn ($token): array => $token->scopes, $tokens)))),
                'since' => min(array_map(static fn ($token): \DateTimeImmutable => $token->issuedAt, $tokens)),
            ];
        }

        return $this->pages->render('@KohSyliusPoppy/shop/connections.html.twig', [
            'connections' => $connections,
            'csrf_id' => self::CSRF_ID,
            'action' => $this->pages->url('koh_poppy_account_agents'),
        ]);
    }
}
