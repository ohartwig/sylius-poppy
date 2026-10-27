<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A valid signed-out Session Token: nobody to authenticate, nothing to refuse.
 * The failure handler lets the request continue as a guest.
 */
final class SignedOutSession extends AuthenticationException
{
}
