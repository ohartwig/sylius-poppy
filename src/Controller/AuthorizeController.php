<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\Poppy\Server\SignIn\AuthorizationError;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Direct Sign-In (Poppy 0.2, 4.5): the customer signs in on the shop's own
 * login page and decides on a consent page what the Personal Agent may do.
 *
 * The validated request is kept in the server-side session, keyed by a random
 * id, and never travels through the browser: a redirect URI the customer's
 * browser could edit is a redirect URI an attacker could edit. The id in the
 * URL only finds the request again; it is checked against the session it was
 * stored in, so another browser cannot use it.
 */
final readonly class AuthorizeController
{
    private const SESSION_KEY = 'koh_poppy.authorize.';

    private const CSRF_ID = 'koh_poppy_authorize';

    public function __construct(
        private CompanyResolver $companies,
        private ShopPages $pages,
    ) {}

    public function start(Request $request): Response
    {
        $signIn = $this->companies->resolve()->server?->directSignIn() ?? throw new NotFoundHttpException();

        try {
            $authorization = $signIn->validate($request->query->all());
        } catch (AuthorizationError $error) {
            return $error->isRedirect()
                ? new RedirectResponse((string) $error->redirectUrl)
                : $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => $error->getMessage()], 400);
        }

        $id = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_KEY.$id, $authorization->toArray());

        return new RedirectResponse($this->pages->url('koh_poppy_authorize_consent', ['request' => $id]));
    }

    public function consent(Request $request): Response
    {
        $company = $this->companies->resolve();
        $signIn = $company->server?->directSignIn() ?? throw new NotFoundHttpException();

        $id = $request->query->getString('request');
        $stored = '' !== $id ? $request->getSession()->get(self::SESSION_KEY.$id) : null;
        if (!\is_array($stored)) {
            return $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => 'koh_poppy.error.request_expired'], 400);
        }

        $customer = $this->pages->customer();
        if (null === $customer) {
            return $this->pages->login($request);
        }

        try {
            $authorization = $signIn->restore($stored);
        } catch (AuthorizationError $error) {
            $request->getSession()->remove(self::SESSION_KEY.$id);

            return $error->isRedirect()
                ? new RedirectResponse((string) $error->redirectUrl)
                : $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => $error->getMessage()], 400);
        }

        if ($request->isMethod('POST')) {
            if (!$this->pages->csrfValid($request, self::CSRF_ID)) {
                return $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => 'koh_poppy.error.csrf'], 400);
            }

            $request->getSession()->remove(self::SESSION_KEY.$id);
            $approved = $request->request->getBoolean('approve')
                ? array_values(array_filter($request->request->all('scopes'), 'is_string'))
                : [];

            return new RedirectResponse([] === $approved
                ? $signIn->deny($authorization)
                : $signIn->approve($authorization, (string) $customer->getId(), $approved));
        }

        return $this->pages->render('@KohSyliusPoppy/shop/consent.html.twig', [
            'agent' => $authorization->client,
            'scopes' => $authorization->scopes,
            'company' => $company->document->toArray()['organization'],
            'customer' => $customer,
            'action' => $this->pages->url('koh_poppy_authorize_consent', ['request' => $id]),
            'csrf_id' => self::CSRF_ID,
            'redirect_host' => parse_url($authorization->redirectUri, \PHP_URL_HOST),
        ]);
    }
}
