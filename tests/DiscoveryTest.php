<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy\Tests;

use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusPoppy\Controller\DiscoveryController;
use Koh\SyliusPoppy\Poppy\Settings;
use Koh\SyliusPoppy\Tests\Support\ShopKit;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DiscoveryTest extends TestCase
{
    public function testThePoppyDocumentOfAChannel(): void
    {
        $document = $this->document(new Settings(shopApi: true));

        self::assertSame('0.2', $document['protocol_version']);
        self::assertSame(['name' => 'Example Shop', 'domain' => 'shop.example'], $document['organization']);
        self::assertSame([
            'issuer' => 'https://shop.example/poppy',
            'direct' => ['scopes' => ['poppy:read', 'poppy:write']],
            'device' => ['scopes' => ['poppy:read']],
        ], $document['auth']);
        self::assertSame(['browser_session_endpoint' => 'https://shop.example/poppy/browser-session'], $document['web']);
        self::assertSame(['endpoint' => 'https://shop.example/poppy/feedback'], $document['feedback']);
        self::assertSame([['type' => 'openapi', 'url' => 'https://shop.example/api/v2/docs.jsonopenapi', 'description' => 'Catalogue, cart, checkout, orders and addresses (Sylius Shop API)']], $document['apis']);
    }

    public function testWithoutTheServerTheWebsiteAloneIsDeclared(): void
    {
        self::assertSame(
            ['protocol_version' => '0.2', 'organization' => ['name' => 'Example Shop', 'domain' => 'shop.example'], 'web' => []],
            $this->document(new Settings(server: false)),
        );
    }

    public function testAHostWithoutHttpsDeclaresTheWebsiteAlone(): void
    {
        $document = $this->document(new Settings(), 'http://10.0.0.5');

        self::assertArrayNotHasKey('auth', $document);
    }

    public function testAFixedIssuerNamesOnlyItsDomains(): void
    {
        $listed = $this->document(new Settings(issuer: 'https://shop.example/poppy', domains: ['shop.example', 'shop.example.at']));
        self::assertSame('https://shop.example/poppy', $listed['auth']['issuer'] ?? null);

        $unlisted = $this->document(new Settings(issuer: 'https://shop.example/poppy', domains: ['shop.example']), 'https://shop.example.at');
        self::assertArrayNotHasKey('auth', $unlisted, 'a channel the issuer does not list cannot name it');
    }

    public function testNoSignInTypesMeansSignedOutSessionsOnly(): void
    {
        $document = $this->document(new Settings(directScopes: [], deviceScopes: [], browserSession: false, feedback: false));

        self::assertSame(['issuer' => 'https://shop.example/poppy'], $document['auth']);
        self::assertSame([], $document['web']);
        self::assertArrayNotHasKey('feedback', $document);
    }

    public function testTheIssuerMetadataIsServedAtItsRfc8414Path(): void
    {
        $kit = new ShopKit($this->channel());
        $controller = new DiscoveryController($kit->companies, new AgentJsonResponder());

        $response = $controller->metadata(new Request(), 'poppy');
        $metadata = ShopKit::json($response);
        self::assertSame('https://shop.example/poppy', $metadata['issuer']);
        self::assertSame(['shop.example'], $metadata['poppy_domains']);
        self::assertSame('https://shop.example/poppy/token', $metadata['token_endpoint']);
        self::assertSame(300, $response->getMaxAge());

        $this->expectException(NotFoundHttpException::class);
        $controller->metadata(new Request(), 'other');
    }

    public function testDisabledAnswers404(): void
    {
        $controller = new DiscoveryController((new ShopKit($this->channel(), new Settings(enabled: false)))->companies, new AgentJsonResponder());

        $this->expectException(NotFoundHttpException::class);
        $controller->poppy(new Request());
    }

    /** @return array<string, mixed> */
    private function document(Settings $settings, string $baseUrl = ShopKit::BASE): array
    {
        $controller = new DiscoveryController((new ShopKit($this->channel(), $settings, $baseUrl))->companies, new AgentJsonResponder());

        return ShopKit::json($controller->poppy(new Request()));
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getName')->willReturn('Example Shop');
        $channel->method('getCode')->willReturn('WEB');

        return $channel;
    }
}
