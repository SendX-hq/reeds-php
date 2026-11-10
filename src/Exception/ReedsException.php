<?php

declare(strict_types=1);

namespace Reeds\Exception;

/**
 * Every failure is a ReedsException. Branch on the class or on `$errorCode`,
 * never on the message, which Reeds can reword.
 *
 * PHP's own `getCode()` stays an integer (the HTTP status, or 0); the API's
 * stable code, such as `INSUFFICIENT_BALANCE`, is `$errorCode`.
 */
class ReedsException extends \RuntimeException
{
    /** The HTTP status, or null when no response came (or the library refused before sending). */
    public readonly ?int $status;

    /** The API's stable code (`INSUFFICIENT_BALANCE`), or the OAuth error (`invalid_client`). */
    public readonly string $errorCode;

    /**
     * Each invalid field, on `422 INVALID_REQUEST`.
     *
     * @var list<array{field: string, message: string}>
     */
    public readonly array $fields;

    /** The code's page on the developer hub. */
    public readonly ?string $docUrl;

    /** The `X-Request-Id` of the call. Quote it to Reeds support. */
    public readonly ?string $requestId;

    /**
     * The API's error object as sent, including fields only some codes carry
     * (`delivery_id` on `QUOTE_ALREADY_USED`, `hold_reason` on `ACCOUNT_ON_HOLD`).
     *
     * @var array<string, mixed>|null
     */
    public readonly ?array $raw;

    /**
     * @param list<array{field: string, message: string}> $fields
     * @param array<string, mixed>|null $raw
     */
    public function __construct(
        string $errorCode,
        string $message,
        ?int $status = null,
        array $fields = [],
        ?string $docUrl = null,
        ?string $requestId = null,
        ?array $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
        $this->docUrl = $docUrl;
        $this->requestId = $requestId;
        $this->raw = $raw;
    }
}
