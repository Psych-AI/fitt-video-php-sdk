<?php

namespace Fitt\Video\Webhooks;

use Closure;
use Fitt\Video\Exceptions\InvalidWebhookSignature;

/**
 * Verifies a Fitt Video webhook delivery: `X-Fitt-Signature` is `sha256=<hex>` of
 * HMAC-SHA256(secret, timestamp + '.' + raw body), the scheme the platform's
 * `App\Video\Webhooks\WebhookSigner` signs with.
 */
final class WebhookVerifier
{
    /**
     * Checks, in this order: both headers are present; the timestamp is plain unix
     * seconds; the signature matches one of the secrets (every secret is compared,
     * with `hash_equals`); the timestamp is within the tolerance either way; the body
     * is an event; and the `X-Fitt-Event-Id` header, when given, is the body's `id`.
     *
     * The signature comes before the age, so a stale forgery reports
     * `signature_mismatch`, and only a genuine but late delivery reports
     * `timestamp_out_of_tolerance`. Pass two secrets while a rotation overlaps.
     * An empty secret is never used as a key.
     *
     * @param  string|list<string>  $secrets
     * @param  (Closure(): int)|null  $now  unix seconds, `time()` when null
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(
        string $rawBody,
        ?string $timestamp,
        ?string $signature,
        #[\SensitiveParameter] string|array $secrets,
        int $toleranceS = 300,
        ?Closure $now = null,
        ?string $eventId = null,
    ): WebhookEvent {
        if ($timestamp === null || $signature === null) {
            throw new InvalidWebhookSignature('missing_header');
        }

        // The timestamp is never trimmed, and `D` stops `$` from matching before a newline.
        if (preg_match('/^\d{1,12}$/D', $timestamp) !== 1) {
            throw new InvalidWebhookSignature('malformed_timestamp');
        }

        $given = strtolower(trim($signature));

        // An empty secret would let anyone sign, so it is never used as a key.
        $secrets = array_values(array_filter(array_map('strval', (array) $secrets), fn (string $secret): bool => $secret !== ''));

        $matched = false;

        foreach ($secrets as $secret) {
            // Every secret is compared, so the time taken does not say which one matched.
            $matched = hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $given) || $matched;
        }

        if (! $matched) {
            throw new InvalidWebhookSignature('signature_mismatch');
        }

        $now = $now === null ? time() : (int) $now();

        if (abs($now - (int) $timestamp) > $toleranceS) {
            throw new InvalidWebhookSignature('timestamp_out_of_tolerance');
        }

        try {
            $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidWebhookSignature('malformed_body');
        }

        if (! is_array($body) || array_is_list($body)) {
            throw new InvalidWebhookSignature('malformed_body');
        }

        $event = WebhookEvent::fromArray($body);

        // The id header is outside the signed string: a replay with a fresh id must not pass a host's dedupe.
        if ($eventId !== null && $eventId !== '' && strtolower($eventId) !== strtolower($event->id)) {
            throw new InvalidWebhookSignature('header_mismatch');
        }

        return $event;
    }
}
