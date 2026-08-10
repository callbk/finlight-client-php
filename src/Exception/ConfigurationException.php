<?php

declare(strict_types=1);

namespace Finlight\Exception;

/**
 * No PSR-18 client or PSR-17 factory could be found and none was injected.
 */
final class ConfigurationException extends \RuntimeException implements FinlightException
{
}
