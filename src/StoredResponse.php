<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency;

use Symfony\Component\HttpFoundation\Response;

final class StoredResponse
{
    private const SKIPPED_HEADERS = ['set-cookie', 'date'];

    /**
     * @param array<string, list<string|null>> $headers
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function fromResponse(string $fingerprint, Response $response): self
    {
        $headers = array_diff_key($response->headers->all(), array_flip(self::SKIPPED_HEADERS));

        return new self($fingerprint, $response->getStatusCode(), $headers, (string) $response->getContent());
    }

    public function toResponse(): Response
    {
        return new Response($this->body, $this->status, $this->headers);
    }

    /**
     * @return array{fingerprint: string, status: int, headers: array<string, list<string|null>>, body: string}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }

    /**
     * Returns null for anything that does not look like toArray() output.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)
            || !\is_string($data['fingerprint'] ?? null)
            || !\is_int($data['status'] ?? null)
            || !\is_array($data['headers'] ?? null)
            || !\is_string($data['body'] ?? null)
        ) {
            return null;
        }

        /** @var array<string, list<string|null>> $headers */
        $headers = $data['headers'];

        return new self($data['fingerprint'], $data['status'], $headers, $data['body']);
    }
}
