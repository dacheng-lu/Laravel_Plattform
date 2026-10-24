<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\ReviewToday;
use App\Filament\Widgets\StudentLearningWidget;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\User;
use App\QuestionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Heute wiederholen" card on the student's start page: it appears with the number of due questions and stays away
 * when nothing is due or repeating is switched off.
 */
class ReviewCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_counts_the_questions_due_today(): void
    {
        $student = $this->student();

        $this->actingAs($student);

        // Nothing due: no card at all.
        Livewire::test(StudentLearningWidget::class)
            ->assertDontSee('Kurz wiederholen')
            ->assertDontSee('Heute wiederholen');

        $this->dueItems($student, 3);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Kurz wiederholen')
            ->assertSee('Heute wiederholen: 3 Fragen')
            ->assertSee(ReviewToday::getUrl(), false);

        // The same card is on the dashboard itself.
        $this->get('/')
            ->assertOk()
            ->assertSee('Heute wiederholen: 3 Fragen');
    }

    public function test_a_single_question_is_announced_in_the_singular(): void
    {
        $student = $this->student();
        $this->dueItems($student, 1);

        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Heute wiederholen: 1 Frage')
            ->assertDontSee('Heute wiederholen: 1 Fragen');
    }

    public function test_the_card_disappears_when_repeating_is_switched_off(): void
    {
        $student = $this->student();
        $this->dueItems($student, 2);

        $this->actingAs($student);

        // Positive control: with repeating switched on, the same two questions produce the card.
        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Heute wiederholen: 2 Fragen');

        config()->set('lernplattform.review.enabled', false);

        Livewire::test(StudentLearningWidget::class)
            ->assertDontSee('Heute wiederholen')
            ->assertDontSee('Kurz wiederholen');

        // The page itself is closed as well.
        $this->get(ReviewToday::getUrl())->assertForbidden();
    }

    private function student(): User
    {
        return User::factory()->student(SchoolClass::factory()->create())->create();
    }

    /**
     * Due questions of published lessons that are delegated to the student.
     */
    private function dueItems(User $student, int $count): void
    {
        foreach (range(1, $count) as $number) {
            $lesson = Lesson::factory()
                ->for($student->schoolClass)
                ->publishedFor([$student])
                ->create();

            $question = Question::factory()->for($lesson)->create([
                'type' => QuestionType::Comprehension,
                'prompt' => "Wiederholungsfrage Nummer {$number}",
            ]);

            ReviewItem::factory()
                ->for($student, 'student')
                ->for($question)
                ->due(today()->toDateString())
                ->create();
        }
    }
}
