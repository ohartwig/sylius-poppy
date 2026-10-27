<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;

/** Announces poppy.json to llms.txt and to `koh:agent:check`, which then probes that it answers. */
final readonly class PoppyEndpointProvider implements AgentEndpointProviderInterface
{
    public function __construct(private bool $enabled = true)
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        if (!$this->enabled) {
            return;
        }

        yield new AgentEndpoint(
            path: '/.well-known/poppy.json',
            title: 'Personal Agent Protocol (Poppy) discovery',
            protocol: 'poppy',
            description: 'How a Personal Agent starts a Session here, how the customer signs in, and which APIs accept its tokens.',
        );
    }
}
