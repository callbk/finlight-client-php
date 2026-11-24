<?php

declare(strict_types=1);

namespace Finlight;

/**
 * CURSOR_INVALID: the cursor could not be read.
 */
class CursorInvalidException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'cursor_invalid';
    }
}
