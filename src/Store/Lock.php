<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Store;

final class Lock
{
    private bool $released = false;

    /**
     * @param \Closure(): mixed $release
     */
    public function __construct(private readonly \Closure $release)
    {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        ($this->release)();
    }
}
