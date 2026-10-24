<?php

return [

    /*
    |--------------------------------------------------------------------------
    | School time zone
    |--------------------------------------------------------------------------
    |
    | The calendar day the platform works with: "today" and "tomorrow" are the days in this time zone, not in UTC.
    | The server runs in UTC, so between 00:00 and 02:00 CEST the UTC day is still the previous one – a due date
    | worked out in UTC would then call "tomorrow" today. Everything that turns a day into a date (for example the
    | "Heute wiederholen" due dates) goes through App\Support\SchoolDay, which reads this value and falls back to
    | Europe/Berlin when it is not a known time zone identifier.
    |
    */

    'timezone' => env('LERNPLATTFORM_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Retention of learning data
    |--------------------------------------------------------------------------
    |
    | Lessons (with their questions, hand-ins and answers) and "Erklär's mir" conversations are kept for the
    | current school year plus the previous ones, so `school_years_kept` = 2 means the current and the last
    | school year. `school_year_starts` is the month and day (MM-DD) a school year begins. A lesson is only
    | deleted when its date, its last change, its due date and its newest hand-in all lie before the start of
    | the oldest kept school year. See `php artisan lernplattform:prune-learning-data`.
    |
    */

    'retention' => [
        'school_years_kept' => (int) env('LERNPLATTFORM_SCHOOL_YEARS_KEPT', 2),
        'school_year_starts' => env('LERNPLATTFORM_SCHOOL_YEAR_STARTS', '08-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lesson recordings (audio only)
    |--------------------------------------------------------------------------
    |
    | Teachers may record a lesson's sound on the recording page. Recordings are private to the teacher, never sent
    | to an AI and deleted after `keep_days` days (`php artisan lernplattform:prune-recordings`, scheduled daily).
    | `max_chunk_kb` caps one upload (the browser sends a chunk every `chunk_seconds`, about 100 KB at 32 kbit/s),
    | `max_total_mb` the whole file (a school day at 32 kbit/s is around 100 MB). A teacher can have at most
    | `max_unfinished` recordings that were started in the last 12 hours and never finished (browser closed,
    | connection lost) before a new one is refused, so a faulty client cannot fill the disk with recordings.
    |
    */

    'recordings' => [
        'keep_days' => (int) env('LERNPLATTFORM_RECORDING_KEEP_DAYS', 14),
        'chunk_seconds' => 20,
        'max_chunk_kb' => (int) env('LERNPLATTFORM_RECORDING_MAX_CHUNK_KB', 2048),
        'max_total_mb' => (int) env('LERNPLATTFORM_RECORDING_MAX_TOTAL_MB', 150),
        'max_unfinished' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data processing agreement (AVV, Art. 28 DSGVO)
    |--------------------------------------------------------------------------
    |
    | `version` identifies the text of the agreement on /auftragsverarbeitung. Schools accept it electronically; the
    | accepted version, the text as the school saw it and who accepted it are stored (DpaAcceptance). Change the version
    | whenever the text changes in substance: every school that accepted an older version then sees a prominent request
    | to accept the new one under "Meine Schule", and the operator sees it as outdated in the school list. Values the
    | text takes from the configuration (retention periods, session lifetime, AI and transcription switched on) are part
    | of the acceptance as well, see DpaAcceptance::terms(): changing one of them makes every acceptance outdated, too.
    |
    | `final` releases the contract. While it is false the text is a draft with open placeholders: nobody can accept it,
    | a registering school gets no acceptance box and nobody is held back by a missing acceptance. Set
    | LERNPLATTFORM_DPA_FINAL=true only after every yellow placeholder in resources/views/filament/legal/avv-contract.blade.php
    | is filled in and the text was checked legally. From then on a school that never accepted can use nothing but the
    | "Meine Schule" page, and its lessons cannot be recorded, until its administration accepts.
    |
    */

    'dpa' => [
        'version' => '2026-10',
        'title' => 'Vertrag zur Auftragsverarbeitung (AVV)',
        'final' => (bool) env('LERNPLATTFORM_DPA_FINAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | "Heute wiederholen" (spaced repetition)
    |--------------------------------------------------------------------------
    |
    | After an evaluated submission the answered comprehension and application questions become review items in
    | box 1. `intervals` maps each box to the number of days until it is due again, a `final_box` counts as settled
    | (never asked again). `daily_limit` caps how many questions a student gets per day. `min_group` is reserved for
    | a class overview later on: individual self-assessments are never shown to teachers or in the parents' report.
    |
    */

    'review' => [
        'enabled' => filter_var(env('LERNPLATTFORM_REVIEW_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'daily_limit' => (int) env('LERNPLATTFORM_REVIEW_DAILY_LIMIT', 5),
        'intervals' => [1 => 1, 2 => 3, 3 => 7, 4 => 21],
        'final_box' => 5,
        'min_group' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mistake types
    |--------------------------------------------------------------------------
    |
    | Only answers below `threshold` (0–100) are classified, and only when the classifier's `min_confidence`
    | (0–1) is reached – an "unclear" is better than a wrong label. `demo_short_ratio` is the share of the
    | expected answer's length below which the offline rules call an answer "incomplete".
    |
    */

    'mistakes' => [
        'enabled' => filter_var(env('LERNPLATTFORM_MISTAKES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'threshold' => (int) env('LERNPLATTFORM_MISTAKES_THRESHOLD', 80),
        'min_confidence' => (float) env('LERNPLATTFORM_MISTAKES_MIN_CONFIDENCE', 0.6),
        'demo_short_ratio' => 0.3,
    ],

];
