<?php

namespace Fitt\Video\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

/**
 * The SDK's Laravel layer: the `fitt-video` config and the client the
 * `FittVideo` facade resolves.
 *
 * The client is bound, not a singleton, and is built again on every resolve.
 * A PendingRequest copies the factory's fakes when it is created
 * (`Illuminate\Http\Client\Factory`), so a client built once would never see
 * an `Http::fake()` registered after it. Building it on Laravel's handler
 * stack (`Http::buildHandlerStack()`, the stack `Http::buildClient()` uses)
 * also gives the SDK the host's global Laravel HTTP middleware and
 * `Http::preventStrayRequests()`, so a test can never reach the network
 * through it.
 *
 * The client is not `Http::buildClient()` itself: that Guzzle client carries
 * no timeout, and Laravel's 30 s and 10 s defaults are applied only in
 * `PendingRequest::send()`, never on a PSR-18 `sendRequest()`. So the client
 * sets the SDK's own default limits, or a stalled platform would hold a host
 * worker with no limit.
 */
class FittVideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fitt-video.php', 'fitt-video');

        $this->app->bind(LaravelVideoClient::class, fn (): LaravelVideoClient => new LaravelVideoClient(
            baseUrl: (string) config('fitt-video.url'),
            apiKey: (string) config('fitt-video.key'),
            client: new Client([
                'handler' => Http::buildHandlerStack(),
                'timeout' => 30,
                'connect_timeout' => 10,
            ]),
            requestFactory: new HttpFactory,
            streamFactory: new HttpFactory,
            webhookSecrets: (array) config('fitt-video.webhook_secrets'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/fitt-video.php' => config_path('fitt-video.php'),
        ], 'fitt-video-config');
    }
}
