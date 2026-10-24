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
 * is "off topic". Everything else stays unclassified (null): an "unclear" beats a wrong label. An answer made of
 * function words only is never "off topic" – it says nothing about the topic, neither for nor against it.
 *
 * The confidences 70 and 65 deliberately sit above the default `min_confidence` of 0.6, so the offline rules apply
 * with the default configuration.
 *
 * Known limit of the word-stem heuristic: two words count as the same when their first five letters match. Synonyms and
 * abbreviations that share no stem therefore look unrelated – "O2" against "Sauerstoff", "H2O" against "Wasser",
 * "Zähler" against "Numerator". A correct answer written that way can be labelled "off topic" (confidence 65). The
 * result is only a hint next to the answer: it changes no score, is marked as an AI hint that can be wrong, and the
 * teacher's own correction has priority.
 */
class DemoMistakeClassifier implements MistakeClassifier
{
    /**
     * Shorter words ("der", "und", "zu") carry no content on their own.
     */
    private const int MINIMUM_CONTENT_WORD_LENGTH = 4;

    /**
     * Function words that appear in almost every sentence: they never show that an answer is about the question.
     */
    private const array STOPWORDS = [
        'eine', 'einem', 'einen', 'einer', 'eines', 'nicht', 'sind', 'auch', 'dass', 'oder', 'wird', 'werden',
        'haben', 'über', 'unter', 'durch', 'sich', 'sein', 'ihre', 'ihr', 'diese', 'dieser', 'wenn', 'weil',
        'aber', 'noch', 'sehr',
    ];

    /**
     * Two words count as the same when their first letters match, so inflections and derivations still count
     * ("fotosynthetisch" for "Fotosynthese"); shorter words are compared whole.
     */
    private const int COMPARED_PREFIX_LENGTH = 5;

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

        $answerWords = $this->contentWords($text);
        $reference = $this->contentWords((string) $question->prompt.' '.(string) $question->expected_answer);

        if ($answerWords !== [] && $reference !== [] && array_intersect($answerWords, $reference) === []) {
            return new MistakeClassification(MistakeType::OffTopic, 65, MistakeSource::Demo);
        }

        return null;
    }

    /**
     * Lower-case words without punctuation, function words and short ones dropped – reduced to their first letters,
     * so the words that carry the content are compared regardless of inflection and derivation.
     *
     * @return list<string>
     */
    private function contentWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        $words = array_filter(
            array_map(mb_strtolower(...), $matches[0]),
            fn (string $word): bool => mb_strlen($word) >= self::MINIMUM_CONTENT_WORD_LENGTH
                && ! in_array($word, self::STOPWORDS, true),
        );

        $stems = array_map(
            fn (string $word): string => mb_substr($word, 0, self::COMPARED_PREFIX_LENGTH),
            $words,
        );

        return array_values(array_unique($stems));
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
