<?php

declare(strict_types=1);

namespace Finlight;

/**
 * FORBIDDEN: the plan lacks the needed entitlement.
 */
class ForbiddenException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'forbidden';
    }
}
