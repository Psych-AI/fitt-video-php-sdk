# Changelog

## Unreleased

- `Recipe::audioOnly()` for `answer` and `conversation` (Plan 10). The version bump happens on `main` after merge (CLAUDE.md, PHP SDK release steps).

## 1.0.0 (2026-11-09)

The first release.

- `Fitt\Video\VideoClient` on any PSR-18 client and PSR-17 factories, with
  Guzzle built when none is passed (30 s request, 10 s connect timeout). The
  base URL is the platform's origin; the client adds `/api/v1`. Methods:
  `me()`, `createSession()`, `createVideo()`, `reissueUploadToken()`,
  `session()`, `updateSession()`, `video()`, `findByExternalRef()`,
  `playbackUrl()` (`{url, expires_at, mime_type}`), `frame()` (JPEG bytes),
  `frameUrl()` (server use only), `transcript()`, `summary()`, `checks()`,
  `analyses()`, `events()`, `uploadTranscript()`, `requestAnalysis()`,
  `finalise()`, `deleteSession()`, `deleteVideo()`, `sdkScriptUrl()` and
  `verifyWebhook()` (a PSR request).
- `Fitt\Video\Recipe`: an immutable builder for `createSession()`
  (`answer()`, `monitor()`, `conversation()`, `file()`, and setters for the
  question, the context, languages, the external ref, tags, metadata, the
  webhook URL, playback in status, options, `expose_reason` and `reject_on`).
- Typed errors on the platform's one error envelope: `ApiException` (with
  `$status`, `$errorCode` and `$fields`), `AuthenticationException` (401),
  `NotFoundException` (404), `ValidationException` (422, with `$fields`),
  `RateLimitedException` (429, with `$retryAfter`), `TransportException` (no
  answer), and `ApiException` `invalid_response` for an answer that is not a
  JSON object or list.
- `Fitt\Video\Webhooks\WebhookVerifier` and `WebhookEvent`: the signature
  (`sha256=` HMAC-SHA256 of the timestamp, a dot and the raw body), a 300 s
  tolerance either way, two secrets during an overlap, an empty secret never
  used as a key, and the `X-Fitt-Event-Id` header checked against the body's
  `id`. Refusals throw `InvalidWebhookSignature` with a `$reason`.
- The Laravel layer: `FittVideoServiceProvider` (auto-discovered),
  `config/fitt-video.php` (`FITT_VIDEO_URL`, `FITT_VIDEO_KEY`,
  `FITT_VIDEO_WEBHOOK_SECRETS`), and the `FittVideo` facade over
  `LaravelVideoClient`, whose `verifyWebhook()` also takes an
  `Illuminate\Http\Request` and verifies on Laravel's clock. Its client is
  built on Laravel's HTTP handler stack on every call, so `Http::fake()` and
  `Http::preventStrayRequests()` reach it.
- Secret parameters are marked `#[\SensitiveParameter]`.
- `skill/SKILL.md`, the integration guide.
