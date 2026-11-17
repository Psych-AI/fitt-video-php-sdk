---
name: video-php-sdk-integration
version: 1
description: >-
  Use when wiring Fitt Video into a host (source) platform's PHP server with
  fitt/video-sdk: composer require fitt/video-sdk, FITT_VIDEO_URL,
  FITT_VIDEO_KEY, FITT_VIDEO_WEBHOOK_SECRETS, Fitt\Video\VideoClient, the
  FittVideo facade, Recipe::answer() / monitor() / conversation() / file(),
  createSession and the descriptor hand-off, sdkScriptUrl(), session(),
  checks(), transcript(), summary(), analyses(), uploadTranscript(),
  requestAnalysis(), finalise(), deleteSession(), playbackUrl(), frame(),
  receiving webhooks with verifyWebhook() (X-Fitt-Signature,
  X-Fitt-Timestamp, X-Fitt-Event-Id, sequence, object_key,
  session.checks.completed, video.ready, webhook.test), rotating a webhook
  secret in two steps, and the
  error classes (ApiException, AuthenticationException, NotFoundException,
  ValidationException, RateLimitedException, TransportException,
  InvalidWebhookSignature).
---

# Fitt Video PHP SDK integration (version 1)

How a host platform's server talks to Fitt Video with `fitt/video-sdk`: it
creates a session with its API key, hands the browser only the descriptor,
reads the results, and acts on signed webhooks. This file ships inside the
package (`skill/SKILL.md`) and is the source; the
`.claude/skills/video-php-sdk-integration/` and
`.agents/skills/video-php-sdk-integration/` copies in the Fitt Video repo are
byte-for-byte mirrors of it. SDK version 1.x, PHP 8.4. The browser side is
the `@fitt/video-sdk` integration skill (`video-sdk-integration`).

## Install

- `composer require fitt/video-sdk`. The package is on Packagist
  (`packagist.org/packages/fitt/video-sdk`, since 1.0.0 on 2026-11-17), so a
  host needs no `repositories` entry. Its source is the public read-only
  repository `Psych-AI/fitt-video-php-sdk`, split from the platform's
  `packages/php-sdk` on each `php-sdk-v*` tag; a host that must pin an
  unreleased commit can add that repository as a VCS source, never a fork.
- It needs a PSR-18 client and PSR-17 factories. With `guzzlehttp/guzzle`
  installed it builds one itself (30 s request, 10 s connect timeout).
- In Laravel the provider and the `FittVideo` facade are discovered. Set:
  - `FITT_VIDEO_URL`: the platform's origin, for example
    `https://video.fitt.ai` (the SDK adds `/api/v1`).
  - `FITT_VIDEO_KEY`: the space's API key, `fvk_<id>.<secret>`. Server only.
  - `FITT_VIDEO_WEBHOOK_SECRETS`: the key's webhook secret; two, comma
    separated, while a key change overlaps.
  - Publish the config with `php artisan vendor:publish --tag=fitt-video-config`
    only if you need to change `webhook_tolerance_s` (300).
- Outside Laravel: `new Fitt\Video\VideoClient($origin, $apiKey,
  webhookSecrets: [$secret])`, or pass your own PSR-18 client and factories.

## Quick reference

| Task | Do this |
|---|---|
| Create a session | `$created = FittVideo::createSession(Recipe::answer()->question('Tell us about a project.')->externalRef($candidateId)->tags(['role-42'])->webhookUrl(route('fitt.webhook')));` It answers `{descriptor, session}` |
| Hand off to the browser | Send only `$created['descriptor']` to the page. The page loads `FittVideo::sdkScriptUrl()` (`{origin}/sdk/v1/fitt-video.js`) or `@fitt/video-sdk`, and calls `FittVideo.session(descriptor)` |
| A new token for the page | `FittVideo::reissueUploadToken($sessionId)` gives `{descriptor}` |
| Read a session | `FittVideo::session($id)`; by your own id: `FittVideo::findByExternalRef($candidateId)` |
| Read the results | `checks($id)` (`verdict`, `reasons`), `transcript($id)` (primary; `transcript($id, 'uploaded')` for yours), `summary($id)`, `analyses($id)` |
| Upload a transcript | `FittVideo::uploadTranscript($id, ['timing' => 'none', 'segments' => [['text' => '...']]])` |
| Ask for an analysis later | `FittVideo::requestAnalysis($id, 'summarise')` (or `translate`, `meaningful`, `transcribe`, `speech`; pass a take to aim at an older one) |
| Close it from the server | `FittVideo::finalise($id)` |
| Let the person review the take | `Recipe::answer()->playbackInStatus()`. **Off by default.** With it, the browser's `session.playback(video)` plays a local copy at once and moves to the processed server file once it is ready. Without it the review stays on the local copy and never switches, and `waitForReady()` rejects `playback_not_enabled`. See "Review right after recording" below |
| Play it back | `FittVideo::playbackUrl($videoId)` gives `{url, expires_at, mime_type}`; the URL expires, so ask again rather than storing it |
| A still frame | `FittVideo::frame($videoId, $tMs)` gives the JPEG bytes |
| Delete | `FittVideo::deleteSession($id)` or `deleteVideo($id)`: the media and the records go |
| Upload a file | `FittVideo::createVideo([...])` gives `{descriptor, video_id, session}` |

## Review right after recording

The `answer` recipe lets the person watch their take straight after stop (SDK 1.3.0). Two things make it work, one on each side:

1. **Your server turns on status playback** when it creates the session: `Recipe::answer()->playbackInStatus()`. It is off by default in every recipe, so the server never hands the browser a playback URL you did not ask for.
2. **The page finalises at stop and calls `session.playback(video)`** (or uses `<fitt-recorder review>`).

| `playbackInStatus()` | What the person sees |
|---|---|
| On | The local copy at once, then the server file once it is ready (switched only while paused or ended) |
| Off (the default) | The local copy only. It never switches to the server file. After a reload there is nothing to play |

A retake after finalise is a new session from your server: call `createSession()` again, with `playbackInStatus()` again. The local copy is only allowed for `answer` takes that fit the instance's byte budget (`descriptor.playback.local`); for other recipes the review is the server file, so `playbackInStatus()` is required there.

## Receive webhooks

The platform POSTs the session's `webhook_url` (from `Recipe::webhookUrl()`, or
the space's default) for nine events: `session.ready`, `session.failed`,
`session.abandoned`, `session.discarded`, `video.ready`, `video.failed`,
`session.checks.completed`, `transcript.ready` and `analysis.completed`. Each
carries `X-Fitt-Event-Id`, `X-Fitt-Timestamp` and `X-Fitt-Signature`
(`sha256=` HMAC-SHA256 of the timestamp, a dot and the raw body). Failed
attempts (any answer but 2xx, or no answer) are retried 1 min, 5 min, 30 min,
2 h and 12 h after each failure, then given up.

**Webhook URLs must be https in production.** Outside `local` and `testing`
the platform refuses an `http` `webhook_url` or space default with a `422`
`ValidationException`, and never sends to one stored before.

```php
// routes/api.php: no session and no CSRF check on this route
Route::post('fitt/webhook', function (Request $request) {
    try {
        $event = FittVideo::verifyWebhook($request); // the raw body, never $request->all()
    } catch (InvalidWebhookSignature $refused) {
        return response()->noContent(401); // $refused->reason says why
    }

    HandleFittEvent::dispatch($event->type, $event->id, $event->data); // queue the work

    return response()->noContent(); // answer 2xx fast
})->name('fitt.webhook');
```

- **Verify on the raw body.** Any re-encoding breaks the signature. Outside
  Laravel, pass a PSR request to `VideoClient::verifyWebhook()`.
- **Answer 2xx fast and queue the work.** The platform waits 5 s; a slow or
  failing answer is retried, so your handler must be idempotent.
- **Route and dedupe only on the verified body's `type` and `id`**
  (`$event->type`, `$event->id`), never on a header. The event id is stable
  across retries.
- **Drop a `sequence` below the last one you accepted for the same session and
  `object_key`.** There is no ordering guarantee. Compare only within one
  object; across objects the sequence decides nothing:

  | Event | `object_key` |
  |---|---|
  | `session.ready`, `session.failed`, `session.abandoned`, `session.discarded` | `session` |
  | `video.ready`, `video.failed` | `video:{video_id}` |
  | `session.checks.completed` | `checks:{take}` |
  | `transcript.ready` | `transcript:{transcript_id}` |
  | `analysis.completed` | `analysis:{analysis_id}` |

- **Expect repeats that are new events.** `video.ready` comes once per track
  (an `answer` session sends one for the camera and one for the audio), and
  again after a re-splice; `session.ready` can follow `session.failed` after a
  staff re-stitch; `analysis.completed` comes for `done`, `failed` and
  `skipped`. Each has a new `id` and a higher `sequence`.
- **Never trust the browser's verdict.** Act on `session.checks.completed`
  (`$event->data['checks']['verdict']`: `accept`, `reject` or `unknown`). A
  `reject` can arrive after the page showed nothing wrong: handle it late. The
  default after a rejected take is `reset()` in the browser, so the candidate
  records again; the platform has no setting that does it for you.
- **The body holds ids, statuses and numbers only.** Read the transcript, the
  summary or a playback URL with the client when you need it.
- **Keys and secrets.** After you move to a new API key, keep the old key's
  webhook secret beside the new one in `FITT_VIDEO_WEBHOOK_SECRETS` for 24 h:
  sessions made with the old key are still signed with its secret, and the
  platform stops sending for a revoked key 24 h after the revoke.
- **Rotating a key's webhook secret** is staged, so no delivery fails. Fitt
  staff stage a new secret and send it to you. Add it beside the current one
  in `FITT_VIDEO_WEBHOOK_SECRETS` (comma separated; the verifier accepts
  either). Tell Fitt it is installed; the platform then promotes it and signs
  with it from that moment. Then remove the old secret.
- **A `webhook.test` event may arrive** when Fitt staff press "Send a test
  event". It is signed like any delivery, with `sequence` 0, no session
  (`session_id`, `take` and `external_ref` null), empty `tags` and
  `object_key` null. Verify it, answer 2xx and do nothing else. It is never
  retried.
- **The timestamp must be within 300 s of your clock**, either way. Keep the
  server's clock in sync.

## The recording page's Content Security Policy

When the Fitt Video deployment uploads straight to the bucket, the browser
PUTs segments to the media host (`cloudfront`, for example
`media.video.fitt.ai`) or to the bucket's host (`s3`, `oss`). A host page with
a Content Security Policy adds that host to `connect-src`, beside the Fitt
Video API host, and corporate network allowlists need it too. Playback URLs
are on the media host as well.

## Errors

| Exception | When | Do this |
|---|---|---|
| `AuthenticationException` (401) | The key is wrong or revoked | Check `FITT_VIDEO_KEY`; do not retry |
| `NotFoundException` (404) | Not there, or in another space | Treat as gone |
| `ValidationException` (422) | A field is not valid | Read `$e->fields`; fix the request |
| `RateLimitedException` (429) | Too many requests | Wait `$e->retryAfter` seconds, then retry |
| `ApiException` (any other status) | For example `409 not_ready` | Match `$e->errorCode`, never the message; `not_ready` means ask again later |
| `ApiException` `invalid_response` | The answer was not a JSON object | Retry later; report it if it persists |
| `TransportException` | No answer (network, timeout) | Retry with backoff |
| `InvalidWebhookSignature` | A webhook did not verify | Answer 401 and log `$e->reason` (`signature_mismatch`, `timestamp_out_of_tolerance`, `header_mismatch`, `missing_header`, `malformed_timestamp`, `malformed_body`) |

All extend `Fitt\Video\Exceptions\FittVideoException`. Match on
`$e->errorCode` (the envelope's `error.code`), not `getCode()`.

## Never

- Send the API key, or `frameUrl()`, to a browser: `frameUrl()` is an API URL
  that needs the key. Use `frame()` on your server, or a playback URL.
- Act on a webhook you did not verify, or on its headers alone.
- Store a playback URL: it expires.
- Log a webhook secret or the API key.
