<?php

namespace Tests\Feature\Review;

use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storage limitation for "Heute wiederholen": review items hang on their question (and so on the lesson) and on the
 * student, so the retention command and a deleted account take them with them.
 */
class ReviewRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruning_an_expired_lesson_removes_its_review_items(): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();

        $expired = $this->lessonWithReviewItem($student, $schoolClass, '2024-09-01');
        $kept = $this->lessonWithReviewItem($student, $schoolClass, '2026-09-01');

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired['lesson']);
        $this->assertModelMissing($expired['question']);
        $this->assertDatabaseMissing('review_items', ['id' => $expired['item']->id]);
        $this->assertDatabaseMissing('review_items', ['question_id' => $expired['question']->id]);

        // Positivkontrolle: the item of the lesson that is still inside the retention period survives.
        $this->assertModelExists($kept['lesson']);
        $this->assertDatabaseHas('review_items', [
            'id' => $kept['item']->id,
            'student_id' => $student->id,
            'question_id' => $kept['question']->id,
        ]);
        $this->assertSame(1, ReviewItem::query()->count());
    }

    public function test_deleting_a_student_removes_their_review_items(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $classmate = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student, $classmate])->create();
        $question = Question::factory()->for($lesson)->create();

        $ownItem = ReviewItem::factory()->for($student, 'student')->for($question)->create();
        $classmateItem = ReviewItem::factory()->for($classmate, 'student')->for($question)->create();

        $student->delete();

        $this->assertModelMissing($ownItem);
        $this->assertDatabaseMissing('review_items', ['student_id' => $student->id]);

        // Positivkontrolle: the classmate's item on the same question is untouched.
        $this->assertModelExists($classmateItem);
        $this->assertDatabaseHas('review_items', ['id' => $classmateItem->id, 'student_id' => $classmate->id]);
    }

    /**
     * A lesson of the given date with its question, an evaluated hand-in and one review item.
     *
     * @return array{lesson: Lesson, question: Question, item: ReviewItem}
     */
    private function lessonWithReviewItem(User $student, SchoolClass $schoolClass, string $date): array
    {
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'held_on' => $date,
            'due_on' => $date,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        $question = Question::factory()->for($lesson)->create(['position' => 1]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create([
            'created_at' => $date,
            'updated_at' => $date,
        ]);
        Answer::factory()->for($submission)->for($question)->create(['answer' => 'Licht, Wasser und Kohlendioxid.']);

        $item = ReviewItem::factory()->for($student, 'student')->for($question)->create([
            'box' => 2,
            'due_on' => '2026-10-25',
            'knew_count' => 1,
        ]);

        return compact('lesson', 'question', 'item');
    }
}
