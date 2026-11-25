<?php

declare(strict_types=1);

namespace Finlight;

/**
 * INTERNAL_ERROR: the server could not process the request.
 */
class InternalServerException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'internal_error';
    }
}
