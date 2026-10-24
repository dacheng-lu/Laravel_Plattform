<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Class statistics (aggregates only)
    |--------------------------------------------------------------------------
    |
    | The statistics page shows class averages and never a single student's result. A group smaller than
    | `min_group` is suppressed, so nobody can be recognised from it; the same limit applies to a week of the
    | trend and to a competency or subject. `minutes_per_evaluation` is the estimate of correction time the AI
    | saves per evaluated hand-in. `weeks` are the periods the page offers, `default_weeks` the one used when a
    | request asks for any other period.
    |
    */

    'min_group' => 3,

    'minutes_per_evaluation' => (int) env('LERNPLATTFORM_MINUTES_PER_EVALUATION', 3),

    'weeks' => [4, 10],

    'default_weeks' => 10,

];
