<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\WorkOnHomework;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\ReviewScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Leitner side of "Heute wiederholen": which questions become review items, how a self-assessment moves them
 * on and that neither ever touches a score.
 */
class ReviewSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_creates_items_once(): void
    {
        $this->travelTo('2026-10-24 09:00:00');

        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $comprehension = $this->question($lesson, QuestionType::Comprehension, 1);
        $application = $this->question($lesson, QuestionType::Application, 2);
        $reflection = $this->question($lesson, QuestionType::Reflection, 3);
        $unanswered = $this->question($lesson, QuestionType::Application, 4);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();
        Answer::factory()->for($submission)->for($comprehension)->create(['answer' => 'Licht, Wasser und Kohlendioxid.']);
        Answer::factory()->for($submission)->for($application)->create(['answer' => 'Eine Pflanze im dunklen Zimmer wächst nicht.']);
        Answer::factory()->for($submission)->for($reflection)->create(['answer' => 'Der Versuch mit der Wasserpflanze.']);
        // Only blanks: not an answered question, so it never becomes a review item.
        Answer::factory()->for($submission)->for($unanswered)->create(['answer' => '   ']);

        $scheduler = app(ReviewScheduler::class);

        $this->assertSame(2, $scheduler->scheduleForSubmission($submission));

        $items = ReviewItem::query()->orderBy('question_id')->get();
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing([$comprehension->id, $application->id], $items->pluck('question_id')->all());

        foreach ($items as $item) {
            $this->assertSame($student->id, $item->student_id);
            $this->assertSame(1, $item->box);
            $this->assertSame('2026-10-25', $item->due_on->toDateString());
            $this->assertNull($item->last_reviewed_at);
            $this->assertSame(0, $item->knew_count);
            $this->assertSame(0, $item->hard_count);
            $this->assertSame(0, $item->forgot_count);
        }

        $this->assertDatabaseMissing('review_items', ['question_id' => $reflection->id]);
        $this->assertDatabaseMissing('review_items', ['question_id' => $unanswered->id]);

        // A further evaluation of the same hand-in (e.g. "Jetzt auswerten lassen" again) adds no duplicates.
        $this->assertSame(0, $scheduler->scheduleForSubmission($submission));
        $this->assertSame(2, ReviewItem::query()->count());
    }

    /**
     * One row per box and rating; each row ends with the expected box and the days until the next due date
     * (null: the settled box keeps its date).
     *
     * @return array<string, array{int, ReviewRating, int, ?int}>
     */
    public static function transitions(): array
    {
        return [
            'Box 1, sofort gewusst' => [1, ReviewRating::Knew, 2, 3],
            'Box 1, mit Mühe' => [1, ReviewRating::Hard, 1, 1],
            'Box 1, nicht gewusst' => [1, ReviewRating::Forgot, 1, 1],
            'Box 2, sofort gewusst' => [2, ReviewRating::Knew, 3, 7],
            'Box 2, mit Mühe' => [2, ReviewRating::Hard, 2, 3],
            'Box 2, nicht gewusst' => [2, ReviewRating::Forgot, 1, 1],
            'Box 3, sofort gewusst' => [3, ReviewRating::Knew, 4, 21],
            'Box 3, mit Mühe' => [3, ReviewRating::Hard, 3, 7],
            'Box 3, nicht gewusst' => [3, ReviewRating::Forgot, 1, 1],
            'Box 4, sofort gewusst' => [4, ReviewRating::Knew, 5, null],
            'Box 4, mit Mühe' => [4, ReviewRating::Hard, 4, 21],
            'Box 4, nicht gewusst' => [4, ReviewRating::Forgot, 1, 1],
        ];
    }

    #[DataProvider('transitions')]
    public function test_leitner_transitions(int $box, ReviewRating $rating, int $expectedBox, ?int $daysUntilDue): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $item = ReviewItem::factory()->box($box)->due('2026-09-01')->create([
            'knew_count' => 2,
            'hard_count' => 3,
            'forgot_count' => 4,
        ]);

        $rated = app(ReviewScheduler::class)->rate($item, $rating)->refresh();

        $expectedDue = $daysUntilDue === null
            ? '2026-09-01'
            : today()->addDays($daysUntilDue)->toDateString();

        $this->assertSame($expectedBox, $rated->box);
        $this->assertSame($expectedDue, $rated->due_on->toDateString());
        $this->assertSame(now()->toDateTimeString(), $rated->last_reviewed_at?->toDateTimeString());

        // Exactly the counter of the rating moves on, the other two stay untouched.
        $this->assertSame(2 + ($rating === ReviewRating::Knew ? 1 : 0), $rated->knew_count);
        $this->assertSame(3 + ($rating === ReviewRating::Hard ? 1 : 0), $rated->hard_count);
        $this->assertSame(4 + ($rating === ReviewRating::Forgot ? 1 : 0), $rated->forgot_count);

        // And the transition is stored, not only in memory.
        $this->assertDatabaseHas('review_items', [
            'id' => $rated->id,
            'box' => $expectedBox,
            'knew_count' => $rated->knew_count,
            'hard_count' => $rated->hard_count,
            'forgot_count' => $rated->forgot_count,
        ]);
    }

    public function test_a_settled_box_is_never_due_again(): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $item = ReviewItem::factory()->box(4)->due('2026-10-24')->create(['knew_count' => 0]);

        $rated = app(ReviewScheduler::class)->rate($item, ReviewRating::Knew)->refresh();

        $this->assertSame(config('lernplattform.review.final_box'), $rated->box);
        $this->assertSame(1, $rated->knew_count);
        // Box 5 keeps its due date; the queue only asks for box < final_box, so it is not due any more.
        $this->assertSame('2026-10-24', $rated->due_on->toDateString());
        $this->assertSame(0, ReviewItem::query()->where('box', '<', config('lernplattform.review.final_box'))->count());

        // Positivkontrolle: a box below the final box is still due.
        $pending = ReviewItem::factory()->box(4)->due('2026-10-24')->create();
        $this->assertSame(1, ReviewItem::query()->where('box', '<', config('lernplattform.review.final_box'))->count());

        // Rating an already settled item changes nothing at all (fail closed).
        $again = app(ReviewScheduler::class)->rate($rated, ReviewRating::Forgot)->refresh();

        $this->assertSame(config('lernplattform.review.final_box'), $again->box);
        $this->assertSame('2026-10-24', $again->due_on->toDateString());
        $this->assertSame(1, $again->knew_count);
        $this->assertSame(0, $again->hard_count);
        $this->assertSame(0, $again->forgot_count);
        $this->assertSame(now()->toDateTimeString(), $again->last_reviewed_at?->toDateTimeString());
        $this->assertModelExists($pending);
    }

    public function test_disabled_or_unevaluated_creates_nothing(): void
    {
        $this->travelTo('2026-10-24 09:00:00');

        $submission = $this->evaluatedSubmissionWithTwoQuestions();
        $openSubmission = $this->openSubmissionWithTwoQuestions();

        config()->set('lernplattform.review.enabled', false);
        $this->assertSame(0, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertDatabaseEmpty('review_items');

        // Positivkontrolle: switched on, the very same hand-in does schedule its two questions.
        config()->set('lernplattform.review.enabled', true);
        $this->assertSame(2, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertSame(2, ReviewItem::query()->count());

        // A hand-in that was never evaluated is not scheduled, although the feature is on.
        $this->assertFalse($openSubmission->isEvaluated());
        $this->assertSame(0, app(ReviewScheduler::class)->scheduleForSubmission($openSubmission));
        $this->assertDatabaseMissing('review_items', ['student_id' => $openSubmission->student_id]);
        $this->assertSame(2, ReviewItem::query()->count());
    }

    public function test_broken_intervals_fail_closed(): void
    {
        $this->travelTo('2026-10-24 09:00:00');

        $submission = $this->evaluatedSubmissionWithTwoQuestions();

        foreach ([[], [2 => 3], [1 => 0], [1 => '1'], 'kaputt'] as $broken) {
            config()->set('lernplattform.review.intervals', $broken);

            // No exception, no item: a broken setting must never guess an interval.
            $this->assertSame(0, app(ReviewScheduler::class)->scheduleForSubmission($submission));
            $this->assertDatabaseEmpty('review_items');
        }

        // Positivkontrolle: with the configured intervals the hand-in is scheduled as usual.
        config()->set('lernplattform.review.intervals', [1 => 1, 2 => 3, 3 => 7, 4 => 21]);
        $this->assertSame(2, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertSame(2, ReviewItem::query()->count());
    }

    public function test_hook_in_work_on_homework(): void
    {
        $this->travelTo('2026-10-24 10:00:00');
        // The offline assistant: the hook must work without any AI call.
        config()->set('services.anthropic.enabled', false);

        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'notes' => 'Pflanzen brauchen Licht, Wasser und Kohlendioxid. Chlorophyll nimmt das Licht auf. '
                .'Dabei entsteht Zucker und Sauerstoff.',
        ]);
        $comprehension = $this->question($lesson, QuestionType::Comprehension, 1);
        $application = $this->question($lesson, QuestionType::Application, 2);
        $reflection = $this->question($lesson, QuestionType::Reflection, 3);

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her. '
                    .'Das Chlorophyll nimmt das Licht auf und dabei entsteht Sauerstoff.',
                'answers' => [
                    $comprehension->id => 'Licht, Wasser und Kohlendioxid sowie Chlorophyll.',
                    $application->id => 'Eine Pflanze im dunklen Zimmer wächst nicht, weil ihr das Licht fehlt.',
                    $reflection->id => 'Der Versuch mit der Wasserpflanze, die Blasen gemacht hat.',
                ],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');

        $this->assertTrue(Submission::query()->sole()->isEvaluated());
        $this->assertSame(2, ReviewItem::query()->count());
        $this->assertSame(0, ReviewItem::query()->where('question_id', $reflection->id)->count());

        foreach (ReviewItem::query()->get() as $item) {
            $this->assertSame($student->id, $item->student_id);
            $this->assertSame(1, $item->box);
            $this->assertSame('2026-10-25', $item->due_on->toDateString());
        }
    }

    public function test_rating_never_touches_scores(): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $question = $this->question($lesson, QuestionType::Comprehension, 1);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(72)->create([
            'feedback' => 'Solide Arbeit.',
        ]);
        $answer = Answer::factory()->for($submission)->for($question)
            ->mistake(MistakeType::Procedure)
            ->create(['answer' => 'Licht und Wasser reichen schon.', 'score' => 45, 'feedback' => 'Fast.']);
        $item = ReviewItem::factory()->for($student, 'student')->for($question)->create();

        $scheduler = app(ReviewScheduler::class);

        foreach (ReviewRating::cases() as $rating) {
            $scheduler->rate($item, $rating);

            $this->assertSame(45, $answer->refresh()->score, $rating->value);
            $this->assertSame('Fast.', $answer->feedback, $rating->value);
            $this->assertSame(MistakeType::Procedure, $answer->mistake_type, $rating->value);
            $this->assertSame(72, $submission->refresh()->understanding_score, $rating->value);
            $this->assertSame('Solide Arbeit.', $submission->feedback, $rating->value);
        }

        // Positivkontrolle: the ratings did arrive at the item itself.
        $item->refresh();
        $this->assertSame(1, $item->knew_count);
        $this->assertSame(1, $item->hard_count);
        $this->assertSame(1, $item->forgot_count);
        $this->assertSame(1, ReviewItem::query()->count());
    }

    private function question(Lesson $lesson, QuestionType $type, int $position): Question
    {
        return Question::factory()->for($lesson)->create([
            'type' => $type,
            'prompt' => 'Frage '.$position.' zur Stunde: Was gehört dazu?',
            'expected_answer' => 'Licht, Wasser, Kohlendioxid und Chlorophyll.',
            'position' => $position,
        ]);
    }

    /**
     * An evaluated hand-in with one comprehension and one application question, both answered.
     */
    private function evaluatedSubmissionWithTwoQuestions(): Submission
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $comprehension = $this->question($lesson, QuestionType::Comprehension, 1);
        $application = $this->question($lesson, QuestionType::Application, 2);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();
        Answer::factory()->for($submission)->for($comprehension)->create(['answer' => 'Licht, Wasser und Kohlendioxid.']);
        Answer::factory()->for($submission)->for($application)->create(['answer' => 'Eine Pflanze im dunklen Zimmer wächst nicht.']);

        return $submission;
    }

    /**
     * The same two questions, but the hand-in was never evaluated.
     */
    private function openSubmissionWithTwoQuestions(): Submission
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $comprehension = $this->question($lesson, QuestionType::Comprehension, 1);
        $application = $this->question($lesson, QuestionType::Application, 2);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create();
        Answer::factory()->for($submission)->for($comprehension)->create(['answer' => 'Licht, Wasser und Kohlendioxid.']);
        Answer::factory()->for($submission)->for($application)->create(['answer' => 'Eine Pflanze im dunklen Zimmer wächst nicht.']);

        return $submission;
    }
}
