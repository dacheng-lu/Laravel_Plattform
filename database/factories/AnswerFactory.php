<?php

namespace Database\Factories;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'question_id' => Question::factory(),
            'answer' => fake()->paragraph(),
            'score' => null,
            'feedback' => null,
        ];
    }

    /**
     * A classified mistake on this answer; the confidence is only meaningful for the AI sources.
     */
    public function mistake(MistakeType $type, MistakeSource $source = MistakeSource::Demo, ?int $confidence = 80): static
    {
        return $this->state(fn (array $attributes) => [
            'mistake_type' => $type,
            'mistake_confidence' => $confidence,
            'mistake_source' => $source,
        ]);
    }
}
