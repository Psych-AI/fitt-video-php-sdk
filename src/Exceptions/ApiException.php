<?php

namespace Fitt\Video\Exceptions;

use Psr\Http\Message\ResponseInterface;

/**
 * The API answered a status of 400 or more, or a body that is not a JSON
 * object or list (`invalid_response`, with the status it came with).
 * `$errorCode` is the envelope's `error.code` (`http_error` when the body
 * carries no envelope), `$status` the HTTP status and `$fields` the envelope's
 * `error.fields`, when given.
 */
class ApiException extends FittVideoException
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $fields = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Maps an error answer to its typed exception: 401, 404, 422 and 429 have
     * their own class, every other status is an ApiException.
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'http_error';
        $message = is_string($error['message'] ?? null) ? $error['message'] : "The API answered {$status}.";

        return match ($status) {
            401 => new AuthenticationException($message, $status, $code),
            404 => new NotFoundException($message, $status, $code),
            422 => new ValidationException($message, $status, $code, fields: is_array($error['fields'] ?? null) ? $error['fields'] : []),
            429 => new RateLimitedException($message, $status, $code, retryAfter: self::retryAfter($response, $error)),
            default => new self($message, $status, $code),
        };
    }

    /**
     * The `Retry-After` header when it is whole seconds, else the body's `retry_after`.
     *
     * @param  array<string, mixed>  $error
     */
    private static function retryAfter(ResponseInterface $response, array $error): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        return is_int($error['retry_after'] ?? null) ? $error['retry_after'] : null;
    }
}
