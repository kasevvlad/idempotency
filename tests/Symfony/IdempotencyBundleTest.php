<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Tests\Symfony;

use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Options;
use Kasevvlad\Idempotency\Symfony\IdempotencyBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class IdempotencyBundleTest extends TestCase
{
    public function testServicesAreWiredFromConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('cache.app', ArrayAdapter::class);
        $container->register('lock.factory', LockFactory::class)->setArguments([new InMemoryStore()]);

        $extension = (new IdempotencyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([['ttl' => 60, 'required' => true, 'methods' => ['post']]], $container);

        self::assertSame(['kernel.event_subscriber' => [[]]], $container->getDefinition('idempotency.subscriber')->getTags());
        $container->getDefinition(Idempotency::class)->setPublic(true);
        $container->compile();

        $idempotency = $container->get(Idempotency::class);
        self::assertInstanceOf(Idempotency::class, $idempotency);
        self::assertEquals(new Options(methods: ['POST'], ttl: 60, required: true), $idempotency->options());
    }
}
