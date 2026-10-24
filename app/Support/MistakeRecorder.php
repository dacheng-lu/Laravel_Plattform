<?php

namespace App\Support;

use App\Models\Submission;

/**
 * Stores the mistake type of the answers of one evaluated submission – one classification per answer, only for
 * answers below `lernplattform.mistakes.threshold` and only above `min_confidence`.
 *
 * Not final, so tests can replace it with a mock.
 * Right now it is a no-op; the recording logic follows in lane B1.
 */
class MistakeRecorder
{
    /**
     * Reads `mistake_type` and `mistake_confidence` from the evaluation and writes them to the answers.
     * A teacher's own correction is never overwritten.
     *
     * @param  array<string, mixed>  $evaluation
     */
    public function recordForSubmission(Submission $submission, array $evaluation): void
    {
        // Intentionally empty: callers may already rely on the contract, the write happens in lane B1.
    }
}
