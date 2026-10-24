<?php

namespace App\Ai;

use App\Models\Answer;
use App\Models\Question;

/**
 * Names the kind of mistake in a student's answer, after the answer was scored.
 *
 * Bound in AppServiceProvider: ClaudeMistakeClassifier when `services.anthropic.enabled` is true, otherwise the
 * offline DemoMistakeClassifier. Fail closed: a timeout, an error or an implausible answer must never delay or
 * break the evaluation of a submission.
 */
interface MistakeClassifier
{
    /**
     * null = keine Einordnung (unsicher, aus, Fehler).
     */
    public function classify(Question $question, Answer $answer): ?MistakeClassification;
}
