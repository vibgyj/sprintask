<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Util
{
    public static function isHttps(array $server): bool
    {
        return !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function nowUtc(): int
    {
        return time();
    }

    public static function localToUtc(string $localDateTime, string $timezone): ?int
    {
        $tz = new DateTimeZone($timezone);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $localDateTime, $tz);
        if (!$dt) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    }

    public static function utcToLocalInput(int $utc, string $timezone): string
    {
        $dt = (new DateTimeImmutable('@' . $utc))->setTimezone(new DateTimeZone($timezone));
        return $dt->format('Y-m-d\TH:i');
    }

    public static function utcToLocalTime(int $utc, string $timezone): string
    {
        $dt = (new DateTimeImmutable('@' . $utc))->setTimezone(new DateTimeZone($timezone));
        return $dt->format('h:i A');
    }

    public static function utcToLocalDateTime(int $utc, string $timezone): string
    {
        $dt = (new DateTimeImmutable('@' . $utc))->setTimezone(new DateTimeZone($timezone));
        return $dt->format('Y-m-d h:i A');
    }

    /** e.g. "Mon 03 Nov, 09:15 AM" */
    public static function utcToLocalShort(int $utc, string $timezone): string
    {
        $dt = (new DateTimeImmutable('@' . $utc))->setTimezone(new DateTimeZone($timezone));
        return $dt->format('D d M, h:i A');
    }

    public static function utcToLocalDate(int $utc, string $timezone): string
    {
        $dt = (new DateTimeImmutable('@' . $utc))->setTimezone(new DateTimeZone($timezone));
        return $dt->format('Y-m-d');
    }

    /** Aggregate hours, e.g. "12h 05m" (minutes rounded down). */
    public static function formatHours(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    public static function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02dh %02dm %02ds', $h, $m, $s);
    }

    public static function timezoneOptions(?DateTimeInterface $at = null): array
    {
        $instant = $at ? DateTimeImmutable::createFromInterface($at) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $options = [];
        foreach (DateTimeZone::listIdentifiers() as $tzId) {
            $tz = new DateTimeZone($tzId);
            $offset = $tz->getOffset($instant);
            $options[] = [
                'value' => $tzId,
                'label' => sprintf('%s (UTC%s)', $tzId, self::formatUtcOffset($offset)),
            ];
        }

        return $options;
    }

    public static function formatUtcOffset(int $seconds): string
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $abs = abs($seconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);
        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }
}
