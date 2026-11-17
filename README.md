# fitt/video-sdk

The PHP SDK for Fitt Video. A host platform's server uses it to create
recording sessions from recipes, hand the browser a descriptor, read the
results (checks, transcripts, summaries, analyses, playback) and verify the
signed webhooks the platform sends. The core is framework-agnostic, on PSR-18
and PSR-17; a thin Laravel layer adds a config file and the `FittVideo` facade.

## Install

```bash
composer require fitt/video-sdk
```

The package is on Packagist (<https://packagist.org/packages/fitt/video-sdk>), so no
`repositories` entry is needed. Its source is the read-only repository
<https://github.com/Psych-AI/fitt-video-php-sdk>, split from the platform's `packages/php-sdk`
on each release; changes and issues belong in the platform repository.

Set `FITT_VIDEO_URL` (the platform's origin), `FITT_VIDEO_KEY` (the space's
API key, server only) and `FITT_VIDEO_WEBHOOK_SECRETS` (the key's webhook
secret, comma separated while a key change overlaps). Outside Laravel, pass
them to `new Fitt\Video\VideoClient(...)`. PHP 8.4.

## Example

```php
use Fitt\Video\Laravel\FittVideo;
use Fitt\Video\Recipe;

// playbackInStatus() lets the page review the take right after stop (off by default)
$created = FittVideo::createSession(Recipe::answer()->question('Tell us about a project.')->externalRef('cand-42')->playbackInStatus());
$descriptor = $created['descriptor']; // send only this to the browser

Route::post('fitt/webhook', function (Illuminate\Http\Request $request) {
    try {
        $event = FittVideo::verifyWebhook($request);
    } catch (Fitt\Video\Exceptions\InvalidWebhookSignature) {
        return response()->noContent(401);
    }

    HandleFittEvent::dispatch($event->type, $event->id, $event->data);

    return response()->noContent();
});
```

## Recipes

`Recipe::answer()`, `Recipe::monitor()`, `Recipe::conversation()` and
`Recipe::file()` build the body of `createSession()`. Each setter returns a
new recipe.

- `audioOnly()` records one audio track and no camera (64 kbps; mono for
  `answer`, stereo for `conversation`). That track is the playable file and
  the checks input:

  ```php
  $client->createSession(Recipe::answer()->audioOnly()->question('Why Fitt?'));
  ```

  It is for `answer` and `conversation` only: on `monitor` and `file` it
  throws a `LogicException`.
- The session overrides (1.2.0): `signals($groups)` picks the signal groups
  the browser records in place of the recipe's defaults (the descriptor lists
  exactly those); `recording([...])` the timings in milliseconds;
  `capture('camera', [...])` or `capture('audio', [...])` the bitrates, size
  and channels; `enforce([...])` the browser rules; `sessionCap($ms)` a cap
  below the space's. `signals()` throws an `InvalidArgumentException` on a
  name outside `Recipe::SIGNAL_GROUPS` before any request; the others are
  checked by the platform (422 `config_invalid`, `cap_exceeds_space`):

  ```php
  Recipe::conversation()->signals(['attention', 'window', 'fullscreen', 'device', 'net', 'recorder']);
  ```

## Frames

`frame($videoId, $tMs)` gives the JPEG bytes of the camera track at session
time `$tMs`. An audio track has no frames: the platform answers 409 `no_video`, an `ApiException` with `errorCode` `no_video`.

## More

The full integration guide, for people and agents, is
[`skill/SKILL.md`](skill/SKILL.md): recipes, the descriptor hand-off, results,
the webhook rules (dedupe on `type` and `id`, the sequence per `object_key`)
and the error classes. The changes are in [`CHANGELOG.md`](CHANGELOG.md).
