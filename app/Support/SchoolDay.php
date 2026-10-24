<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The school day of the platform: "today" is the calendar day in the school's time zone (`lernplattform.timezone`,
 * Europe/Berlin by default), not the UTC day the server runs in. Between 00:00 and 02:00 CEST the UTC day is still
 * the previous one, so a due date worked out in UTC would call "tomorrow" today – and a rating at that time would
 * shorten every interval by one day.
 *
 * `today()` is the day for date columns such as `review_items.due_on`; `startUtc()` is the very same moment as an
 * instant in UTC, to compare against timestamp columns such as `review_items.last_reviewed_at`.
 *
 * An unusable time zone in the configuration falls back to Europe/Berlin and is reported: a fallback keeps a broken
 * setting from moving due dates, whereas guessing a day would move them (fail safe).
 */
final class SchoolDay
{
    /** Used when `lernplattform.timezone` is missing or is not a known time zone identifier. */
    public const string FALLBACK_TIMEZONE = 'Europe/Berlin';

    /**
     * The school day as a date: midnight in the school's time zone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * The beginning of the school day as an instant in UTC, for timestamp columns that are stored in UTC.
     */
    public static function startUtc(): CarbonImmutable
    {
        return self::today()->utc();
    }

    /**
     * The configured time zone, or the fallback when the setting is not a usable time zone identifier.
     */
    private static function timezone(): string
    {
        $timezone = config('lernplattform.timezone');

        if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        report(new RuntimeException(
            'Ungültige Zeitzone in lernplattform.timezone: erwartet wird eine bekannte Zeitzone wie '
            .self::FALLBACK_TIMEZONE.'. Es wird '.self::FALLBACK_TIMEZONE.' verwendet.',
        ));

        return self::FALLBACK_TIMEZONE;
    }
}
