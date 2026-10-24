<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewItem>
 */
class ReviewItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'question_id' => Question::factory(),
            'box' => 1,
            'due_on' => today(),
            'last_reviewed_at' => null,
            'knew_count' => 0,
            'hard_count' => 0,
            'forgot_count' => 0,
        ];
    }

    /**
     * Due on the given date (Y-m-d), by default today.
     */
    public function due(?string $on = null): static
    {
        return $this->state(fn (array $attributes) => ['due_on' => $on ?? today()]);
    }

    public function box(int $box): static
    {
        return $this->state(fn (array $attributes) => ['box' => $box]);
    }

    /**
     * Already rated today, so it isn't due again.
     */
    public function reviewedToday(): static
    {
        return $this->state(fn (array $attributes) => ['last_reviewed_at' => now()]);
    }
}
