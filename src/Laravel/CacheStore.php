<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Laravel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Kasevvlad\Idempotency\Store\Lock;
use Kasevvlad\Idempotency\Store\Store;
use Kasevvlad\Idempotency\StoredResponse;

final class CacheStore implements Store
{
    private readonly LockProvider $locks;

    public function __construct(private readonly Repository $cache)
    {
        $store = $cache->getStore();

        if (!$store instanceof LockProvider) {
            throw new \InvalidArgumentException(sprintf('Cache store %s does not support locks. Use redis, database, file, memcached or array.', $store::class));
        }

        $this->locks = $store;
    }

    public function find(string $key): ?StoredResponse
    {
        return StoredResponse::fromArray($this->cache->get($key));
    }

    public function save(string $key, StoredResponse $response, int $ttl): void
    {
        $this->cache->put($key, $response->toArray(), $ttl);
    }

    public function lock(string $key, int $seconds): ?Lock
    {
        $lock = $this->locks->lock($key.':lock', $seconds);

        return $lock->get() ? new Lock($lock->release(...)) : null;
    }
}
