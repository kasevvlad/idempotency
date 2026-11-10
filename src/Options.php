<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency;

final class Options
{
    /**
     * @param list<string> $methods
     */
    public function __construct(
        public readonly string $header = 'Idempotency-Key',
        public readonly array $methods = ['POST', 'PATCH'],
        public readonly int $ttl = 86400,
        public readonly int $lockSeconds = 30,
        public readonly bool $required = false,
        public readonly int $maxKeyLength = 255,
    ) {
    }

    public function with(?int $ttl = null, ?bool $required = null): self
    {
        return new self(
            $this->header,
            $this->methods,
            $ttl ?? $this->ttl,
            $this->lockSeconds,
            $required ?? $this->required,
            $this->maxKeyLength,
        );
    }
}
