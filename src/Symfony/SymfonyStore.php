<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Symfony;

use Kasevvlad\Idempotency\Store\Lock;
use Kasevvlad\Idempotency\Store\Store;
use Kasevvlad\Idempotency\StoredResponse;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

final class SymfonyStore implements Store
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $locks,
    ) {
    }

    public function find(string $key): ?StoredResponse
    {
        $item = $this->cache->getItem($key);

        return $item->isHit() ? StoredResponse::fromArray($item->get()) : null;
    }

    public function save(string $key, StoredResponse $response, int $ttl): void
    {
        $item = $this->cache->getItem($key);
        $item->set($response->toArray());
        $item->expiresAfter($ttl);

        $this->cache->save($item);
    }

    public function lock(string $key, int $seconds): ?Lock
    {
        $lock = $this->locks->createLock($key, $seconds, false);

        return $lock->acquire() ? new Lock($lock->release(...)) : null;
    }
}
