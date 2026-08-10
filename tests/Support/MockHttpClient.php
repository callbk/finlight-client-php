<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Replays queued responses and records the requests it was handed.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function push(ResponseInterface|\Throwable $item): self
    {
        $this->queue[] = $item;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException('MockHttpClient: the response queue is empty.');
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);

        if ($last === false) {
            throw new \LogicException('MockHttpClient: no request was sent.');
        }

        return $last;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
