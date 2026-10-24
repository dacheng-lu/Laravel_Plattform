<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\ReviewToday;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\ReviewQueue;
use App\Support\ReviewScheduler;
use App\Support\SchoolDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Heute wiederholen" end to end and without a mock: the productive page, the productive scheduler and the stored rows.
 * Each case follows one card from the queue through reveal and self-assessment back into the queue, so a box that
 * moves in memory but not in the database (or the other way round) fails here.
 */
class ReviewIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string SOLUTION = 'Die Photosynthese wandelt Lichtenergie in Zucker um.';

    public function test_a_due_card_moves_one_box_on_the_real_path(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');

        $student = $this->student();
        $item = $this->dueItem($student, 'Was macht die Photosynthese mit dem Licht?');

        $this->assertSame(5, ReviewQueue::remainingToday($student));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $item->getKey())
            ->assertSee('Was macht die Photosynthese mit dem Licht?')
            ->call('reveal')
            ->assertSee(self::SOLUTION)
            ->call('rate', ReviewRating::Knew->value)
            // The card left the queue, so nothing is left for today.
            ->assertSet('itemId', null)
            ->assertSee('Für heute ist alles wiederholt');

        $item->refresh();

        $this->assertSame(2, $item->box);
        $this->assertNotNull($item->last_reviewed_at);
        $this->assertSame(1, $item->knew_count);
        // intervals[2] = 3 days, counted in the school day.
        $this->assertSame('2026-10-27', $item->due_on->toDateString());

        // The stored row is what the page reads next: one question rated, four left.
        $this->assertSame(4, ReviewQueue::remainingToday($student));
        $this->assertSame(0, ReviewQueue::dueCount($student));
        $this->assertNotContains($item->getKey(), ReviewQueue::dueToday($student)->pluck('id')->all());
    }

    public function test_a_settled_card_never_returns_while_a_box_three_card_does(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');

        $student = $this->student();
        $settled = $this->dueItem($student, 'Welche Farbe hat das Chlorophyll?', box: 4);
        $control = $this->dueItem($student, 'Wozu braucht die Pflanze den Zucker?', box: 3);

        // The oldest card first: the one in box 4.
        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $settled->getKey())
            ->call('reveal')
            ->call('rate', ReviewRating::Knew->value);

        $settled->refresh();

        $finalBox = (int) config('lernplattform.review.final_box');
        $this->assertSame($finalBox, $settled->box);
        $this->assertSame(1, $settled->knew_count);

        // Nothing more today but the control card, which never was rated.
        $this->assertSame([$control->getKey()], ReviewQueue::dueToday($student)->pluck('id')->all());
        $this->assertSame(1, ReviewQueue::dueCount($student));

        // Sixty days later the settled card is still gone – its box counts as learnt.
        $this->travelTo(now()->addDays(60));

        $this->assertNotContains($settled->getKey(), ReviewQueue::dueToday($student)->pluck('id')->all());

        // Positivkontrolle: the card in box 3 is due again, so the empty result is not just an empty queue.
        $this->assertSame([$control->getKey()], ReviewQueue::dueToday($student)->pluck('id')->all());
        $this->assertSame(1, ReviewQueue::dueCount($student));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $control->getKey())
            ->assertSee('Wozu braucht die Pflanze den Zucker?')
            ->assertDontSee('Welche Farbe hat das Chlorophyll?');
    }

    public function test_a_forgotten_card_goes_back_to_box_one_due_tomorrow(): void
    {
        // 22:30 UTC is 00:30 CEST on the 25th: tomorrow in the school's time zone is the 26th.
        $this->travelTo('2026-10-24 22:30:00 UTC');

        $student = $this->student();
        $item = $this->dueItem($student, 'Was macht die Photosynthese mit dem Licht?', box: 3, dueOn: '2026-10-25');

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->call('rate', ReviewRating::Forgot->value)
            ->assertSet('itemId', null);

        $item->refresh();

        $this->assertSame(1, $item->box);
        $this->assertSame(1, $item->forgot_count);
        $this->assertSame('2026-10-26', $item->due_on->toDateString());

        // Positivkontrolle: the card is due again on exactly that day, not before.
        $this->travelTo('2026-10-25 12:00:00 UTC');
        $this->assertSame(0, ReviewQueue::dueCount($student));

        $this->travelTo('2026-10-26 12:00:00 UTC');
        $this->assertSame([$item->getKey()], ReviewQueue::dueToday($student)->pluck('id')->all());
    }

    public function test_the_own_answer_never_reaches_the_database(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');

        $student = $this->student();
        // A really handed in and evaluated answer: the answers table is not empty, so the search below can hit.
        [$item, $storedAnswer] = $this->answeredHomework($student);

        $ownAnswer = 'Meine eigene Antwort zur Photosynthese, nur für mich gedacht.';

        $this->assertGreaterThanOrEqual(12, mb_strlen($ownAnswer));
        $this->assertNotSame($storedAnswer, $ownAnswer);

        // Positivkontrolle first: the very search used below does find stored answer text.
        $this->assertDatabaseHas('answers', ['answer' => $storedAnswer]);
        $this->assertStringContainsString($storedAnswer, $this->storedRows('answers'));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->set('ownAnswer', $ownAnswer)
            ->assertSet('ownAnswer', $ownAnswer)
            ->call('rate', ReviewRating::Knew->value)
            ->assertSet('ownAnswer', '')
            ->assertDontSee($ownAnswer);

        // Every text column of the tables an answer could land in (see the migrations).
        foreach (['answer', 'feedback'] as $column) {
            $this->assertDatabaseMissing('answers', [$column => $ownAnswer]);
        }

        foreach (['summary', 'feedback', 'gaps'] as $column) {
            $this->assertDatabaseMissing('submissions', [$column => $ownAnswer]);
        }

        $this->assertStringNotContainsString($ownAnswer, $this->storedRows('answers'));
        $this->assertStringNotContainsString($ownAnswer, $this->storedRows('submissions'));
        // review_items has no text column at all, so the whole row set is searched as JSON.
        $this->assertStringNotContainsString($ownAnswer, $this->storedRows('review_items'));

        // The hand-in and the rating did arrive, so the negative checks above are not the checks of a dead end.
        $this->assertSame(1, DB::table('answers')->count());
        $this->assertSame($storedAnswer, DB::table('answers')->value('answer'));
        $this->assertSame(2, $item->refresh()->box);
    }

    public function test_a_double_click_does_not_rate_the_same_card_twice(): void
    {
        $this->travelTo('2026-10-24 10:00:00 UTC');

        $student = $this->student();
        $item = $this->dueItem($student, 'Was macht die Photosynthese mit dem Licht?');

        // This browser opens the solution …
        $page = Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->assertSet('revealed', true);

        // … while a second tab (or the first click of a double click) has rated the card already.
        $rated = app(ReviewScheduler::class)->rate($item, ReviewRating::Knew);

        $this->assertSame(2, $rated->box);
        $ratedAt = $rated->last_reviewed_at?->toDateTimeString();
        $this->assertNotNull($ratedAt);

        // The click from the first browser arrives now: no error for the student, and no second box step.
        $page->call('rate', ReviewRating::Knew->value)->assertSuccessful();

        $item->refresh();

        $this->assertSame(2, $item->box);
        $this->assertSame(1, $item->knew_count);
        $this->assertSame($ratedAt, $item->last_reviewed_at?->toDateTimeString());

        // Positivkontrolle: the scheduler itself refuses a second rating on the same school day, too – the next
        // school day moves the card again.
        $again = app(ReviewScheduler::class)->rate($item->fresh(), ReviewRating::Knew)->refresh();

        $this->assertSame(2, $again->box);
        $this->assertSame(1, $again->knew_count);

        $this->travelTo('2026-10-27 10:00:00 UTC');

        $next = app(ReviewScheduler::class)->rate($again->fresh(), ReviewRating::Knew)->refresh();

        $this->assertSame(3, $next->box);
        $this->assertSame(2, $next->knew_count);
    }

    private function student(): User
    {
        return User::factory()->student(SchoolClass::factory()->create())->create();
    }

    /**
     * A card of a lesson that belongs to the student's class and was delegated to them.
     */
    private function dueItem(
        User $student,
        string $prompt,
        string $expectedAnswer = self::SOLUTION,
        int $box = 1,
        ?string $dueOn = null,
    ): ReviewItem {
        $lesson = Lesson::factory()
            ->for($student->schoolClass)
            ->publishedFor([$student])
            ->create();

        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => $prompt,
            'expected_answer' => $expectedAnswer,
        ]);

        return ReviewItem::factory()
            ->for($student, 'student')
            ->for($question)
            ->box($box)
            ->due($dueOn ?? SchoolDay::today()->toDateString())
            ->create();
    }

    /**
     * Homework that was really handed in, evaluated and answered, with a due card for its question.
     *
     * @return array{ReviewItem, string} the due card and the stored answer text
     */
    private function answeredHomework(User $student): array
    {
        $lesson = Lesson::factory()
            ->for($student->schoolClass)
            ->publishedFor([$student])
            ->create();

        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was macht die Photosynthese mit dem Licht?',
            'expected_answer' => self::SOLUTION,
        ]);

        $storedAnswer = 'Licht, Wasser und Kohlendioxid, dazu das Chlorophyll in den Blättern.';

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();
        Answer::factory()->for($submission)->for($question)->create(['answer' => $storedAnswer]);

        $item = ReviewItem::factory()
            ->for($student, 'student')
            ->for($question)
            ->due(SchoolDay::today()->toDateString())
            ->create();

        return [$item, $storedAnswer];
    }

    /**
     * Every stored value of a table as JSON, so a search can prove that a text was written nowhere.
     */
    private function storedRows(string $table): string
    {
        return (string) json_encode(DB::table($table)->get()->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
