<?php

declare(strict_types=1);

namespace Reeds\Core;

use Reeds\Exception\ApiException;
use Reeds\Exception\AuthenticationException;
use Reeds\Exception\ConflictException;
use Reeds\Exception\InvalidRequestException;
use Reeds\Exception\NotFoundException;
use Reeds\Exception\PaymentRequiredException;
use Reeds\Exception\PermissionDeniedException;
use Reeds\Exception\RateLimitException;
use Reeds\Exception\ReedsException;
use Reeds\Exception\ServiceUnavailableException;

/** Builds the exception for a failed response. @internal */
final class Errors
{
    private const BY_STATUS = [
        400 => InvalidRequestException::class,
        401 => AuthenticationException::class,
        402 => PaymentRequiredException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => InvalidRequestException::class,
        429 => RateLimitException::class,
        503 => ServiceUnavailableException::class,
    ];

    private function __construct()
    {
    }

    /** @return class-string<ReedsException> */
    public static function classFor(int $status): string
    {
        return self::BY_STATUS[$status] ?? ApiException::class;
    }

    /** From the failure envelope, an OAuth error, or anything else (a proxy's HTML page, say). */
    public static function fromResponse(int $status, mixed $body, ?string $requestId, ?float $retryAfter = null): ReedsException
    {
        $class = self::classFor($status);
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            /** @var array<string, mixed> $error */
            $error = $body['error'];
            $fields = isset($error['fields']) && is_array($error['fields']) ? array_values($error['fields']) : [];
            /** @var list<array{field: string, message: string}> $fields */
            $exception = new $class(
                is_string($error['code'] ?? null) ? $error['code'] : "HTTP_{$status}",
                is_string($error['message'] ?? null) ? $error['message'] : "Reeds answered {$status}.",
                $status,
                $fields,
                is_string($error['doc_url'] ?? null) ? $error['doc_url'] : null,
                $requestId,
                $error,
            );
        } elseif (is_array($body) && is_string($body['error'] ?? null)) {
            /** @var array<string, mixed> $body */
            $exception = new $class(
                $body['error'],
                is_string($body['error_description'] ?? null) ? $body['error_description'] : $body['error'],
                $status,
                [],
                null,
                $requestId,
                $body,
            );
        } else {
            $exception = new $class("HTTP_{$status}", "Reeds answered {$status} without an error body.", $status, [], null, $requestId);
        }
        if ($exception instanceof RateLimitException || $exception instanceof ServiceUnavailableException) {
            $exception->retryAfter = $retryAfter;
        }
        return $exception;
    }
}
