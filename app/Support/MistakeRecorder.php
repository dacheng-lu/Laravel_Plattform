<?php

namespace App\Support;

use App\Ai\MistakeClassification;
use App\Ai\MistakeClassifier;
use App\MistakeSource;
use App\Models\Answer;
use App\Models\Submission;
use Throwable;

/**
 * Stores the mistake type of the answers of one evaluated submission – one classification per answer, only for
 * answers below `lernplattform.mistakes.threshold` and only above `min_confidence`.
 *
 * Fail closed: a classifier that throws, a broken configuration or a low confidence leaves the answer without a
 * type, and never fails the stored evaluation. Every answer is handled on its own, so one failure does not stop the
 * others. A teacher's own correction is never overwritten. Not final, so tests can replace it with a mock.
 */
class MistakeRecorder
{
    /**
     * Higher than any possible confidence (0–100): used when the configured minimum is not a share between 0 and 1.
     */
    private const int UNREACHABLE_CONFIDENCE = 101;

    /**
     * Reads `mistake_type` and `mistake_confidence` from the evaluation and writes them to the answers.
     * A teacher's own correction is never overwritten.
     *
     * @param  array<string, mixed>  $evaluation
     */
    public function recordForSubmission(Submission $submission, array $evaluation): void
    {
        if (! (bool) config('lernplattform.mistakes.enabled')) {
            return;
        }

        $threshold = (int) config('lernplattform.mistakes.threshold', 80);
        $minimumConfidence = $this->minimumConfidence();

        foreach ($submission->answers()->with('question')->get() as $answer) {
            try {
                $this->record($answer, $evaluation, $threshold, $minimumConfidence);
            } catch (Throwable $exception) {
                // The evaluation stays stored and valid: only the hint for this one answer is lost.
                report($exception);

                $this->forget($answer);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function record(Answer $answer, array $evaluation, int $threshold, int $minimumConfidence): void
    {
        // A teacher's own correction has priority over every model, and an unscored answer is not yet judged.
        if ($answer->mistake_source === MistakeSource::Teacher || $answer->score === null) {
            return;
        }

        if ($answer->score >= $threshold) {
            $this->forget($answer);

            return;
        }

        $inBand = $evaluation['answers'][$answer->question_id] ?? [];

        $classification = MistakeClassification::fromEvaluation(is_array($inBand) ? $inBand : [], MistakeSource::Claude)
            ?? app(MistakeClassifier::class)->classify($answer->question, $answer);

        if ($classification === null || $classification->confidence < $minimumConfidence) {
            $this->forget($answer);

            return;
        }

        $answer->update([
            'mistake_type' => $classification->type,
            'mistake_confidence' => $classification->confidence,
            'mistake_source' => $classification->source,
        ]);
    }

    /**
     * Removes a hint that no longer holds, without touching the answer otherwise.
     */
    private function forget(Answer $answer): void
    {
        if ($answer->mistake_type === null && $answer->mistake_confidence === null && $answer->mistake_source === null) {
            return;
        }

        $answer->update([
            'mistake_type' => null,
            'mistake_confidence' => null,
            'mistake_source' => null,
        ]);
    }

    /**
     * `min_confidence` as a 0–100 value; anything outside 0–1 is unusable, so no confidence ever reaches it.
     */
    private function minimumConfidence(): int
    {
        $configured = config('lernplattform.mistakes.min_confidence');

        if (! is_numeric($configured)) {
            return self::UNREACHABLE_CONFIDENCE;
        }

        $share = (float) $configured;

        if ($share < 0.0 || $share > 1.0) {
            return self::UNREACHABLE_CONFIDENCE;
        }

        return (int) round($share * 100);
    }
}
