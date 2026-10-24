<?php

return [

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
    | Demo access (prefilled login, "Demo-Zugänge", demo schools)
    |--------------------------------------------------------------------------
    |
    | Demo logins (the prefilled login form, the "Demo-Zugänge" list on the login page and the demo schools in
    | DemoSchoolSeeder) are available only when APP_ENV is exactly local, testing, staging or demo, DEMO_MODE is
    | true and DEMO_PASSWORD is set. Every other combination means off, so an instance that serves real schools
    | can never show demo logins by accident. Demo instances run with APP_ENV=demo. Changing DEMO_PASSWORD and
    | seeding again rotates the password of every demo account.
    |
    */

    'demo' => [
        // Demo logins only on non-production instances and only when explicitly switched on.
        'enabled' => in_array(env('APP_ENV'), ['local', 'testing', 'staging', 'demo'], true)
            && filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN),
        'password' => env('DEMO_PASSWORD'),
    ],

];
