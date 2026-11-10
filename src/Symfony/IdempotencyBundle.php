<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Symfony;

use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Options;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class IdempotencyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
                ->scalarNode('lock_factory')->defaultValue('lock.factory')->end()
                ->scalarNode('header')->defaultValue('Idempotency-Key')->end()
                ->arrayNode('methods')
                    ->scalarPrototype()->end()
                    ->defaultValue(['POST', 'PATCH'])
                ->end()
                ->integerNode('ttl')->min(1)->defaultValue(86400)->end()
                ->integerNode('lock_seconds')->min(1)->defaultValue(30)->end()
                ->booleanNode('required')->defaultFalse()->end()
            ->end();
    }

    /**
     * @param array{cache_pool: string, lock_factory: string, header: string, methods: list<string>, ttl: int, lock_seconds: int, required: bool} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('idempotency.store', SymfonyStore::class)
            ->args([service($config['cache_pool']), service($config['lock_factory'])]);

        $services->set('idempotency.options', Options::class)
            ->args([
                $config['header'],
                array_map(strtoupper(...), $config['methods']),
                $config['ttl'],
                $config['lock_seconds'],
                $config['required'],
            ]);

        $services->set(Idempotency::class)
            ->args([service('idempotency.store'), service('idempotency.options')]);

        $services->set('idempotency.subscriber', IdempotencySubscriber::class)
            ->args([service(Idempotency::class), service('security.token_storage')->nullOnInvalid()])
            ->tag('kernel.event_subscriber');
    }
}
