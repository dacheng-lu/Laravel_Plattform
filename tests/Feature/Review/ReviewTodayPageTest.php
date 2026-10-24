<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\ReviewToday;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\ReviewQueue;
use App\Support\ReviewScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The "Heute wiederholen" page: a few due questions per day, the solution only after the student asks for it, and no
 * question of another school. Every negative check has a positive control in the same test.
 */
class ReviewTodayPageTest extends TestCase
{
    use RefreshDatabase;

    private const string SOLUTION = 'Die Photosynthese wandelt Lichtenergie in Zucker um.';

    public function test_limit_and_order(): void
    {
        $this->travelTo('2026-10-24 09:00:00');

        $student = $this->student();

        // Seven questions are due, one per day, so the oldest due date decides the order.
        $items = [];
        foreach (['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $number => $dueOn) {
            $items[$dueOn] = $this->dueItem($student, $dueOn, "Frage {$number} aus der Photosynthese-Stunde");
        }

        // The daily limit is five, the two newest questions wait.
        $this->assertSame(5, ReviewQueue::remainingToday($student));
        $this->assertSame(
            [$items['2026-10-18']->getKey(), $items['2026-10-19']->getKey(), $items['2026-10-20']->getKey(), $items['2026-10-21']->getKey(), $items['2026-10-22']->getKey()],
            ReviewQueue::dueToday($student)->pluck('id')->all(),
        );

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $items['2026-10-18']->getKey())
            ->assertSee('Frage 0 aus der Photosynthese-Stunde')
            ->assertDontSee('Frage 5 aus der Photosynthese-Stunde')
            ->assertSee('Noch 5 Fragen heute');

        // Two questions were rated today already: the quota drops to three and those two are gone.
        $items['2026-10-18']->update(['last_reviewed_at' => now()]);
        $items['2026-10-19']->update(['last_reviewed_at' => now()->subMinutes(5)]);

        $this->assertSame(3, ReviewQueue::remainingToday($student));
        $this->assertSame(
            [$items['2026-10-20']->getKey(), $items['2026-10-21']->getKey(), $items['2026-10-22']->getKey()],
            ReviewQueue::dueToday($student)->pluck('id')->all(),
        );

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $items['2026-10-20']->getKey())
            ->assertSee('Frage 2 aus der Photosynthese-Stunde')
            ->assertSee('Noch 3 Fragen heute');
    }

    public function test_solution_hidden_until_reveal(): void
    {
        $student = $this->student();
        $item = $this->dueItem($student, today()->toDateString(), 'Was macht die Photosynthese mit dem Licht?');

        $page = Livewire::actingAs($student)->test(ReviewToday::class);

        $page->assertSet('revealed', false)
            ->assertSee('Was macht die Photosynthese mit dem Licht?')
            ->assertSee('Lösung zeigen')
            ->assertDontSee(self::SOLUTION)
            ->assertDontSee('Sofort gewusst');

        // Neither the markup nor the JSON Livewire keeps for the next request carries the solution.
        $html = $page->html();
        $snapshot = $this->snapshotJson($html);

        // Positive control first: the snapshot is not an empty string but carries the open card, and the very same
        // markup does show the question. Without this a "not in there" could come from a check of nothing at all.
        $state = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($item->getKey(), $state['data']['itemId'] ?? null);
        $this->assertStringContainsString('Was macht die Photosynthese mit dem Licht?', $html);

        $this->assertStringNotContainsString(self::SOLUTION, $html);
        $this->assertStringNotContainsString(self::SOLUTION, $snapshot);

        $page->call('reveal')
            ->assertSet('revealed', true)
            ->assertSee(self::SOLUTION)
            ->assertSee('Sofort gewusst')
            ->assertSee('Mit Mühe')
            ->assertSee('Nicht gewusst');

        // Positive control: after the reveal the text is part of the response.
        $this->assertStringContainsString(self::SOLUTION, $page->html());
    }

    public function test_access_and_foreign_items(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();

        $own = $this->dueItem($student, today()->toDateString(), 'Eigene Frage zum Zellaufbau');
        $foreign = $this->dueItem($classmate, today()->toDateString(), 'Frage der Mitschülerin zum Zellaufbau');

        $teacher = User::factory()->for($student->school)->teacher()->create();
        $admin = User::factory()->for($student->school)->admin()->create();
        $operator = User::factory()->operator()->create();

        // The student opens their own page and sees only their own question.
        $this->actingAs($student)
            ->get(ReviewToday::getUrl())
            ->assertOk()
            ->assertSee('Eigene Frage zum Zellaufbau')
            ->assertDontSee('Frage der Mitschülerin zum Zellaufbau');

        // No other role reaches the page at all.
        $this->actingAs($teacher)->get(ReviewToday::getUrl())->assertForbidden();
        $this->actingAs($admin)->get(ReviewToday::getUrl())->assertForbidden();
        $this->actingAs($operator)->get(ReviewToday::getUrl())->assertForbidden();

        // From here on nothing may be rated: the scheduler is never asked.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock->shouldNotReceive('rate'));

        // Positive control: the owner reveals their own solution through the component.
        Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->assertSuccessful()
            ->assertSet('revealed', true);

        $beforeOwn = $this->storedState($own);
        $beforeForeign = $this->storedState($foreign);

        // The student's item is a foreign one for everybody else. Their refusal already happens when the page mounts,
        // and that closes reveal and rate with it: no mounted component, no action to call.
        foreach ([$teacher, $admin, $operator] as $stranger) {
            Livewire::actingAs($stranger)->test(ReviewToday::class)->assertForbidden();
        }

        // A foreign item id in the student's own browser reveals and rates nothing either.
        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('itemId', $foreign->getKey())
            ->call('reveal')
            ->assertForbidden();

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('revealed', true)
            ->set('itemId', $foreign->getKey())
            ->call('rate', 'knew')
            ->assertForbidden();

        // None of the attempts touched an item.
        $this->assertSame($beforeOwn, $this->storedState($own));
        $this->assertSame($beforeForeign, $this->storedState($foreign));
        $this->assertDatabaseCount('review_items', 2);
    }

    public function test_rating_requires_reveal(): void
    {
        $student = $this->student();
        $item = $this->dueItem($student, today()->toDateString());

        // Rating without the solution on screen does nothing.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock->shouldNotReceive('rate'));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('revealed', false)
            ->call('rate', 'knew')
            ->assertStatus(422);

        $item->refresh();
        $this->assertSame(1, $item->box);
        $this->assertNull($item->last_reviewed_at);

        // An unknown rating is refused as well.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock->shouldNotReceive('rate'));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->call('rate', 'foo')
            ->assertStatus(422);

        // With the solution revealed the self-assessment reaches the scheduler exactly once.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock
            ->expects('rate')
            ->once()
            ->withArgs(fn (ReviewItem $rated, ?ReviewRating $rating): bool => $rated->is($item) && $rating === ReviewRating::Knew)
            ->andReturn($item));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->assertSet('revealed', true)
            ->call('rate', 'knew')
            ->assertSet('revealed', false)
            ->assertSet('ownAnswer', '');
    }

    public function test_tenant_filter(): void
    {
        $ownClass = SchoolClass::factory()->create();
        $student = User::factory()->student($ownClass)->create();

        $own = $this->dueItem($student, today()->toDateString(), 'Eigene Frage zu den Zellorganellen');

        // Another school's lesson, delegated to the student all the same.
        $foreignSchoolClass = SchoolClass::factory()->create();
        $foreignSchoolLesson = Lesson::factory()
            ->for($foreignSchoolClass)
            ->publishedFor([$student])
            ->create();
        $this->item($student, $this->question($foreignSchoolLesson, 'Frage aus der Stunde einer anderen Schule'));

        // A published lesson of the own school that was never delegated to this student.
        $unassignedLesson = Lesson::factory()->for($ownClass)->create(['published_at' => now()]);
        $this->item($student, $this->question($unassignedLesson, 'Frage aus einer nie zugewiesenen Stunde'));

        // Delegated, but the homework was never published.
        $unpublishedLesson = Lesson::factory()->for($ownClass)->create();
        $unpublishedLesson->students()->attach($student);
        $this->item($student, $this->question($unpublishedLesson, 'Frage aus einer unveröffentlichten Stunde'));

        // Positive control: the own question is the only one that is due.
        $this->assertSame([$own->getKey()], ReviewQueue::dueToday($student)->pluck('id')->all());

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $own->getKey())
            ->assertSee('Eigene Frage zu den Zellorganellen')
            ->assertDontSee('Frage aus der Stunde einer anderen Schule')
            ->assertDontSee('Frage aus einer nie zugewiesenen Stunde')
            ->assertDontSee('Frage aus einer unveröffentlichten Stunde');

        // A class change inside the same school keeps the student's own question: it comes from their own evaluated
        // answer, and that homework was delegated to them. Decision of the orchestrator: own, answered questions stay.
        $sameSchoolClass = SchoolClass::factory()->for($ownClass->school)->create();
        $student->update(['school_class_id' => $sameSchoolClass->getKey(), 'school_id' => $sameSchoolClass->school_id]);
        $student->refresh();

        $this->assertSame([$own->getKey()], ReviewQueue::dueToday($student)->pluck('id')->all());

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', $own->getKey())
            ->assertSee('Eigene Frage zu den Zellorganellen');

        // A move into another school hides the questions of the old school.
        $newClass = SchoolClass::factory()->create();
        $student->update(['school_class_id' => $newClass->getKey(), 'school_id' => $newClass->school_id]);

        $this->assertCount(0, ReviewQueue::dueToday($student));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->assertSet('itemId', null)
            ->assertSee('Für heute ist alles wiederholt');
    }

    public function test_own_answer_not_stored(): void
    {
        $student = $this->student();
        $item = $this->dueItem($student, today()->toDateString());
        $ownAnswer = 'Meine eigene Antwort zum Zellaufbau, nur für mich gedacht.';

        // Only the item and the rating reach the scheduler: not one character of what the student typed.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock
            ->expects('rate')
            ->once()
            ->withArgs(function (ReviewItem $rated, ReviewRating $rating, mixed ...$rest) use ($item): bool {
                return $rated->is($item) && $rating === ReviewRating::Knew && $rest === [];
            })
            ->andReturn($item));

        $before = $this->storedState($item);

        $page = Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->set('ownAnswer', $ownAnswer)
            // Positive control: while the student types, the text is on the page.
            ->assertSet('ownAnswer', $ownAnswer)
            ->assertSee('Deine Antwort (nur für dich)');

        $page->call('rate', 'knew')
            ->assertSet('ownAnswer', '')
            ->assertDontSee($ownAnswer);

        // The tables are read out and searched, and the search does find text that is stored.
        $this->assertStringContainsString($item->question->prompt, $this->storedRows('questions'));
        $this->assertStringNotContainsString($ownAnswer, $this->storedRows('answers'));
        $this->assertStringNotContainsString($ownAnswer, $this->storedRows('review_items'));

        $this->assertDatabaseMissing('answers', ['answer' => $ownAnswer]);
        $this->assertDatabaseCount('answers', 0);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertSame($before, $this->storedState($item));
    }

    public function test_the_own_answer_is_capped_at_two_thousand_characters(): void
    {
        $student = $this->student();
        $this->dueItem($student, today()->toDateString());

        // 2001 characters are cut back to the limit before the page uses the text anywhere.
        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('ownAnswer', str_repeat('a', 2001))
            ->assertSet('ownAnswer', str_repeat('a', 2000));

        // Positive control: exactly at the limit the text stays untouched and the solution can be revealed.
        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('ownAnswer', str_repeat('b', 2000))
            ->assertSet('ownAnswer', str_repeat('b', 2000))
            ->call('reveal')
            ->assertSet('revealed', true);
    }

    private function student(): User
    {
        return User::factory()->student(SchoolClass::factory()->create())->create();
    }

    /**
     * A question of a lesson that is published and delegated to the student.
     */
    private function dueItem(
        User $student,
        string $dueOn,
        string $prompt = 'Was passiert bei der Photosynthese?',
        string $expectedAnswer = self::SOLUTION,
        ?SchoolClass $schoolClass = null,
    ): ReviewItem {
        $lesson = Lesson::factory()
            ->for($schoolClass ?? $student->schoolClass)
            ->publishedFor([$student])
            ->create();

        return $this->item($student, $this->question($lesson, $prompt, $expectedAnswer), $dueOn);
    }

    private function question(Lesson $lesson, string $prompt, string $expectedAnswer = self::SOLUTION): Question
    {
        return Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => $prompt,
            'expected_answer' => $expectedAnswer,
        ]);
    }

    /**
     * An item in box 1 that is due today, whatever school and delegation its question has.
     */
    private function item(User $student, Question $question, ?string $dueOn = null): ReviewItem
    {
        return ReviewItem::factory()
            ->for($student, 'student')
            ->for($question)
            ->due($dueOn ?? today()->toDateString())
            ->create();
    }

    /**
     * The JSON Livewire keeps for the component between requests: its snapshot, taken from the markup the browser gets.
     * Markup without a readable snapshot would quietly weaken the check, so the test fails instead of checking less.
     */
    private function snapshotJson(string $html): string
    {
        $marker = 'wire:snapshot="';
        $start = strpos($html, $marker);
        $this->assertIsInt($start, 'The page carries a Livewire snapshot.');

        $valueStart = $start + strlen($marker);
        $valueEnd = strpos($html, '"', $valueStart);
        $this->assertIsInt($valueEnd, 'The Livewire snapshot attribute is closed.');

        $snapshot = json_decode(html_entity_decode(substr($html, $valueStart, $valueEnd - $valueStart)), true);
        $this->assertIsArray($snapshot, 'The Livewire snapshot is readable JSON.');

        return (string) json_encode($snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Every stored column of an item, so that "nothing changed" actually compares something.
     *
     * @return array<string, mixed>
     */
    private function storedState(ReviewItem $item): array
    {
        return $item->fresh()->getAttributes();
    }

    /**
     * Every stored value of a table as JSON, so a search can prove that a text was written nowhere.
     */
    private function storedRows(string $table): string
    {
        return (string) json_encode(DB::table($table)->get()->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
