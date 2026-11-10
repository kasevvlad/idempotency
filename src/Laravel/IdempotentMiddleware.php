<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Laravel;

use Closure;
use Illuminate\Http\Request;
use Kasevvlad\Idempotency\Idempotency;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('idempotent'), 'idempotent:required' or 'idempotent:required,3600'.
 */
final class IdempotentMiddleware
{
    public function __construct(private readonly Idempotency $idempotency)
    {
    }

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $options = $this->idempotency->options()->with(
            ttl: $this->ttl($args),
            required: \in_array('required', $args, true) ?: null,
        );

        if ($early = $this->idempotency->begin($request, $this->scope($request), $options)) {
            return $early;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->idempotency->abort($request);

            throw $e;
        }

        $this->idempotency->complete($request, $response);

        return $response;
    }

    private function scope(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /**
     * @param array<string> $args
     */
    private function ttl(array $args): ?int
    {
        foreach ($args as $arg) {
            if (ctype_digit($arg)) {
                return (int) $arg;
            }
        }

        return null;
    }
}
