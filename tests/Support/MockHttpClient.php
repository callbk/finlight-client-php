<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The injected PSR-18 client of the tests: answers from a queue or a handler and records every
 * request, so the bytes after serialisation can be checked. It never touches the network.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable|callable(RequestInterface): ResponseInterface> */
    private array $queue = [];

    /** @var (callable(RequestInterface): ResponseInterface)|null */
    private $fallback = null;

    public function push(ResponseInterface|\Throwable|callable $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    /**
     * Answer every request (after the queue is empty) with the handler.
     *
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    public function always(callable $handler): self
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): ResponseInterface
    {
        $text = $body === null ? '' : (is_string($body) ? $body : Kit::json($body));
        $headers += ['Content-Type' => 'application/json; charset=utf-8'];

        return new Response($status, $headers, $text);
    }

    /**
     * A response as a kit fixture describes it: {status, headers, body}.
     *
     * @param array<string, mixed> $response
     */
    public static function fromFixture(array $response): ResponseInterface
    {
        $status = $response['status'] ?? 200;
        $headers = Kit::obj($response['headers'] ?? []);
        $body = $response['body'] ?? null;
        $text = $body === null ? '' : (is_string($body) ? $body : Kit::json($body));
        $clean = [];
        foreach ($headers as $name => $value) {
            $clean[$name] = Kit::str($value);
        }

        return new Response(is_int($status) ? $status : 200, $clean, $text);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->queue);
        if ($answer === null) {
            if ($this->fallback === null) {
                throw new \LogicException('MockHttpClient: no answer queued for ' . $request->getMethod() . ' ' . $request->getUri());
            }
            $answer = $this->fallback;
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if ($answer instanceof ResponseInterface) {
            return $answer;
        }

        return $answer($request);
    }

    public function last(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('no request was sent');
        }

        return $last;
    }
}
