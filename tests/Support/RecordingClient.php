<?php

namespace Fitt\Video\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client for the SDK's unit tests: it sends nothing, records every
 * request and answers the queued responses in order, then 200 {}.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param  list<ResponseInterface>  $responses */
    public function __construct(private array $responses = []) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(200, ['Content-Type' => 'application/json'], '{}');
    }
}
