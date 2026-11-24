<?php

declare(strict_types=1);

namespace Finlight\Internal;

/**
 * Strict RFC 3339 parsing and the SDK's own formatting of times.
 *
 * @internal
 */
final class Time
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/D';

    /**
     * Parse a timestamp in strict RFC 3339 form with Z or an offset. Returns the time in UTC, or
     * null for anything else: unix seconds, dates without a time, times without a zone, or values
     * out of range. Never uses the lenient DateTime constructor.
     */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($m, 1, 6));
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $micro = $m[7] === '' ? '000000' : str_pad(substr($m[7], 1, 6), 6, '0');
        $zone = strtoupper($m[8]);
        if ($zone !== 'Z') {
            $hh = (int) substr($zone, 1, 2);
            $mm = (int) substr($zone, 4, 2);
            if ($hh > 23 || $mm > 59) {
                return null;
            }
        } else {
            $zone = '+00:00';
        }
        $text = sprintf('%04d-%02d-%02dT%02d:%02d:%02d.%s%s', $year, $month, $day, $hour, $minute, $second, $micro, $zone);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /** UTC ISO 8601 with milliseconds and Z, independent of the locale. */
    public static function format(\DateTimeInterface $value): string
    {
        $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }
}
