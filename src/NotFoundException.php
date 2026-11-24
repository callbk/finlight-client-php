<?php

declare(strict_types=1);

namespace Finlight;

/**
 * NOT_FOUND: the lookup found nothing.
 */
class NotFoundException extends FinlightApiException
{
    public function apiKind(): string
    {
        return 'not_found';
    }
}
