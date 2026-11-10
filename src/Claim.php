<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency;

use Kasevvlad\Idempotency\Store\Lock;

/**
 * @internal
 */
final class Claim
{
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly Lock $lock,
        public readonly int $ttl,
    ) {
    }
}
