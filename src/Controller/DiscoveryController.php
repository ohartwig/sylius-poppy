<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Controller;

use Koh\Poppy\Discovery\AuthorizationServerMetadata;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusPoppy\Poppy\CompanyResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/.well-known/poppy.json` and the issuer's RFC 8414 metadata.
 *
 * Both are public, cacheable documents. Five minutes, like the MCP OAuth
 * metadata: long enough to spare the shop, short enough that a change of
 * sign-in types reaches Personal Agents the same hour.
 */
final readonly class DiscoveryController
{
    private const MAX_AGE = 300;

    public function __construct(
        private CompanyResolver $companies,
        private AgentJsonResponder $responder,
    ) {}

    public function poppy(Request $request): Response
    {
        if (!$this->companies->settings()->enabled) {
            throw new NotFoundHttpException();
        }

        return $this->responder->respond($this->companies->resolve()->document->toArray(), maxAge: self::MAX_AGE, request: $request);
    }

    public function metadata(Request $request, string $issuerPath): Response
    {
        $company = $this->companies->settings()->enabled ? $this->companies->resolve() : null;
        $server = $company?->server;
        if (null === $server || AuthorizationServerMetadata::path($server->configuration->issuer) !== '/.well-known/oauth-authorization-server/'.$issuerPath) {
            throw new NotFoundHttpException();
        }

        return $this->responder->respond($server->metadata(), maxAge: self::MAX_AGE, request: $request);
    }
}
