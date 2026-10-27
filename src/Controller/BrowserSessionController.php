<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\Poppy\Exception\PoppyError;
use Koh\Poppy\Server\BrowserCookie;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST {web.browser_session_endpoint}` (Poppy 0.2, section 5): a Personal
 * Agent's browser posts a signed browser assertion and gets the shop's own
 * cookie for that Session, then a 303 to the page it asked for.
 *
 * The POST comes from a page the agent built, cross-site and without a CSRF
 * token — the assertion is the authentication, single-use and audience-bound.
 * The cookie is SameSite=Lax, not Strict, so the browser sends it on the 303.
 */
final readonly class BrowserSessionController
{
    public const COOKIE = 'koh_poppy_browser';

    public function __construct(
        private CompanyResolver $companies,
        private BrowserCookie $cookie,
        private ShopPages $pages,
    ) {}

    public function __invoke(Request $request): Response
    {
        $company = $this->companies->resolve();
        if (null === $company->server || null === $company->browserSessionEndpoint) {
            throw new NotFoundHttpException();
        }

        try {
            [$session, $returnTo] = $company->server
                ->browserSessions($company->browserSessionEndpoint, $company->domain)
                ->join($request->request->getString('assertion'));
        } catch (PoppyError $error) {
            return $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => $error->getMessage()], 400);
        }

        $response = new RedirectResponse($returnTo, Response::HTTP_SEE_OTHER);
        $response->headers->setCookie(Cookie::create(self::COOKIE)
            ->withValue($this->cookie->value($session->id))
            ->withExpires($session->expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }
}
