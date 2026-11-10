<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency;

use Kasevvlad\Idempotency\Store\Store;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Idempotency
{
    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const ATTRIBUTE = '_idempotency_claim';

    private const NOT_STORED_STATUSES = [408, 409, 425, 429];

    public function __construct(
        private readonly Store $store,
        private readonly Options $options = new Options(),
    ) {
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * Returns a response to send right away (replay or error), or null to let the request through.
     * The scope separates keys of different clients, e.g. a user id.
     */
    public function begin(Request $request, string $scope, ?Options $options = null): ?Response
    {
        $options ??= $this->options;

        if (!\in_array($request->getMethod(), $options->methods, true)) {
            return null;
        }

        $key = (string) $request->headers->get($options->header, '');

        if ($key === '') {
            return $options->required ? Problem::missingKey($options->header) : null;
        }

        if (\strlen($key) > $options->maxKeyLength) {
            return Problem::invalidKey($options->header, $options->maxKeyLength);
        }

        $storageKey = 'idempotency_'.hash('sha256', $scope."\0".$key);
        $fingerprint = $this->fingerprint($request);

        if ($stored = $this->store->find($storageKey)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = $this->store->lock($storageKey, $options->lockSeconds);

        if ($lock === null) {
            return Problem::inProgress();
        }

        // the first request could have finished between find() and lock()
        if ($stored = $this->store->find($storageKey)) {
            $lock->release();

            return $this->replay($stored, $fingerprint);
        }

        $request->attributes->set(self::ATTRIBUTE, new Claim($storageKey, $fingerprint, $lock, $options->ttl));

        return null;
    }

    public function complete(Request $request, Response $response): void
    {
        $claim = $this->takeClaim($request);

        if ($claim === null) {
            return;
        }

        try {
            if ($this->isStorable($response)) {
                $this->store->save($claim->key, StoredResponse::fromResponse($claim->fingerprint, $response), $claim->ttl);
            }
        } finally {
            $claim->lock->release();
        }
    }

    /**
     * Releases the lock without saving anything, so the client can retry.
     */
    public function abort(Request $request): void
    {
        $this->takeClaim($request)?->lock->release();
    }

    private function replay(StoredResponse $stored, string $fingerprint): Response
    {
        if (!hash_equals($stored->fingerprint, $fingerprint)) {
            return Problem::keyReused();
        }

        $response = $stored->toResponse();
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    private function fingerprint(Request $request): string
    {
        $body = $request->getContent();

        if ($body === '') {
            $body = serialize([$request->request->all(), array_keys($request->files->all())]);
        }

        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            hash('sha256', $body),
        ]));
    }

    private function isStorable(Response $response): bool
    {
        $status = $response->getStatusCode();

        return $status < 500
            && !\in_array($status, self::NOT_STORED_STATUSES, true)
            && $response->getContent() !== false;
    }

    private function takeClaim(Request $request): ?Claim
    {
        $claim = $request->attributes->get(self::ATTRIBUTE);
        $request->attributes->remove(self::ATTRIBUTE);

        return $claim instanceof Claim ? $claim : null;
    }
}
