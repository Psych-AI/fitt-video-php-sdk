<?php

namespace Fitt\Video\Exceptions;

/**
 * A webhook delivery that did not verify. `$reason` is one of `missing_header`,
 * `malformed_timestamp`, `signature_mismatch`, `timestamp_out_of_tolerance`,
 * `malformed_body` or `header_mismatch`. The message never carries a secret.
 */
final class InvalidWebhookSignature extends FittVideoException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The webhook was refused: {$reason}.");
    }
}
