<?php

declare(strict_types=1);

namespace Finlight;

/**
 * A correctly signed webhook body that is not payloadVersion 3, for example a v2 retry that still
 * arrives for about 2 hours after switching the webhook to v3.
 *
 * This is a result, not an error: answer it with 2xx and keep raw if you need the data. An
 * unhandled WrongPayloadVersion that ends in a 500 counts as a failed delivery.
 *
 * - detectedVersion: the version as the server reads it (always 2 here).
 * - payloadVersion: the payloadVersion value as received; payloadVersionPresent is false when the
 *   key was missing.
 * - raw: the parsed JSON body.
 * - link: the article link, when it can be read.
 */
final class WrongPayloadVersion
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $detectedVersion,
        public readonly mixed $payloadVersion,
        public readonly bool $payloadVersionPresent,
        public readonly array $raw,
        public readonly ?string $link,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rawJson(): array
    {
        return $this->raw;
    }
}
