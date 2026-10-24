<?php

namespace App\Ai;

use App\MistakeSource;
use App\MistakeType;

/**
 * One classification: the mistake type, how sure the classifier was (0–100) and who classified.
 */
final readonly class MistakeClassification
{
    public function __construct(
        public MistakeType $type,
        public int $confidence,
        public MistakeSource $source,
    ) {}

    /**
     * Reads `mistake_type` and `mistake_confidence` out of an evaluation result, strictly: a value that is not
     * exactly as specified counts as "no classification" instead of being coerced.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromEvaluation(array $result, MistakeSource $source): ?self
    {
        $type = $result['mistake_type'] ?? null;
        $confidence = $result['mistake_confidence'] ?? null;

        if (! is_string($type) || ! is_int($confidence) || $confidence < 0 || $confidence > 100) {
            return null;
        }

        $mistakeType = MistakeType::tryFrom($type);

        return $mistakeType === null ? null : new self($mistakeType, $confidence, $source);
    }
}
