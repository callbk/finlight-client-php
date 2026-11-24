<?php

declare(strict_types=1);

namespace Finlight\Internal;

use Finlight\FinlightDecodeException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ResponseInterface $response,
        public readonly string $body,
        public readonly string $requestId,
    ) {
    }

    public function header(string $name): ?string
    {
        $value = $this->response->getHeaderLine($name);

        return $value === '' ? null : $value;
    }

    /**
     * The body as a JSON object.
     *
     * @return array<string, mixed>
     */
    public function jsonObject(string $what): array
    {
        try {
            $value = Json::decode($this->body);
        } catch (\JsonException $e) {
            throw new FinlightDecodeException(sprintf('finlight: the %s answer is not valid JSON', $what), null, null, $e);
        }
        if (!Json::isObject($value) || !Json::looksLikeObject($this->body)) {
            throw new FinlightDecodeException(sprintf('finlight: the %s answer is not a JSON object', $what), null, $value);
        }

        return $value;
    }
}
