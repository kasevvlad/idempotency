<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Store;

use Kasevvlad\Idempotency\StoredResponse;

interface Store
{
    public function find(string $key): ?StoredResponse;

    public function save(string $key, StoredResponse $response, int $ttl): void;

    /**
     * Returns null when the key is already locked by another request.
     */
    public function lock(string $key, int $seconds): ?Lock;
}
