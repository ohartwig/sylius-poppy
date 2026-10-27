<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HttpFoundation in, PSR-7 to koh/poppy, HttpFoundation out.
 *
 * The PSR request keeps the internal URL the request arrived at; that is
 * harmless, because every check that needs the public URL — DPoP `htu`,
 * assertion audiences — is given it explicitly from the channel context.
 */
final class PsrBridge
{
    private readonly PsrHttpFactory $toPsr;

    private readonly HttpFoundationFactory $toSymfony;

    public function __construct(?Psr17Factory $factory = null)
    {
        $factory ??= new Psr17Factory();
        $this->toPsr = new PsrHttpFactory($factory, $factory, $factory, $factory);
        $this->toSymfony = new HttpFoundationFactory();
    }

    public function request(Request $request): ServerRequestInterface
    {
        return $this->toPsr->createRequest($request);
    }

    public function response(ResponseInterface $response): Response
    {
        return $this->toSymfony->createResponse($response);
    }
}
