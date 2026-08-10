<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * A webhook failed verification. Reject the request; do not process the payload.
 */
final class WebhookVerificationException extends \RuntimeException implements FinlightException
{
}
