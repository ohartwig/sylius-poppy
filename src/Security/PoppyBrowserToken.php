<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A shop login that exists only because a Personal Agent's browser carries a
 * signed-in Session's cookie. It is checked again on every request and
 * dropped the moment the Session signs out, ends, or the cookie is gone — the
 * website follows the Session, never the other way round (Poppy 0.2,
 * section 5).
 */
final class PoppyBrowserToken extends AbstractToken
{
    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     */
    public function __construct(
        UserInterface $user,
        private string $firewallName,
        array $roles,
        private string $sessionId,
        private array $scopes,
    ) {
        parent::__construct($roles);
        $this->setUser($user);
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function __serialize(): array
    {
        return [$this->firewallName, $this->sessionId, $this->scopes, parent::__serialize()];
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        [$firewallName, $sessionId, $scopes, $parent] = $data + [null, null, null, null];
        $this->firewallName = \is_string($firewallName) ? $firewallName : '';
        $this->sessionId = \is_string($sessionId) ? $sessionId : '';
        $this->scopes = \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        parent::__unserialize(\is_array($parent) ? $parent : []);
    }
}
