<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Poppy;

use Koh\Poppy\Client\ClientPolicy;
use Koh\Poppy\Discovery\Api;
use Koh\Poppy\Discovery\PoppyDocument;
use Koh\Poppy\Dpop\NonceSource;
use Koh\Poppy\Http\JsonFetcher;
use Koh\Poppy\Http\Url;
use Koh\Poppy\PoppyServer;
use Koh\Poppy\Replay\ReplayGuard;
use Koh\Poppy\Server\Endpoints;
use Koh\Poppy\Server\RateLimiter;
use Koh\Poppy\Server\ServerConfiguration;
use Koh\Poppy\Server\Storage\PoppyStorage;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the Company for the current request's channel.
 *
 * The configuration follows from what is installed and enabled, never the
 * other way round: no server, no `auth`; no Shop API wiring, no `apis`; no
 * sign-in scopes, no sign-in. A host that does not resolve to an HTTPS URL —
 * a bare IP, a preview hostname without TLS — gets the website-only document,
 * because an issuer has to be an HTTPS URL and would be wrong on any other
 * host.
 */
final class CompanyResolver
{
    public const ISSUER_PATH = '/poppy';

    /** @var array<string, PoppyServer> one server per issuer and process */
    private array $servers = [];

    public function __construct(
        private readonly AgentContextFactoryInterface $contexts,
        private readonly Settings $settings,
        private readonly PoppyStorage $storage,
        private readonly ReplayGuard $replayGuard,
        private readonly ClientPolicy $policy,
        private readonly JsonFetcher $fetcher,
        private readonly ClockInterface $clock,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RateLimiter $rateLimiter,
        private readonly ?NonceSource $nonces = null,
    ) {}

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function resolve(): Company
    {
        $context = $this->contexts->create();
        $domain = (string) Url::host($context->baseUrl);
        $name = $this->settings->organizationName ?? $context->channel->getName() ?? $domain;

        $issuer = $this->settings->issuer ?? $context->baseUrl.self::ISSUER_PATH;
        if (!$this->settings->server || '' === $domain || !Url::isHttps($issuer)) {
            return new Company($context, new PoppyDocument($name, $domain), null, $domain, null, null, null);
        }

        $apis = [];
        if ($this->settings->shopApi) {
            $apis[] = Api::openApi($context->url($this->settings->shopApiOpenApiPath), $this->settings->shopApiDescription);
        }

        $domains = null !== $this->settings->issuer && [] !== $this->settings->domains ? $this->settings->domains : [$domain];
        if (!\in_array($domain, array_map('strtolower', $domains), true)) {
            // A channel the fixed issuer does not list cannot name it: the
            // issuer's poppy_domains would contradict the document.
            return new Company($context, new PoppyDocument($name, $domain), null, $domain, null, null, null);
        }

        $configuration = new ServerConfiguration(
            $issuer,
            $domains,
            Endpoints::below($issuer),
            directScopes: $this->settings->directScopes,
            deviceScopes: $this->settings->deviceScopes,
            apis: $apis,
            sessionTokenTtl: $this->settings->sessionTokenTtl,
            sessionLifetime: $this->settings->sessionLifetime,
            accountTokenTtl: $this->settings->accountTokenTtl > 0 ? $this->settings->accountTokenTtl : null,
        );

        $server = $this->servers[$issuer] ??= new PoppyServer(
            $configuration,
            $this->storage,
            $this->replayGuard,
            $this->policy,
            $this->fetcher,
            $this->clock,
            $this->responseFactory,
            $this->streamFactory,
            $this->rateLimiter,
            $this->settings->dpopNonces ? $this->nonces : null,
        );

        $browserSession = $this->settings->browserSession ? $context->url(self::ISSUER_PATH.'/browser-session') : null;
        $feedback = $this->settings->feedback ? $context->url(self::ISSUER_PATH.'/feedback') : null;

        return new Company(
            $context,
            new PoppyDocument($name, $domain, $configuration, $apis, browserSessionEndpoint: $browserSession, feedbackEndpoint: $feedback),
            $server,
            $domain,
            $browserSession,
            $feedback,
            $this->settings->shopApi ? $this->settings->shopApiPrefix : null,
        );
    }
}
