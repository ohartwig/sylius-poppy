<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\Poppy\Feedback\FeedbackSink;
use Koh\SyliusPoppy\Http\PsrBridge;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The machine-facing endpoints: token, revocation, device authorization and
 * feedback. They answer JSON to Personal Agents, keep no session and set no
 * cookie, which is why their paths sit in a firewall without security (see
 * the README).
 */
final readonly class EndpointController
{
    public function __construct(
        private CompanyResolver $companies,
        private PsrBridge $bridge,
        private FeedbackSink $feedback,
    ) {}

    public function token(Request $request): Response
    {
        return $this->bridge->response($this->server()->tokenEndpoint()->handle($this->bridge->request($request)));
    }

    public function revoke(Request $request): Response
    {
        return $this->bridge->response($this->server()->revocationEndpoint()->handle($this->bridge->request($request)));
    }

    public function deviceAuthorization(Request $request): Response
    {
        return $this->bridge->response($this->server()->deviceAuthorizationEndpoint()->handle($this->bridge->request($request)));
    }

    public function feedback(Request $request): Response
    {
        $company = $this->companies->resolve();
        if (null === $company->server || null === $company->feedbackEndpoint) {
            throw new NotFoundHttpException();
        }

        return $this->bridge->response(
            $company->server->feedbackEndpoint($company->feedbackEndpoint, $this->feedback)->handle($this->bridge->request($request)),
        );
    }

    private function server(): \Koh\Poppy\PoppyServer
    {
        $server = $this->companies->settings()->enabled ? $this->companies->resolve()->server : null;

        return $server ?? throw new NotFoundHttpException();
    }
}
