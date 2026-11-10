<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Tests\Laravel;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Laravel\IdempotencyServiceProvider;
use Orchestra\Testbench\TestCase;

final class IdempotentMiddlewareTest extends TestCase
{
    private int $calls = 0;

    protected function getPackageProviders($app): array
    {
        return [IdempotencyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function defineRoutes($router): void
    {
        Route::post('/orders', function () {
            ++$this->calls;

            return response()->json(['id' => $this->calls], 201);
        })->middleware('idempotent');

        Route::post('/payments', function () {
            ++$this->calls;

            return response()->json(['ok' => true]);
        })->middleware('idempotent:required');

        Route::post('/failing', function () {
            ++$this->calls;

            throw new \RuntimeException('boom');
        })->middleware('idempotent');
    }

    public function testRepeatedRequestIsReplayed(): void
    {
        $first = $this->postJson('/orders', ['amount' => 100], ['Idempotency-Key' => 'abc']);
        $second = $this->postJson('/orders', ['amount' => 100], ['Idempotency-Key' => 'abc']);

        $first->assertCreated()->assertJson(['id' => 1])->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
        $second->assertCreated()->assertJson(['id' => 1])->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
        self::assertSame(1, $this->calls);
    }

    public function testDifferentPayloadIsRejected(): void
    {
        $this->postJson('/orders', ['amount' => 100], ['Idempotency-Key' => 'abc']);

        $this->postJson('/orders', ['amount' => 500], ['Idempotency-Key' => 'abc'])
            ->assertUnprocessable()
            ->assertJson(['title' => 'Idempotency key reused']);
    }

    public function testKeysAreScopedByUser(): void
    {
        $this->actingAs($this->user(1))->postJson('/orders', [], ['Idempotency-Key' => 'abc']);
        $this->actingAs($this->user(2))->postJson('/orders', [], ['Idempotency-Key' => 'abc']);

        self::assertSame(2, $this->calls);
    }

    public function testRequiredParameter(): void
    {
        $this->postJson('/payments')->assertBadRequest();
        $this->postJson('/payments', [], ['Idempotency-Key' => 'abc'])->assertOk();
    }

    public function testExceptionIsNotStored(): void
    {
        $this->postJson('/failing', [], ['Idempotency-Key' => 'abc'])->assertServerError();
        $this->postJson('/failing', [], ['Idempotency-Key' => 'abc'])->assertServerError();

        self::assertSame(2, $this->calls);
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }
}
