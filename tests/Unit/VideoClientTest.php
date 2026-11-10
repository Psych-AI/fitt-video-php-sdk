<?php

use Fitt\Video\Exceptions\ApiException;
use Fitt\Video\Exceptions\AuthenticationException;
use Fitt\Video\Exceptions\InvalidWebhookSignature;
use Fitt\Video\Exceptions\NotFoundException;
use Fitt\Video\Exceptions\RateLimitedException;
use Fitt\Video\Exceptions\TransportException;
use Fitt\Video\Exceptions\ValidationException;
use Fitt\Video\Recipe;
use Fitt\Video\Tests\Support\RecordingClient;
use Fitt\Video\VideoClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @param  list<ResponseInterface>  $responses
 * @return array{0: VideoClient, 1: RecordingClient}
 */
function recordingSdk(array $responses = []): array
{
    $http = new RecordingClient($responses);

    return [new VideoClient('https://video.test', 'fvk_01kfy0a2b3c4d5e6f7g8h9j0km.secret', $http, new HttpFactory, new HttpFactory), $http];
}

function caughtApiError(Closure $call): ApiException
{
    try {
        $call();
    } catch (ApiException $error) {
        return $error;
    }

    throw new RuntimeException('Expected an ApiException.');
}

test('createSession posts the recipe with the key, as JSON, with the SDK version', function () {
    [$sdk, $http] = recordingSdk([new Response(201, [], '{"descriptor":{"session_id":"s1"},"session":{"id":"s1"}}')]);

    $created = $sdk->createSession(Recipe::answer()->question('Why?'));
    $request = $http->requests[0];

    expect($created['session']['id'])->toBe('s1')
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://video.test/api/v1/sessions')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer fvk_01kfy0a2b3c4d5e6f7g8h9j0km.secret')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('User-Agent'))->toBe('fitt-video-php/1.0.0')
        ->and((string) $request->getBody())->toBe('{"recipe":"answer","context":{"question":"Why?"}}');
});

test('each call goes to its route', function (string $call, array $args, string $method, string $url) {
    [$sdk, $http] = recordingSdk();

    $sdk->{$call}(...$args);

    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]->getMethod())->toBe($method)
        ->and((string) $http->requests[0]->getUri())->toBe($url);
})->with([
    'me' => ['me', [], 'GET', 'https://video.test/api/v1/me'],
    'createVideo' => ['createVideo', [['external_ref' => 'f1']], 'POST', 'https://video.test/api/v1/videos'],
    'reissueUploadToken' => ['reissueUploadToken', ['s1'], 'POST', 'https://video.test/api/v1/sessions/s1/upload-token'],
    'session' => ['session', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1'],
    'updateSession' => ['updateSession', ['s1', ['external_ref' => 'x']], 'PATCH', 'https://video.test/api/v1/sessions/s1'],
    'video' => ['video', ['v1'], 'GET', 'https://video.test/api/v1/videos/v1'],
    'findByExternalRef' => ['findByExternalRef', ['ext 1/a'], 'GET', 'https://video.test/api/v1/sessions?external_ref=ext%201%2Fa'],
    'findByExternalRef for videos' => ['findByExternalRef', ['ext-1', true], 'GET', 'https://video.test/api/v1/videos?external_ref=ext-1'],
    'playbackUrl' => ['playbackUrl', ['v1'], 'GET', 'https://video.test/api/v1/videos/v1/playback'],
    'frame' => ['frame', ['v1', 2500], 'GET', 'https://video.test/api/v1/videos/v1/frame?t_ms=2500'],
    'transcript' => ['transcript', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1/transcript'],
    'transcript by source' => ['transcript', ['s1', 'uploaded'], 'GET', 'https://video.test/api/v1/sessions/s1/transcript?source=uploaded'],
    'summary' => ['summary', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1/summary'],
    'checks' => ['checks', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1/checks'],
    'analyses' => ['analyses', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1/analyses'],
    'events' => ['events', ['s1'], 'GET', 'https://video.test/api/v1/sessions/s1/events'],
    'uploadTranscript' => ['uploadTranscript', ['s1', ['timing' => 'none', 'segments' => [['text' => 'Hi']]]], 'POST', 'https://video.test/api/v1/sessions/s1/transcript'],
    'requestAnalysis' => ['requestAnalysis', ['s1', 'summarise'], 'POST', 'https://video.test/api/v1/sessions/s1/analyses'],
    'finalise' => ['finalise', ['s1'], 'POST', 'https://video.test/api/v1/sessions/s1/finalise'],
    'deleteSession' => ['deleteSession', ['s1'], 'DELETE', 'https://video.test/api/v1/sessions/s1'],
    'deleteVideo' => ['deleteVideo', ['v1'], 'DELETE', 'https://video.test/api/v1/videos/v1'],
]);

test('the lists answer their data, a frame answers its bytes, and an analysis request sends its type', function () {
    [$sdk, $http] = recordingSdk([
        new Response(200, [], '{"data":[{"id":"s1"}],"next_cursor":null}'),
        new Response(200, ['Content-Type' => 'image/jpeg'], 'JPEG'),
        new Response(202, [], '{"id":"a1","type":"summarise","status":"pending"}'),
    ]);

    expect($sdk->findByExternalRef('ext-1'))->toBe([['id' => 's1']])
        ->and($sdk->frame('v1', 0))->toBe('JPEG')
        ->and($sdk->requestAnalysis('s1', 'summarise')['status'])->toBe('pending')
        ->and((string) $http->requests[2]->getBody())->toBe('{"type":"summarise"}');
});

test('playbackUrl returns the signed URL with its expiry and type', function () {
    [$sdk] = recordingSdk([new Response(200, ['Content-Type' => 'application/json'], '{"url":"https://media.test/v1.webm?sig=x","expires_at":"2026-11-09T12:00:00+00:00","mime_type":"video/webm"}')]);

    expect($sdk->playbackUrl('v1'))->toBe(['url' => 'https://media.test/v1.webm?sig=x', 'expires_at' => '2026-11-09T12:00:00+00:00', 'mime_type' => 'video/webm']);
});

test('the URLs a host builds without a request', function () {
    $sdk = new VideoClient('https://video.test/', 'fvk_x', new RecordingClient, new HttpFactory, new HttpFactory);

    expect($sdk->frameUrl('v1', 2500))->toBe('https://video.test/api/v1/videos/v1/frame?t_ms=2500')
        ->and($sdk->sdkScriptUrl())->toBe('https://video.test/sdk/v1/fitt-video.js')
        ->and($sdk->sdkScriptUrl('1.1.0'))->toBe('https://video.test/sdk/1.1.0/fitt-video.js');
});

test('the error envelope maps to typed exceptions', function (int $status, string $body, string $class, string $code) {
    [$sdk] = recordingSdk([new Response($status, ['Content-Type' => 'application/json'], $body)]);

    $error = caughtApiError(fn () => $sdk->session('s1'));

    expect($error)->toBeInstanceOf($class)
        ->and($error->status)->toBe($status)
        ->and($error->errorCode)->toBe($code);
})->with([
    'unauthenticated' => [401, '{"error":{"code":"unauthenticated","message":"A valid API key is required."}}', AuthenticationException::class, 'unauthenticated'],
    'not found' => [404, '{"error":{"code":"not_found","message":"Nothing was found here."}}', NotFoundException::class, 'not_found'],
    'not ready' => [409, '{"error":{"code":"not_ready","message":"Not yet."}}', ApiException::class, 'not_ready'],
    'not JSON' => [502, 'Bad gateway', ApiException::class, 'http_error'],
]);

test('a validation failure carries the fields, and a rate limit its wait', function () {
    [$sdk] = recordingSdk([
        new Response(422, [], '{"error":{"code":"validation_failed","message":"Some fields are not valid.","fields":{"recipe":["The selected recipe is invalid."]}}}'),
        new Response(429, ['Retry-After' => '7'], '{"error":{"code":"rate_limited","message":"Too many requests.","retry_after":60}}'),
        new Response(429, [], '{"error":{"code":"rate_limited","message":"Too many requests.","retry_after":9}}'),
    ]);

    $invalid = caughtApiError(fn () => $sdk->createSession(['recipe' => 'nope']));
    $limited = caughtApiError(fn () => $sdk->me());
    $limitedByBody = caughtApiError(fn () => $sdk->me());

    expect($invalid)->toBeInstanceOf(ValidationException::class)
        ->and($invalid->fields)->toBe(['recipe' => ['The selected recipe is invalid.']])
        ->and($limited)->toBeInstanceOf(RateLimitedException::class)
        ->and($limited->retryAfter)->toBe(7)
        ->and($limitedByBody->retryAfter)->toBe(9);
});

test('a transport failure becomes a TransportException', function () {
    $down = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};
        }
    };
    $sdk = new VideoClient('https://video.test', 'fvk_x', $down, new HttpFactory, new HttpFactory);

    expect(fn () => $sdk->me())->toThrow(TransportException::class, 'Connection refused');
});

test('verifyWebhook reads a PSR request, and refuses an event id header that is not the body id', function () {
    $vector = json_decode((string) file_get_contents(dirname(__DIR__).'/vectors/webhook-signature.json'), true, flags: JSON_THROW_ON_ERROR);
    $factory = new HttpFactory;
    $sdk = new VideoClient('https://video.test', 'fvk_x', new RecordingClient, $factory, $factory, webhookSecrets: ['an-older-secret', $vector['secret']]);
    $timestamp = (string) time(); // the core verifies on time(), so the request is signed now
    $request = fn (string $eventId) => $factory->createRequest('POST', 'https://host.test/hooks/fitt')
        ->withHeader('X-Fitt-Event-Id', $eventId)
        ->withHeader('X-Fitt-Timestamp', $timestamp)
        ->withHeader('X-Fitt-Signature', 'sha256='.hash_hmac('sha256', $timestamp.'.'.$vector['body'], $vector['secret']))
        ->withBody($factory->createStream($vector['body']));

    expect($sdk->verifyWebhook($request('01kfy0a2b3c4d5e6f7g8h9j0km'))->id)->toBe('01kfy0a2b3c4d5e6f7g8h9j0km')
        ->and(fn () => $sdk->verifyWebhook($request('01kfy0a2b3c4d5e6f7g8h9j0kn')))->toThrow(InvalidWebhookSignature::class, 'header_mismatch');
});

test('with no client the SDK builds Guzzle and its PSR-17 factory', function () {
    $sdk = new VideoClient('https://video.test', 'fvk_x');

    expect((fn () => $this->client)->call($sdk))->toBeInstanceOf('GuzzleHttp\Client')
        ->and((fn () => $this->requestFactory)->call($sdk))->toBeInstanceOf(HttpFactory::class);
});

test('an empty body is sent as an object, a take rides on an analysis request, and a call with no body has no content type', function () {
    [$sdk, $http] = recordingSdk();

    $sdk->updateSession('s1', []);
    $sdk->requestAnalysis('s1', 'translate', 2);
    $sdk->finalise('s1');

    expect((string) $http->requests[0]->getBody())->toBe('{}')
        ->and((string) $http->requests[1]->getBody())->toBe('{"type":"translate","take":2}')
        ->and($http->requests[2]->hasHeader('Content-Type'))->toBeFalse()
        ->and((string) $http->requests[2]->getBody())->toBe('');
});
