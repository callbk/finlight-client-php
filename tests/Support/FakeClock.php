<?php

declare(strict_types=1);

namespace Finlight\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock whose time only moves through sleep() and advance(). Tests never wait for real.
 */
final class FakeClock implements ClockInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    private float $now;

    public function __construct(float $start = 1_795_467_845.0)
    {
        $this->now = $start;
    }

    public function now(): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->now));
        if ($time === false) {
            throw new \LogicException('bad fake time');
        }

        return $time;
    }

    public function sleep(float|int $seconds): void
    {
        $this->sleeps[] = (float) $seconds;
        $this->now += (float) $seconds;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
