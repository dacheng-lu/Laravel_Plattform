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
        $this->dueItem($student, today()->toDateString(), 'Was macht die Photosynthese mit dem Licht?');

        $page = Livewire::actingAs($student)->test(ReviewToday::class);

        $page->assertSet('revealed', false)
            ->assertSee('Was macht die Photosynthese mit dem Licht?')
            ->assertSee('Lösung zeigen')
            ->assertDontSee(self::SOLUTION)
            ->assertDontSee('Sofort gewusst');

        // Neither the markup nor the JSON Livewire keeps for the next request carries the solution.
        $this->assertStringNotContainsString(self::SOLUTION, $this->snapshotJson($page->html()));

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

        $this->dueItem($student, today()->toDateString(), 'Eigene Frage zum Zellaufbau');
        $foreign = $this->dueItem($classmate, today()->toDateString(), 'Frage der Mitschülerin zum Zellaufbau');

        // The student opens their own page and sees only their own question.
        $this->actingAs($student)
            ->get(ReviewToday::getUrl())
            ->assertOk()
            ->assertSee('Eigene Frage zum Zellaufbau')
            ->assertDontSee('Frage der Mitschülerin zum Zellaufbau');

        // No other role reaches the page at all.
        $teachers = User::factory()->for($student->school)->teacher()->create();
        $admins = User::factory()->for($student->school)->admin()->create();
        $operators = User::factory()->operator()->create();

        $this->actingAs($teachers)->get(ReviewToday::getUrl())->assertForbidden();
        $this->actingAs($admins)->get(ReviewToday::getUrl())->assertForbidden();
        $this->actingAs($operators)->get(ReviewToday::getUrl())->assertForbidden();

        // A foreign item id in the browser reveals and rates nothing.
        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock->shouldNotReceive('rate'));

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('itemId', $foreign->getKey())
            ->call('reveal')
            ->assertForbidden();

        Livewire::actingAs($student)->test(ReviewToday::class)
            ->set('revealed', true)
            ->set('itemId', $foreign->getKey())
            ->call('rate', 'knew')
            ->assertForbidden();
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

        // A class and school change hides the questions of the old school.
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

        $this->mock(ReviewScheduler::class, fn (MockInterface $mock) => $mock
            ->expects('rate')
            ->once()
            ->andReturn($item));

        $page = Livewire::actingAs($student)->test(ReviewToday::class)
            ->call('reveal')
            ->set('ownAnswer', $ownAnswer)
            // Positive control: while the student types, the text is on the page.
            ->assertSet('ownAnswer', $ownAnswer)
            ->assertSee('Deine Antwort (nur für dich)');

        $page->call('rate', 'knew')
            ->assertSet('ownAnswer', '')
            ->assertDontSee($ownAnswer);

        // The text reaches no table, and the counters of the item stay untouched.
        $this->assertDatabaseMissing('answers', ['answer' => $ownAnswer]);
        $this->assertDatabaseCount('answers', 0);
        $this->assertDatabaseCount('review_items', 1);

        $item->refresh();
        $this->assertSame(1, $item->box);
        $this->assertNull($item->last_reviewed_at);
        $this->assertSame(0, $item->knew_count);
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
     * A page without the attribute is covered by the plain HTML assertions, so this check never goes quiet.
     */
    private function snapshotJson(string $html): string
    {
        $marker = 'wire:snapshot="';
        $start = strpos($html, $marker);

        if ($start === false) {
            return $html;
        }

        $start += strlen($marker);
        $end = strpos($html, '"', $start);

        if ($end === false) {
            return $html;
        }

        $snapshot = json_decode(html_entity_decode(substr($html, $start, $end - $start)), true);

        return $snapshot === null ? $html : (string) json_encode($snapshot, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
