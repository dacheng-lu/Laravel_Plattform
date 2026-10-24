<?php

namespace App\Ai;

use App\Models\Answer;
use App\Models\Question;

/**
 * Offline stand-in for the AI: deterministic rules over the answer text, no API call.
 *
 * Right now it classifies nothing; the rules (empty, very short compared to the expected answer, no overlap with
 * it) follow in lane B1.
 */
class DemoMistakeClassifier implements MistakeClassifier
{
    public function classify(Question $question, Answer $answer): ?MistakeClassification
    {
        return null;
    }
}
