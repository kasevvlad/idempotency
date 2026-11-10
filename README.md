# Idempotency-Key for Laravel and Symfony

[![tests](https://github.com/kasevvlad/idempotency/actions/workflows/tests.yml/badge.svg)](https://github.com/kasevvlad/idempotency/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/kasevvlad/idempotency)](https://packagist.org/packages/kasevvlad/idempotency)
[![PHP](https://img.shields.io/packagist/dependency-v/kasevvlad/idempotency/php)](composer.json)

A client sends `POST /payments`, the network drops before the response arrives, the client retries — and the customer is charged twice.

This package makes such retries safe. The client attaches an `Idempotency-Key` header; the first request is executed and its response is stored, every repeat with the same key gets the stored response back without touching your controller. It follows the IETF draft [The Idempotency-Key HTTP Header Field](https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/) and the way Stripe's API behaves.

```http
POST /payments HTTP/1.1
Idempotency-Key: 8e03978e-40d5-43e8-bc93-6894a57f9324
Content-Type: application/json

{"amount": 1000}
```

## What happens to a request

| Situation | Response |
|---|---|
| First request with a key | Controller runs, response is stored |
| Same key, same payload, first request finished | Stored response, plus `Idempotent-Replayed: true` |
| Same key while the first request is still running | `409 Conflict` with `Retry-After: 1` |
| Same key, different payload (body, path or query) | `422 Unprocessable Content` |
| No key, route does not require it | Passes through untouched |
| No key, route requires it | `400 Bad Request` |
| Key longer than 255 characters | `400 Bad Request` |

Errors are returned as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) `application/problem+json`.

What is **not** stored, so the client can retry with the same key:

- `5xx` responses and uncaught exceptions
- `408`, `409`, `425`, `429` — the request was not really processed
- streamed and file responses

`Set-Cookie` is never replayed. Keys are scoped per authenticated user (or per IP for guests), so two clients can't collide on the same key or read each other's responses.

Only `POST` and `PATCH` are covered by default: `GET`, `PUT` and `DELETE` are idempotent by definition.

## Installation

```bash
composer require kasevvlad/idempotency
```

Requires PHP 8.2+ and Laravel 11+ or Symfony 6.4+.

## Laravel

The service provider is discovered automatically. Add the middleware to routes:

```php
Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('idempotent');

// reject requests without the header
Route::post('/payments', [PaymentController::class, 'store'])
    ->middleware('idempotent:required');

// keep responses for one hour instead of 24
Route::post('/refunds', [RefundController::class, 'store'])
    ->middleware('idempotent:required,3600');
```

Put it after `auth` so keys are scoped by user, not by IP.

Responses and locks live in the cache, so the store must support [atomic locks](https://laravel.com/docs/cache#atomic-locks): `redis`, `database`, `memcached`, `file` or `array`. Redis is the right choice when you run several app servers.

To change the defaults:

```bash
php artisan vendor:publish --tag=idempotency-config
```

```php
// config/idempotency.php
return [
    'store' => env('IDEMPOTENCY_STORE'),  // null means the default cache store
    'header' => 'Idempotency-Key',
    'methods' => ['POST', 'PATCH'],
    'ttl' => 86400,                       // how long responses are kept
    'lock_seconds' => 30,                 // how long a key is locked while the first request runs
    'required' => false,
];
```

## Symfony

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    Kasevvlad\Idempotency\Symfony\IdempotencyBundle::class => ['all' => true],
];
```

Mark controllers with the attribute:

```php
use Kasevvlad\Idempotency\Symfony\Attribute\Idempotent;

final class PaymentController
{
    #[Route('/payments', methods: ['POST'])]
    #[Idempotent(required: true, ttl: 3600)]
    public function create(Request $request): JsonResponse
    {
        // ...
    }
}
```

The attribute also works on the controller class. The bundle uses `cache.app` and `lock.factory` by default (`composer require symfony/lock` if you don't have it yet); for several servers point both at Redis:

```yaml
# config/packages/idempotency.yaml
idempotency:
    cache_pool: cache.app
    lock_factory: lock.factory
    header: Idempotency-Key
    methods: [POST, PATCH]
    ttl: 86400
    lock_seconds: 30
    required: false
```

## Other frameworks

The core only depends on `symfony/http-foundation`, so it fits anything built on it. Implement `Kasevvlad\Idempotency\Store\Store` for your storage and call the three methods around your handler:

```php
$idempotency = new Idempotency($store, new Options(required: true));

if ($early = $idempotency->begin($request, scope: 'user:'.$userId)) {
    return $early; // replay or error
}

try {
    $response = $handler($request);
} catch (Throwable $e) {
    $idempotency->abort($request); // free the key for a retry
    throw $e;
}

$idempotency->complete($request, $response);
```

## How it works

1. The storage key is `sha256(scope + Idempotency-Key)`, the fingerprint is `sha256` of method, path, query and body.
2. If a response is stored under the key, it is replayed — or `422` if the fingerprint differs.
3. Otherwise the key is locked. A request that can't get the lock receives `409`.
4. The store is checked once more under the lock: the first request may have finished in between.
5. After the controller, the response is stored for `ttl` seconds and the lock is released. On an exception the lock is just released.

The lock has its own timeout (`lock_seconds`), so a crashed worker doesn't block the key forever. Keep it longer than your slowest request.

## Testing

```bash
composer test
composer analyse
```

## License

MIT
