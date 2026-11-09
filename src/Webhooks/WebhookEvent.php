<?php

namespace Fitt\Video\Webhooks;

use Fitt\Video\Exceptions\InvalidWebhookSignature;

/**
 * A verified webhook body. Hosts route and dedupe on `$type` and `$id`, and drop a
 * `$sequence` below the last one they accepted for the same `$objectKey`.
 * `$data` is the whole decoded body, including the object it carries.
 */
final readonly class WebhookEvent
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public int $sequence,
        public ?int $take,
        public ?string $sessionId,
        public ?string $spaceId,
        public ?string $externalRef,
        public array $tags,
        public ?string $objectKey,
        public array $data,
    ) {}

    /**
     * Builds the event from a decoded body, refusing one that is not an event.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws InvalidWebhookSignature with reason `malformed_body`
     */
    public static function fromArray(array $body): self
    {
        if (! is_string($body['id'] ?? null) || ! is_string($body['type'] ?? null) || ! is_int($body['sequence'] ?? null)) {
            throw new InvalidWebhookSignature('malformed_body');
        }

        $tags = is_array($body['tags'] ?? null)
            ? array_values(array_filter($body['tags'], fn (mixed $tag): bool => is_string($tag)))
            : [];

        return new self(
            id: $body['id'],
            type: $body['type'],
            sequence: $body['sequence'],
            take: is_int($body['take'] ?? null) ? $body['take'] : null,
            sessionId: self::stringOrNull($body, 'session_id'),
            spaceId: self::stringOrNull($body, 'space_id'),
            externalRef: self::stringOrNull($body, 'external_ref'),
            tags: $tags,
            objectKey: self::stringOrNull($body, 'object_key'),
            data: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function stringOrNull(array $body, string $key): ?string
    {
        return is_string($body[$key] ?? null) ? $body[$key] : null;
    }
}
