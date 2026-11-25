<?php

declare(strict_types=1);

namespace Finlight;

/**
 * VALIDATION_FAILED: the server rejected the request or the subscribe.
 */
class ValidationFailedException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'validation_failed';
    }
}
