<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency\Tests;

use Kasevvlad\Idempotency\Idempotency;
use Kasevvlad\Idempotency\Options;
use Kasevvlad\Idempotency\Store\ArrayStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IdempotencyTest extends TestCase
{
    private ArrayStore $store;
    private Idempotency $idempotency;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
        $this->idempotency = new Idempotency($this->store);
    }

    public function testRequestWithoutKeyPassesThrough(): void
    {
        $this->send($this->post(key: null));
        $this->send($this->post(key: null));

        self::assertSame(2, $this->calls);
    }

    public function testRepeatedRequestIsReplayed(): void
    {
        $first = $this->send($this->post());
        $second = $this->send($this->post());

        self::assertSame(1, $this->calls);
        self::assertSame(201, $second->getStatusCode());
        self::assertSame($first->getContent(), $second->getContent());
        self::assertSame('application/json', $second->headers->get('Content-Type'));
        self::assertSame('true', $second->headers->get(Idempotency::REPLAYED_HEADER));
        self::assertNull($first->headers->get(Idempotency::REPLAYED_HEADER));
    }

    public function testSameKeyWithDifferentPayloadIsRejected(): void
    {
        $this->send($this->post(body: '{"amount":100}'));
        $response = $this->send($this->post(body: '{"amount":500}'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(1, $this->calls);
    }

    public function testSameKeyOnAnotherPathIsRejected(): void
    {
        $this->send($this->post(path: '/orders'));
        $response = $this->send($this->post(path: '/refunds'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testKeysAreIsolatedByScope(): void
    {
        $this->send($this->post(), scope: 'user:1');
        $this->send($this->post(), scope: 'user:2');

        self::assertSame(2, $this->calls);
    }

    public function testConcurrentRequestGetsConflict(): void
    {
        $first = $this->post();
        self::assertNull($this->idempotency->begin($first, 'user:1'));

        $response = $this->idempotency->begin($this->post(), 'user:1');

        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));

        $this->idempotency->complete($first, new Response('done', 201));
        $replayed = $this->idempotency->begin($this->post(), 'user:1');

        self::assertSame('done', $replayed?->getContent());
    }

    public function testServerErrorIsNotStoredAndKeyCanBeRetried(): void
    {
        $this->send($this->post(), response: new Response('oops', 503));
        $retry = $this->send($this->post());

        self::assertSame(2, $this->calls);
        self::assertSame(201, $retry->getStatusCode());
    }

    #[DataProvider('retryableStatuses')]
    public function testRetryableClientErrorsAreNotStored(int $status): void
    {
        $this->send($this->post(), response: new Response('', $status));
        $this->send($this->post());

        self::assertSame(2, $this->calls);
    }

    /**
     * @return iterable<array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        yield [408];
        yield [409];
        yield [425];
        yield [429];
    }

    public function testValidationErrorIsStored(): void
    {
        $this->send($this->post(), response: new JsonResponse(['error' => 'invalid'], 422));
        $replayed = $this->send($this->post());

        self::assertSame(1, $this->calls);
        self::assertSame(422, $replayed->getStatusCode());
    }

    public function testAbortReleasesLockWithoutStoring(): void
    {
        $request = $this->post();
        $this->idempotency->begin($request, 'user:1');
        $this->idempotency->abort($request);

        $this->send($this->post());

        self::assertSame(1, $this->calls);
    }

    public function testStreamedResponseIsNotStored(): void
    {
        $this->send($this->post(), response: new StreamedResponse(static function (): void {}));
        $this->send($this->post());

        self::assertSame(2, $this->calls);
    }

    public function testCookiesAreNotReplayed(): void
    {
        $response = new Response('ok', 201);
        $response->headers->set('Set-Cookie', 'session=secret');

        $this->send($this->post(), response: $response);
        $replayed = $this->send($this->post());

        self::assertNull($replayed->headers->get('Set-Cookie'));
    }

    public function testSafeMethodsAreIgnored(): void
    {
        $this->send(Request::create('/orders', 'GET', server: ['HTTP_IDEMPOTENCY_KEY' => 'abc']));
        $this->send(Request::create('/orders', 'GET', server: ['HTTP_IDEMPOTENCY_KEY' => 'abc']));

        self::assertSame(2, $this->calls);
    }

    public function testMissingKeyIsRejectedWhenRequired(): void
    {
        $response = $this->idempotency->begin($this->post(key: null), 'user:1', new Options(required: true));

        self::assertSame(400, $response?->getStatusCode());
    }

    public function testTooLongKeyIsRejected(): void
    {
        $response = $this->idempotency->begin($this->post(key: str_repeat('a', 256)), 'user:1');

        self::assertSame(400, $response?->getStatusCode());
    }

    public function testCustomHeaderName(): void
    {
        $idempotency = new Idempotency($this->store, new Options(header: 'X-Request-Id'));
        $request = Request::create('/orders', 'POST', server: ['HTTP_X_REQUEST_ID' => 'abc']);

        self::assertNull($idempotency->begin($request, 'user:1'));
        $idempotency->complete($request, new Response('ok'));

        $repeat = Request::create('/orders', 'POST', server: ['HTTP_X_REQUEST_ID' => 'abc']);
        self::assertSame('true', $idempotency->begin($repeat, 'user:1')?->headers->get(Idempotency::REPLAYED_HEADER));
    }

    private function send(Request $request, string $scope = 'user:1', ?Response $response = null): Response
    {
        if ($early = $this->idempotency->begin($request, $scope)) {
            return $early;
        }

        ++$this->calls;
        $response ??= new JsonResponse(['id' => $this->calls], 201);
        $this->idempotency->complete($request, $response);

        return $response;
    }

    private function post(?string $key = 'abc', string $path = '/orders', string $body = '{"amount":100}'): Request
    {
        $server = $key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];

        return Request::create($path, 'POST', server: $server, content: $body);
    }
}
