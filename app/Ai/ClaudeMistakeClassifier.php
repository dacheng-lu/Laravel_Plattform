<?php

namespace App\Ai;

use App\Models\Answer;
use App\Models\Question;

/**
 * Claude's classification: it arrives in-band in ClaudeLessonAssistant::evaluateSubmission(), which returns
 * `mistake_type` and `mistake_confidence` per answer, so there is no second API call to make here.
 */
final class ClaudeMistakeClassifier implements MistakeClassifier
{
    public function classify(Question $question, Answer $answer): ?MistakeClassification
    {
        return null;
    }
}
