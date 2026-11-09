<?php

use Fitt\Video\Exceptions\InvalidWebhookSignature;
use Fitt\Video\Webhooks\WebhookVerifier;

/** @return array{secret: string, timestamp: string, body: string, signature: string} */
function webhookVector(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/vectors/webhook-signature.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The verifier's refusal reason for the vector with some fields replaced, or
 * null when it verifies. "now" is 2026-11-09T10:00:00Z unless given.
 *
 * @param  array<string, mixed>  $overrides
 */
function verifierRefusal(array $overrides = [], int $now = 1794218400): ?string
{
    $vector = [...webhookVector(), ...$overrides];

    try {
        (new WebhookVerifier)->verify($vector['body'], $vector['timestamp'], $vector['signature'], $vector['secret'], now: fn (): int => $now, eventId: $vector['event_id'] ?? null);

        return null;
    } catch (InvalidWebhookSignature $refused) {
        return $refused->reason;
    }
}

test('the shared vector verifies and gives the event', function () {
    $vector = webhookVector();
    $event = (new WebhookVerifier)->verify($vector['body'], $vector['timestamp'], $vector['signature'], $vector['secret'], now: fn (): int => 1794218400);

    expect('sha256='.hash_hmac('sha256', $vector['timestamp'].'.'.$vector['body'], $vector['secret']))->toBe($vector['signature'])
        ->and($event->id)->toBe('01kfy0a2b3c4d5e6f7g8h9j0km')
        ->and($event->type)->toBe('session.discarded')
        ->and($event->sequence)->toBe(1)
        ->and($event->take)->toBeNull()
        ->and($event->tags)->toBe([])
        ->and($event->objectKey)->toBeNull()
        ->and($event->data)->toBe(['id' => '01kfy0a2b3c4d5e6f7g8h9j0km', 'type' => 'session.discarded', 'sequence' => 1]);
});

test('the tolerance is 300 s each way', function (int $skew, ?string $reason) {
    expect(verifierRefusal(now: 1794218400 + $skew))->toBe($reason);
})->with([
    'now 300 s after' => [300, null],
    'now 301 s after' => [301, 'timestamp_out_of_tolerance'],
    'now 300 s before' => [-300, null],
    'now 301 s before' => [-301, 'timestamp_out_of_tolerance'],
]);

test('a signature without the sha256= prefix is refused', function () {
    expect(verifierRefusal(['signature' => substr(webhookVector()['signature'], 7)]))->toBe('signature_mismatch');
});

test('one changed body byte is refused', function () {
    expect(verifierRefusal(['body' => str_replace('"sequence":1', '"sequence":2', webhookVector()['body'])]))->toBe('signature_mismatch');
});

test('either of two secrets verifies, so a rotation can overlap', function () {
    $vector = webhookVector();
    $new = str_repeat('n', 48);
    $signed = 'sha256='.hash_hmac('sha256', $vector['timestamp'].'.'.$vector['body'], $new);

    expect(verifierRefusal(['signature' => $signed, 'secret' => [$vector['secret'], $new]]))->toBeNull()
        ->and(verifierRefusal(['signature' => $signed, 'secret' => [$vector['secret']]]))->toBe('signature_mismatch')
        ->and(verifierRefusal(['secret' => []]))->toBe('signature_mismatch');
});

test('an empty secret never verifies', function () {
    $vector = webhookVector();
    $emptyKeyed = 'sha256='.hash_hmac('sha256', $vector['timestamp'].'.'.$vector['body'], '');

    expect(verifierRefusal(['signature' => $emptyKeyed, 'secret' => '']))->toBe('signature_mismatch')
        ->and(verifierRefusal(['signature' => $emptyKeyed, 'secret' => ['', 'x']]))->toBe('signature_mismatch');
});

test('an X-Fitt-Event-Id that is not the body id is refused, whatever its case', function (string $header, ?string $reason) {
    expect(verifierRefusal(['event_id' => $header]))->toBe($reason);
})->with([
    'the body id' => ['01kfy0a2b3c4d5e6f7g8h9j0km', null],
    'the body id in upper case' => ['01KFY0A2B3C4D5E6F7G8H9J0KM', null],
    'another id' => ['01kfy0a2b3c4d5e6f7g8h9j0kn', 'header_mismatch'],
    'empty, as an absent header' => ['', null],
]);

test('a missing header is refused before anything else', function (string $header) {
    expect(verifierRefusal([$header => null]))->toBe('missing_header');
})->with(['timestamp', 'signature']);

test('a timestamp that is not plain unix seconds is refused', function (string $timestamp) {
    expect(verifierRefusal(['timestamp' => $timestamp]))->toBe('malformed_timestamp');
})->with(['1e9', '-1794218400', "1794218400\n", '1794218400.5', '']);

test('a bad signature on a stale timestamp reports the signature, not the age', function () {
    expect(verifierRefusal(['signature' => 'sha256='.str_repeat('0', 64)], now: 1794218400 + 10_000))->toBe('signature_mismatch');
});

test('a signed body that is not an event is refused after the signature', function (string $body) {
    $vector = webhookVector();

    expect(verifierRefusal(['body' => $body, 'signature' => 'sha256='.hash_hmac('sha256', $vector['timestamp'].'.'.$body, $vector['secret'])]))->toBe('malformed_body');
})->with(['[]', 'not json', '{"id":"x","type":"session.ready"}', '{"id":1,"type":"session.ready","sequence":1}']);

test('a refused webhook never carries the secrets in its trace', function () {
    $previous = ini_set('zend.exception_ignore_args', '0'); // Sail sets it to 1; many host images leave it off

    try {
        try {
            (new WebhookVerifier)->verify('{}', (string) time(), 'sha256=00', ['the-vector-secret']);
            $this->fail('A bad signature verified.');
        } catch (InvalidWebhookSignature $refused) {
            $frame = collect($refused->getTrace())->firstWhere('function', 'verify');

            expect($frame['args'][3])->toBeInstanceOf(SensitiveParameterValue::class)
                ->and($refused->getTraceAsString())->not->toContain(substr('the-vector-secret', 0, 15));
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});
