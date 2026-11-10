<?php

namespace Fitt\Video\Laravel;

use Fitt\Video\Exceptions\InvalidWebhookSignature;
use Fitt\Video\VideoClient;
use Fitt\Video\Webhooks\WebhookEvent;
use Fitt\Video\Webhooks\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;

/**
 * The client the `FittVideo` facade resolves. It also verifies a webhook
 * straight from a Laravel request, with the configured tolerance and
 * Laravel's clock, so `travelTo()` in a host's tests moves it.
 */
class LaravelVideoClient extends VideoClient
{
    /**
     * Verifies a webhook delivery. A Laravel request is read from its raw
     * content and headers; a PSR request goes to the parent. A request whose
     * `X-Fitt-Event-Id` is not the body's `id` is refused `header_mismatch`.
     *
     * @throws InvalidWebhookSignature
     */
    public function verifyWebhook(RequestInterface|Request $request): WebhookEvent
    {
        if ($request instanceof RequestInterface) {
            return parent::verifyWebhook($request);
        }

        return (new WebhookVerifier)->verify(
            $request->getContent(),
            $request->header('X-Fitt-Timestamp'),
            $request->header('X-Fitt-Signature'),
            $this->webhookSecrets,
            toleranceS: (int) config('fitt-video.webhook_tolerance_s'),
            now: fn (): int => Carbon::now()->getTimestamp(),
            eventId: $request->header('X-Fitt-Event-Id'),
        );
    }
}
