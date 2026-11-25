<?php

declare(strict_types=1);

namespace Finlight;

/**
 * CURSOR_MISMATCH: sort order or filters changed since the cursor was issued.
 */
class CursorMismatchException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'cursor_mismatch';
    }
}
