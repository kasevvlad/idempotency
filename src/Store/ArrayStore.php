<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Store;

use Kasevvlad\Idempotency\StoredResponse;

/**
 * Lives for one PHP process. Handy in tests, useless in production.
 */
final class ArrayStore implements Store
{
    /** @var array<string, StoredResponse> */
    private array $responses = [];

    /** @var array<string, true> */
    private array $locks = [];

    public function find(string $key): ?StoredResponse
    {
        return $this->responses[$key] ?? null;
    }

    public function save(string $key, StoredResponse $response, int $ttl): void
    {
        $this->responses[$key] = $response;
    }

    public function lock(string $key, int $seconds): ?Lock
    {
        if (isset($this->locks[$key])) {
            return null;
        }

        $this->locks[$key] = true;

        return new Lock(function () use ($key): void {
            unset($this->locks[$key]);
        });
    }
}
