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
 * An unusable time zone in the configuration falls back to Europe/Berlin: that fallback is not a guessed zone, it is
 * the documented default of the platform and the one a German school works in. Refusing to answer instead would stop
 * every repetition for every student over a single broken setting, which is the worse failure – so the platform keeps
 * working on its default and reports the broken value. The zone is resolved once per request, so a broken setting is
 * reported at most once instead of on every query.
 */
final class SchoolDay
{
    /** Used when `lernplattform.timezone` is missing or is not a known time zone identifier. */
    public const string FALLBACK_TIMEZONE = 'Europe/Berlin';

    /** The resolved time zone of this request; null until the first call. */
    private static ?string $timezone = null;

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
     * Forgets the resolved time zone, for tests that change `lernplattform.timezone` while the process keeps running.
     */
    public static function flush(): void
    {
        self::$timezone = null;
    }

    /**
     * The configured time zone, resolved once per request: a usable identifier, otherwise the platform default.
     */
    private static function timezone(): string
    {
        return self::$timezone ??= self::resolveTimezone();
    }

    /**
     * Reads `lernplattform.timezone` and reports it when it is unusable; the answer is always a usable time zone.
     */
    private static function resolveTimezone(): string
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
