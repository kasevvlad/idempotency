<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Symfony\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final class Idempotent
{
    public function __construct(
        public readonly ?int $ttl = null,
        public readonly ?bool $required = null,
    ) {
    }
}
