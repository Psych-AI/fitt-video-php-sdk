<?php

namespace Fitt\Video\Exceptions;

/**
 * The API answered 429 (`rate_limited`). `$retryAfter` is the wait in seconds:
 * the `Retry-After` header, else the body's `retry_after`, else null.
 */
final class RateLimitedException extends ApiException
{
    public function __construct(
        string $message,
        int $status,
        string $errorCode,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, [], $previous);
    }
}
