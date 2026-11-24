<?php

declare(strict_types=1);

namespace Finlight;

/**
 * The data of connection.admitted (or a v2 admit). It arrives before the server checks the
 * subscribe; it does not mean the subscribe was accepted. serverNow is epoch milliseconds.
 */
final class AdmittedInfo
{
    public function __construct(
        public readonly ?string $leaseId,
        public readonly ?int $serverNow,
        public readonly ?string $clientNonce,
    ) {
    }
}
