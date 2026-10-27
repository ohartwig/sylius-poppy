<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusPoppy;

use Koh\Poppy\Server\Scopes;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The Personal Agent Protocol (Poppy 0.2) for a Sylius shop.
 *
 * Every channel is its own Company by default: its issuer is
 * https://{channel hostname}/poppy and its poppy.json names only that host. A
 * shop whose channels are one Company — one customer base, one account per
 * person — sets `issuer` and `domains` explicitly, and every channel then
 * serves the same `auth`.
 */
final class KohSyliusPoppyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_poppy';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => [
                    'Koh\\SyliusPoppy\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ]);
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Serve /.well-known/poppy.json at all. Off answers 404 there and on every Poppy endpoint.')
                ->end()
                ->booleanNode('server')
                    ->defaultTrue()
                    ->info('Run the authorization server: Sessions, sign-in, the Shop API and browser sessions. Off declares the website alone (web: {}), which is a complete answer as well.')
                ->end()
                ->scalarNode('organization_name')
                    ->defaultNull()
                    ->info('The name Personal Agents show their Users. null: the channel name.')
                ->end()
                ->scalarNode('issuer')
                    ->defaultNull()
                    ->info('A fixed issuer for every channel, such as https://shop.example/poppy. null: one issuer per channel, https://{channel hostname}/poppy. Never change it once agents use it: their User IDs and Account Tokens are bound to it.')
                ->end()
                ->arrayNode('domains')
                    ->info('With a fixed issuer: every domain whose poppy.json names it (poppy_domains). Ignored without one.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('sign_in')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('direct')
                            ->info('Scopes Direct Sign-In can grant; [] turns it off. The customer signs in on the shop\'s own page.')
                            ->scalarPrototype()->end()
                            ->defaultValue([Scopes::READ, Scopes::WRITE])
                        ->end()
                        ->arrayNode('device')
                            ->info('Scopes Device Sign-In can grant; [] turns it off. The customer enters a code on the shop\'s page.')
                            ->scalarPrototype()->end()
                            ->defaultValue([Scopes::READ])
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('browser_session')
                    ->defaultTrue()
                    ->info('Let a Personal Agent\'s browser join its Session (web.browser_session_endpoint). Needs the authenticator on the shop firewall, see the README.')
                ->end()
                ->booleanNode('feedback')
                    ->defaultTrue()
                    ->info('Accept reports of tasks the shop does not offer (feedback.endpoint), listed in the admin.')
                ->end()
                ->arrayNode('shop_api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('List the Sylius Shop API in poppy.json and accept DPoP Session Tokens on it. Needs the authenticator on the api_shop firewall, see the README.')
                        ->end()
                        ->scalarNode('prefix')
                            ->defaultValue('/api/v2/shop')
                            ->info('Path prefix of the Shop API, where DPoP Session Tokens are accepted.')
                        ->end()
                        ->scalarNode('openapi_path')
                            ->defaultValue('/api/v2/docs.jsonopenapi')
                            ->info('Path of the OpenAPI description Personal Agents read.')
                        ->end()
                        ->scalarNode('description')
                            ->defaultValue('Catalogue, cart, checkout, orders and addresses (Sylius Shop API)')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('session_token_ttl')->defaultValue(3600)->min(60)->max(86400)->end()
                ->integerNode('session_lifetime')->defaultValue(2592000)->min(3600)->end()
                ->integerNode('account_token_ttl')
                    ->defaultValue(7776000)
                    ->min(0)
                    ->info('Lifetime of an Account Token in seconds (90 days). 0: until the customer disconnects the agent.')
                ->end()
                ->arrayNode('clients')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allow')
                            ->info('client_id URLs or hosts. Non-empty: only these Personal Agents are accepted.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('block')
                            ->info('client_id URLs or hosts that are refused.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('dpop_nonces')
                    ->defaultFalse()
                    ->info('Require server-issued DPoP nonces, so proofs cannot be made in advance.')
                ->end()
                ->arrayNode('rate_limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(self::limit('token', 60, '1 minute'))
                        ->append(self::limit('revoke', 30, '1 minute'))
                        ->append(self::limit('device', 10, '1 minute'))
                        ->append(self::limit('feedback', 20, '1 hour'))
                        ->append(self::limit('device_page', 10, '10 minutes'))
                    ->end()
                ->end()
                ->scalarNode('table_prefix')->defaultValue('koh_poppy_')->cannotBeEmpty()->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_poppy.config', $config);
        $builder->setParameter('koh_poppy.table_prefix', (string) ($config['table_prefix'] ?? 'koh_poppy_'));
        $builder->setParameter('koh_poppy.rate_limits', $config['rate_limits'] ?? []);

        $container->import('../config/services.php');
    }

    private static function limit(string $name, int $limit, string $interval): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('limit')->min(0)->defaultValue($limit)->info('0 disables the limit.')->end()
                ->scalarNode('interval')->defaultValue($interval)->end()
            ->end();

        return $node;
    }
}
