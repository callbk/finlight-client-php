<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Common interface of every exception the SDK throws.
 *
 * kind() names the kind as text, so errors can be told apart without instanceof:
 * "api", "validation", "network", "timeout", "decode" or "webhook_verification".
 * The original error of a library below the SDK stays reachable through getPrevious().
 */
interface FinlightException extends \Throwable
{
    public const KIND_API = 'api';
    public const KIND_VALIDATION = 'validation';
    public const KIND_NETWORK = 'network';
    public const KIND_TIMEOUT = 'timeout';
    public const KIND_DECODE = 'decode';
    public const KIND_WEBHOOK_VERIFICATION = 'webhook_verification';

    public function kind(): string;
}
