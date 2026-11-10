<?php

namespace Fitt\Video\Laravel;

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
 * an `Http::fake()` registered after it. Building it on `Http::buildClient()`
 * also gives the SDK the host's global Laravel HTTP middleware and
 * `Http::preventStrayRequests()`, so a test can never reach the network
 * through it.
 */
class FittVideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/fitt-video.php', 'fitt-video');

        $this->app->bind(LaravelVideoClient::class, fn (): LaravelVideoClient => new LaravelVideoClient(
            baseUrl: (string) config('fitt-video.url'),
            apiKey: (string) config('fitt-video.key'),
            client: Http::buildClient(),
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
