<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Koh\Poppy\Exception\PoppyError;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Carries a protocol error through Symfony's authenticator flow to the failure handler. */
final class PoppyAuthenticationException extends AuthenticationException
{
    public function __construct(public readonly PoppyError $error)
    {
        parent::__construct($error->getMessage(), 0, $error);
    }

    public function getMessageKey(): string
    {
        return $this->error->error;
    }
}
