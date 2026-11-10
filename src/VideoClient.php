<?php

namespace Fitt\Video;

use Fitt\Video\Exceptions\ApiException;
use Fitt\Video\Exceptions\TransportException;
use Fitt\Video\Webhooks\WebhookEvent;
use Fitt\Video\Webhooks\WebhookVerifier;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The Fitt Video API v1 client (spec 9.4), on any PSR-18 client and PSR-17
 * factories. `$baseUrl` is the platform's origin (`https://video.fitt.ai`); the
 * client adds `/api/v1` (author's call 13). With no client or factories passed it
 * builds Guzzle when Guzzle is installed.
 *
 * Every method answers the decoded JSON body unless its docblock says otherwise,
 * and throws an `ApiException` subclass for a status of 400 or more, an
 * `ApiException` with `errorCode` `invalid_response` for a body that is not a
 * JSON object or list, or a `TransportException` when no answer came. `frameUrl()` is the API URL, which
 * needs the API key: it is for server use, never for a browser.
 */
class VideoClient
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly ClientInterface $client;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    protected readonly array $webhookSecrets;

    /**
     * @param  list<string>  $webhookSecrets  the key's webhook secret, or two while a rotation overlaps
     *
     * @throws \LogicException when no client or factory is passed and Guzzle is not installed
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $apiKey,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        #[\SensitiveParameter] array $webhookSecrets = [],
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->webhookSecrets = array_values($webhookSecrets);
        $this->client = $client ?? self::defaultClient();
        $this->requestFactory = $requestFactory ?? self::defaultFactory();
        $this->streamFactory = $streamFactory ?? self::defaultFactory();
    }

    /** The key's space and the key itself. */
    public function me(): array
    {
        return $this->json($this->send('GET', 'me'));
    }

    /**
     * `{descriptor, session}`: the descriptor goes to the browser, the session stays here.
     *
     * @param  array<string, mixed>|Recipe  $fields
     */
    public function createSession(array|Recipe $fields): array
    {
        return $this->json($this->send('POST', 'sessions', body: $fields instanceof Recipe ? $fields->toArray() : $fields));
    }

    /**
     * `{descriptor, video_id, session}` for a file upload.
     *
     * @param  array<string, mixed>  $fields
     */
    public function createVideo(array $fields): array
    {
        return $this->json($this->send('POST', 'videos', body: $fields));
    }

    /** `{descriptor}` with a fresh upload token for the session. */
    public function reissueUploadToken(string $sessionId): array
    {
        return $this->json($this->send('POST', 'sessions/'.rawurlencode($sessionId).'/upload-token'));
    }

    public function session(string $id): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($id)));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function updateSession(string $id, array $fields): array
    {
        return $this->json($this->send('PATCH', 'sessions/'.rawurlencode($id), body: $fields));
    }

    public function video(string $id): array
    {
        return $this->json($this->send('GET', 'videos/'.rawurlencode($id)));
    }

    /**
     * The sessions (or, with `$videos`, the videos) whose external ref is exactly this one.
     *
     * @return list<array<string, mixed>>
     */
    public function findByExternalRef(string $externalRef, bool $videos = false): array
    {
        return $this->json($this->send('GET', $videos ? 'videos' : 'sessions', ['external_ref' => $externalRef]))['data'] ?? [];
    }

    /**
     * The signed playback URL with its expiry and type, because the URL expires.
     *
     * @return array{url: string, expires_at: string, mime_type: string}
     */
    public function playbackUrl(string $videoId): array
    {
        return $this->json($this->send('GET', 'videos/'.rawurlencode($videoId).'/playback'));
    }

    /** The JPEG bytes of the frame at `$tMs`. */
    public function frame(string $videoId, int $tMs): string
    {
        return (string) $this->send('GET', 'videos/'.rawurlencode($videoId).'/frame', ['t_ms' => $tMs])->getBody();
    }

    /** The frame's API URL. It needs the API key, so it is for server use only. */
    public function frameUrl(string $videoId, int $tMs): string
    {
        return $this->url('videos/'.rawurlencode($videoId).'/frame', ['t_ms' => $tMs]);
    }

    /**
     * @param  'platform'|'uploaded'|null  $source
     */
    public function transcript(string $sessionId, ?string $source = null): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($sessionId).'/transcript', $source === null ? [] : ['source' => $source]));
    }

    public function summary(string $sessionId): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($sessionId).'/summary'));
    }

    public function checks(string $sessionId): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($sessionId).'/checks'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function analyses(string $sessionId): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($sessionId).'/analyses'))['data'] ?? [];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    public function events(string $sessionId, array $query = []): array
    {
        return $this->json($this->send('GET', 'sessions/'.rawurlencode($sessionId).'/events', $query))['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $transcript
     */
    public function uploadTranscript(string $sessionId, array $transcript): array
    {
        return $this->json($this->send('POST', 'sessions/'.rawurlencode($sessionId).'/transcript', body: $transcript));
    }

    /** `{id, type, status}` of the queued analysis. */
    public function requestAnalysis(string $sessionId, string $type, ?int $take = null): array
    {
        $body = $take === null ? ['type' => $type] : ['type' => $type, 'take' => $take];

        return $this->json($this->send('POST', 'sessions/'.rawurlencode($sessionId).'/analyses', body: $body));
    }

    /** The session resource, once the session is closed for upload. */
    public function finalise(string $sessionId): array
    {
        return $this->json($this->send('POST', 'sessions/'.rawurlencode($sessionId).'/finalise'));
    }

    public function deleteSession(string $id): void
    {
        $this->send('DELETE', 'sessions/'.rawurlencode($id));
    }

    public function deleteVideo(string $id): void
    {
        $this->send('DELETE', 'videos/'.rawurlencode($id));
    }

    /** The hosted browser SDK script, `{origin}/sdk/{version}/fitt-video.js`. */
    public function sdkScriptUrl(string $version = 'v1'): string
    {
        return $this->baseUrl.'/sdk/'.rawurlencode($version).'/fitt-video.js';
    }

    /**
     * Verifies a webhook delivery against the configured secrets. A request whose
     * `X-Fitt-Event-Id` is not the body's `id` is refused `header_mismatch`.
     *
     * @throws Exceptions\InvalidWebhookSignature
     */
    public function verifyWebhook(RequestInterface $request): WebhookEvent
    {
        return (new WebhookVerifier)->verify(
            (string) $request->getBody(),
            $request->getHeaderLine('X-Fitt-Timestamp') ?: null,
            $request->getHeaderLine('X-Fitt-Signature') ?: null,
            $this->webhookSecrets,
            eventId: $request->getHeaderLine('X-Fitt-Event-Id') ?: null,
        );
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     *
     * @throws ApiException for a status of 400 or more
     * @throws TransportException when the client throws
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $this->url($path, $query))
            ->withHeader('Authorization', 'Bearer '.$this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'fitt-video-php/'.Version::VERSION);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body === [] ? new \stdClass : $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), previous: $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw ApiException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = $this->baseUrl.'/api/v1/'.$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<mixed>
     */
    /**
     * The decoded body: `[]` for an empty one.
     *
     * @throws ApiException `invalid_response` for a body that is not a JSON object or list
     */
    private function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalidResponse($response, $e);
        }

        if (! is_array($decoded)) {
            throw self::invalidResponse($response);
        }

        return $decoded;
    }

    private static function invalidResponse(ResponseInterface $response, ?\Throwable $previous = null): ApiException
    {
        $status = $response->getStatusCode();

        return new ApiException("The API answered {$status} with a body that is not a JSON object.", $status, 'invalid_response', previous: $previous);
    }

    private static function defaultClient(): ClientInterface
    {
        if (! class_exists(Client::class)) {
            throw self::missingHttp();
        }

        return new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    private static function defaultFactory(): RequestFactoryInterface&StreamFactoryInterface
    {
        if (! class_exists(HttpFactory::class)) {
            throw self::missingHttp();
        }

        return new HttpFactory;
    }

    private static function missingHttp(): \LogicException
    {
        return new \LogicException('Pass a PSR-18 client and PSR-17 factories, or install guzzlehttp/guzzle.');
    }
}
