<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Options;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/idempotency.php', 'idempotency');

        $this->app->singleton(Idempotency::class, function (Application $app): Idempotency {
            /** @var array{store: ?string, header: string, methods: list<string>, ttl: int, lock_seconds: int, required: bool} $config */
            $config = $app->make(Config::class)->get('idempotency');

            return new Idempotency(
                new CacheStore($app->make(CacheFactory::class)->store($config['store'])),
                new Options(
                    header: $config['header'],
                    methods: array_map(strtoupper(...), $config['methods']),
                    ttl: $config['ttl'],
                    lockSeconds: $config['lock_seconds'],
                    required: $config['required'],
                ),
            );
        });
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('idempotent', IdempotentMiddleware::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/idempotency.php' => $this->app->configPath('idempotency.php'),
            ], 'idempotency-config');
        }
    }
}
