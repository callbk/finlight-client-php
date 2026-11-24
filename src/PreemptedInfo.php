<?php

declare(strict_types=1);

namespace Finlight;

/**
 * The data of connection.preempted (or a v2 preempted frame).
 *
 * reason is open (PreemptReason). connection_limit: another connection took over; do not reconnect.
 * quota_exceeded: the monthly WebSocket quota is used up; try again about once per hour.
 * timestamp is epoch milliseconds.
 */
final class PreemptedInfo
{
    public function __construct(
        public readonly ?string $reason,
        public readonly ?string $newLeaseId,
        public readonly ?int $timestamp,
    ) {
    }
}
