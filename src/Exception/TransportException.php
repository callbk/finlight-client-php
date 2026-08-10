<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * The request never produced a response: DNS, connection, TLS or timeout.
 */
final class TransportException extends \RuntimeException implements FinlightException
{
}
