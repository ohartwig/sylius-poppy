<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Poppy;

use Koh\Poppy\Discovery\PoppyDocument;
use Koh\Poppy\PoppyServer;
use Koh\SyliusAgentContracts\Context\AgentContext;

/**
 * The Company a request is addressed to: the channel, its poppy.json and, when
 * the authorization server runs, the server and the public URLs it answers on.
 */
final readonly class Company
{
    public function __construct(
        public AgentContext $context,
        public PoppyDocument $document,
        public ?PoppyServer $server,
        public string $domain,
        public ?string $browserSessionEndpoint,
        public ?string $feedbackEndpoint,
        public ?string $shopApiPrefix,
    ) {}

    public function hasServer(): bool
    {
        return null !== $this->server;
    }

    /** The public URL of a path on the channel's host — what DPoP proofs and assertions name. */
    public function url(string $path): string
    {
        return $this->context->url($path);
    }
}
