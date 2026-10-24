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
use App\Support\SchoolDay;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The Leitner side of "Heute wiederholen": which questions become review items, how a self-assessment moves them
 * on and that neither ever touches a score.
 */
class ReviewSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Die aufgelöste Zeitzone gilt pro Prozess; jeder Test startet mit einer frischen Auflösung.
        SchoolDay::flush();
    }

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

    public function test_a_settled_item_is_not_changed_by_further_ratings(): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $item = ReviewItem::factory()->box(4)->due('2026-10-24')->create(['knew_count' => 0]);

        $rated = app(ReviewScheduler::class)->rate($item, ReviewRating::Knew)->refresh();

        $finalBox = config('lernplattform.review.final_box');

        $this->assertSame($finalBox, $rated->box);
        $this->assertSame(1, $rated->knew_count);
        // A settled item (box final_box) keeps its due date and counts as learnt. That "Heute wiederholen" then
        // no longer lists it (only box < final_box is asked) is checked against the productive queue in the
        // integration, not here.
        $this->assertSame('2026-10-24', $rated->due_on->toDateString());
        $this->assertSame(now()->toDateTimeString(), $rated->last_reviewed_at?->toDateTimeString());

        $ratedAt = $rated->last_reviewed_at?->toDateTimeString();

        // A day later a rating on the settled item changes nothing at all, not even the timestamp (fail closed).
        $this->travelTo('2026-10-25 09:30:00');

        $again = app(ReviewScheduler::class)->rate($rated, ReviewRating::Forgot)->refresh();

        $this->assertSame($finalBox, $again->box);
        $this->assertSame('2026-10-24', $again->due_on->toDateString());
        $this->assertSame(1, $again->knew_count);
        $this->assertSame(0, $again->hard_count);
        $this->assertSame(0, $again->forgot_count);
        $this->assertSame($ratedAt, $again->last_reviewed_at?->toDateTimeString());
    }

    public function test_due_dates_follow_the_school_day_not_the_utc_day(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');

        // Positivkontrolle: mittags sind UTC-Tag und Schultag derselbe, „morgen“ ist der 25.
        $daytime = $this->evaluatedSubmissionWithTwoQuestions();
        $this->assertSame(2, app(ReviewScheduler::class)->scheduleForSubmission($daytime));
        $this->assertSame(['2026-10-25', '2026-10-25'], $this->dueDatesOf($daytime));

        // 22:30 UTC ist 00:30 MESZ am 25.: der Schultag ist der 25., „morgen“ also der 26. – in UTC wäre es der 25.
        $this->travelTo('2026-10-24 22:30:00 UTC');
        $this->assertSame('2026-10-25', SchoolDay::today()->toDateString());

        $late = $this->evaluatedSubmissionWithTwoQuestions();
        $this->assertSame(2, app(ReviewScheduler::class)->scheduleForSubmission($late));
        $this->assertSame(['2026-10-26', '2026-10-26'], $this->dueDatesOf($late));
    }

    public function test_a_rating_after_midnight_counts_for_the_new_school_day(): void
    {
        $this->travelTo('2026-10-24 22:30:00 UTC');

        $item = ReviewItem::factory()->box(3)->due('2026-10-25')->create();

        $rated = app(ReviewScheduler::class)->rate($item, ReviewRating::Forgot)->refresh();

        $this->assertSame(1, $rated->box);
        // Box 1 ist am nächsten Schultag wieder dran: der 26., nicht der 25.
        $this->assertSame('2026-10-26', $rated->due_on->toDateString());
        $this->assertSame(now()->toDateTimeString(), $rated->last_reviewed_at?->toDateTimeString());
    }

    public function test_an_unusable_school_time_zone_falls_back_to_berlin(): void
    {
        $this->travelTo('2026-10-24 22:30:00 UTC');

        config()->set('lernplattform.timezone', 'Nicht/Gueltig');

        // Statt einer geratenen Zeitzone gilt Europe/Berlin: Schultag ist der 25., Tagesbeginn 22:00 UTC am 24.
        $this->assertSame('2026-10-25', SchoolDay::today()->toDateString());
        $this->assertSame('2026-10-24 22:00:00', SchoolDay::startUtc()->toDateTimeString());

        // Positivkontrolle: der übliche Weg kommt mit dem Ersatz zu demselben Fälligkeitsdatum wie mit gültiger Zone.
        $submission = $this->evaluatedSubmissionWithTwoQuestions();
        $this->assertSame(2, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertSame(['2026-10-26', '2026-10-26'], $this->dueDatesOf($submission));
    }

    public function test_an_unusable_school_time_zone_is_reported_once_per_request(): void
    {
        $this->travelTo('2026-10-24 22:30:00 UTC');

        config()->set('lernplattform.timezone', 'Nicht/Gueltig');

        // Die Auflösung passiert einmal pro Request, nicht pro Abfrage: sonst würde eine kaputte Einstellung
        // bei jeder Fälligkeitsprüfung erneut gemeldet.
        $this->mock(ExceptionHandler::class, fn (MockInterface $mock) => $mock->expects('report')
            ->once()
            ->with(Mockery::type(RuntimeException::class)));

        for ($call = 0; $call < 3; $call++) {
            $this->assertSame('2026-10-25', SchoolDay::today()->toDateString());
        }

        // Positivkontrolle: der Ersatz greift auch im zweiten Zugriff (Tagesbeginn in UTC).
        $this->assertSame('2026-10-24 22:00:00', SchoolDay::startUtc()->toDateTimeString());
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

    public function test_a_broken_setting_is_reported_once_per_call(): void
    {
        $this->travelTo('2026-10-24 09:00:00');

        // Three answered comprehension/application questions: a loop that looked the interval up per answer could
        // report three times for one hand-in.
        $submission = $this->evaluatedSubmissionWithThreeQuestions();

        $this->mock(ExceptionHandler::class, fn (MockInterface $mock) => $mock->expects('report')
            ->once()
            ->with(Mockery::type(RuntimeException::class)));

        config()->set('lernplattform.review.intervals', []);

        $this->assertSame(0, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertDatabaseEmpty('review_items');

        // Positivkontrolle: with usable intervals the same hand-in creates its three items, and the "once" above
        // still holds for both calls together – a working setting reports nothing.
        config()->set('lernplattform.review.intervals', [1 => 1, 2 => 3, 3 => 7, 4 => 21]);

        $this->assertSame(3, app(ReviewScheduler::class)->scheduleForSubmission($submission));
        $this->assertSame(3, ReviewItem::query()->count());
    }

    public function test_a_broken_setting_stops_a_rating(): void
    {
        $this->travelTo('2026-10-24 08:00:00');

        $item = ReviewItem::factory()->box(2)->due('2026-10-24')->create();

        $this->mock(ExceptionHandler::class, fn (MockInterface $mock) => $mock->expects('report')
            ->once()
            ->with(Mockery::type(RuntimeException::class)));

        // The settled box is unusable here, so the rating may not guess what to do with it.
        config()->set('lernplattform.review.final_box', 'fünf');

        $rated = app(ReviewScheduler::class)->rate($item, ReviewRating::Knew)->refresh();

        $this->assertSame(2, $rated->box);
        $this->assertSame('2026-10-24', $rated->due_on->toDateString());
        $this->assertSame(0, $rated->knew_count);
        $this->assertNull($rated->last_reviewed_at);

        // Positivkontrolle: with a usable setting the very same rating moves the item on, without a further report.
        config()->set('lernplattform.review.final_box', 5);

        $moved = app(ReviewScheduler::class)->rate($rated, ReviewRating::Knew)->refresh();

        $this->assertSame(3, $moved->box);
        $this->assertSame(1, $moved->knew_count);
        $this->assertSame('2026-10-31', $moved->due_on->toDateString());
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

        // One rating per card and school day (a double click must not count twice), so each rating gets its own day.
        foreach (ReviewRating::cases() as $day => $rating) {
            $this->travelTo(sprintf('2026-10-%02d 08:00:00', 24 + $day));

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

    /**
     * The due dates of the items of one hand-in's student, oldest question first.
     *
     * @return list<string>
     */
    private function dueDatesOf(Submission $submission): array
    {
        return ReviewItem::query()
            ->where('student_id', $submission->student_id)
            ->orderBy('question_id')
            ->get()
            ->map(fn (ReviewItem $item): string => $item->due_on->toDateString())
            ->values()
            ->all();
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
     * An evaluated hand-in with three answered comprehension/application questions.
     */
    private function evaluatedSubmissionWithThreeQuestions(): Submission
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $questions = [
            $this->question($lesson, QuestionType::Comprehension, 1),
            $this->question($lesson, QuestionType::Application, 2),
            $this->question($lesson, QuestionType::Comprehension, 3),
        ];

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();

        foreach ($questions as $question) {
            Answer::factory()->for($submission)->for($question)->create(['answer' => 'Licht, Wasser und Kohlendioxid.']);
        }

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
