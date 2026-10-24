<?php

namespace App\Ai;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;

/**
 * Offline stand-in for the AI: deterministic rules over the answer text, no API call.
 *
 * The rules run in the order of the spec. An empty answer is "no attempt", an answer far shorter than the expected
 * answer is "incomplete", an answer that shares not a single content word with the question and its expected answer
 * is "off topic". Everything else stays unclassified (null): an "unclear" beats a wrong label.
 */
class DemoMistakeClassifier implements MistakeClassifier
{
    /**
     * Shorter words ("der", "und", "zu") carry no content on their own.
     */
    private const int MINIMUM_CONTENT_WORD_LENGTH = 4;

    public function classify(Question $question, Answer $answer): ?MistakeClassification
    {
        $text = trim((string) $answer->answer);

        if ($text === '') {
            return new MistakeClassification(MistakeType::Empty, 100, MistakeSource::Demo);
        }

        $shortRatio = (float) config('lernplattform.mistakes.demo_short_ratio', 0.3);

        if ($this->wordCount($text) < $shortRatio * $this->wordCount((string) $question->expected_answer)) {
            return new MistakeClassification(MistakeType::Incomplete, 70, MistakeSource::Demo);
        }

        $reference = $this->contentWords((string) $question->prompt.' '.(string) $question->expected_answer);

        if ($reference !== [] && array_intersect($this->contentWords($text), $reference) === []) {
            return new MistakeClassification(MistakeType::OffTopic, 65, MistakeSource::Demo);
        }

        return null;
    }

    /**
     * Lower-case words without punctuation, short ones dropped – the words that carry the content.
     *
     * @return list<string>
     */
    private function contentWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        $words = array_filter(
            array_map(mb_strtolower(...), $matches[0]),
            fn (string $word): bool => mb_strlen($word) >= self::MINIMUM_CONTENT_WORD_LENGTH,
        );

        return array_values(array_unique($words));
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
