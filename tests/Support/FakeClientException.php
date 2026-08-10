<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * A transport-level failure such as DNS or connection refused.
 */
final class FakeClientException extends \RuntimeException implements ClientExceptionInterface
{
}
