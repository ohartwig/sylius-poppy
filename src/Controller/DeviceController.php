<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\Poppy\Server\RateLimiter;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Device Sign-In (Poppy 0.2, 4.6): the customer types the code their Personal
 * Agent shows, or arrives with it in the link, signs in, and decides.
 *
 * Opening the link approves nothing (4.6): the code only selects the request,
 * the decision is a POST with a CSRF token. Looking codes up is rate-limited
 * per client address, because eight letters from a twenty-letter alphabet can
 * be searched by someone who is allowed to guess often enough.
 */
final readonly class DeviceController
{
    private const CSRF_ID = 'koh_poppy_device';

    public function __construct(
        private CompanyResolver $companies,
        private ShopPages $pages,
        private RateLimiter $rateLimiter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $company = $this->companies->resolve();
        $verification = $company->server?->deviceVerification() ?? throw new NotFoundHttpException();
        if (!$company->server->configuration->hasDeviceSignIn()) {
            throw new NotFoundHttpException();
        }

        $customer = $this->pages->customer();
        if (null === $customer) {
            return $this->pages->login($request);
        }

        $code = $request->isMethod('POST') ? $request->request->getString('user_code') : $request->query->getString('user_code');
        $view = ['company' => $company->document->toArray()['organization'], 'csrf_id' => self::CSRF_ID, 'action' => $this->pages->url('koh_poppy_device'), 'code' => $code];

        if ('' === $code) {
            return $this->pages->render('@KohSyliusPoppy/shop/device.html.twig', $view);
        }

        $retryAfter = $this->rateLimiter->hit('device_page', (string) $request->getClientIp());
        if (null !== $retryAfter) {
            return $this->pages->render('@KohSyliusPoppy/shop/device.html.twig', $view + ['error' => 'koh_poppy.device.too_many'], 429);
        }

        $found = $verification->find($code);
        if (null === $found) {
            return $this->pages->render('@KohSyliusPoppy/shop/device.html.twig', $view + ['error' => 'koh_poppy.device.unknown'], 404);
        }
        [$authorization, $agent] = $found;

        if ($request->isMethod('POST') && $request->request->has('decision')) {
            if (!$this->pages->csrfValid($request, self::CSRF_ID)) {
                return $this->pages->render('@KohSyliusPoppy/shop/error.html.twig', ['message' => 'koh_poppy.error.csrf'], 400);
            }

            if ('approve' === $request->request->getString('decision')) {
                $verification->approve($authorization, (string) $customer->getId(), array_values(array_filter($request->request->all('scopes'), 'is_string')));
                $outcome = 'approved';
            } else {
                $verification->deny($authorization);
                $outcome = 'denied';
            }

            return $this->pages->render('@KohSyliusPoppy/shop/device_done.html.twig', $view + ['agent' => $agent, 'outcome' => $outcome]);
        }

        return $this->pages->render('@KohSyliusPoppy/shop/device_consent.html.twig', $view + [
            'agent' => $agent,
            'scopes' => $authorization->requestedScopes,
            'customer' => $customer,
        ]);
    }
}
