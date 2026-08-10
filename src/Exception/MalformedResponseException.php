<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * A successful response is missing a required field or has the wrong type for one.
 */
final class MalformedResponseException extends \RuntimeException implements FinlightException
{
}
