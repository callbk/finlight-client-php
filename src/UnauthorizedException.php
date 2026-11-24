<?php

declare(strict_types=1);

namespace Finlight;

/**
 * UNAUTHORIZED: missing or invalid API key.
 */
class UnauthorizedException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'unauthorized';
    }
}
