<?php

declare(strict_types=1);

namespace Finlight;

/**
 * An attempt or the whole call (totalTimeout) ran out of time.
 */
final class FinlightTimeoutException extends FinlightNetworkException
{
    public function kind(): string
    {
        return FinlightException::KIND_TIMEOUT;
    }
}
