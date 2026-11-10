<?php

declare(strict_types=1);

namespace Kasevvlad\Idempotency;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 problem details.
 */
final class Problem
{
    public static function missingKey(string $header): JsonResponse
    {
        return self::create(400, 'Missing idempotency key', sprintf('The %s header is required for this request.', $header));
    }

    public static function invalidKey(string $header, int $maxLength): JsonResponse
    {
        return self::create(400, 'Invalid idempotency key', sprintf('The %s header must be at most %d characters.', $header, $maxLength));
    }

    public static function inProgress(): JsonResponse
    {
        $response = self::create(409, 'Request in progress', 'A request with this idempotency key is still being processed.');
        $response->headers->set('Retry-After', '1');

        return $response;
    }

    public static function keyReused(): JsonResponse
    {
        return self::create(422, 'Idempotency key reused', 'This idempotency key was already used with a different request payload.');
    }

    private static function create(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
