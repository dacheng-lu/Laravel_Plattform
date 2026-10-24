<?php

namespace App\Filament\Pages;

use App\Models\ReviewItem;
use App\Models\User;
use App\ReviewRating;
use App\Support\ReviewQueue;
use App\Support\ReviewScheduler;
use App\Support\SchoolDay;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * "Heute wiederholen": one due question at a time. The student answers from memory, opens the sample solution and then
 * rates their own recall. Practice only – the rating moves the Leitner box and nothing else, never a score, the
 * Lernstand or a grade. What the student types is shown back to them on this page and never stored.
 */
class ReviewToday extends Page
{
    /** More than this is an essay, not a memory jog; and nothing of it is stored anyway. */
    private const int MAX_OWN_ANSWER_LENGTH = 2000;

    protected string $view = 'filament.pages.review-today';

    protected static ?string $title = 'Heute wiederholen';

    protected static ?string $slug = 'wiederholen';

    protected static string|UnitEnum|null $navigationGroup = 'Lernen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 3;

    /** The question on screen; null when nothing is left for today. */
    public ?int $itemId = null;

    /**
     * The sample solution is only part of the response once the student asked for it. Locked, because only the server
     * decides that: a client that sets it itself would show itself the solution without asking (and could skip the
     * answer-from-memory step), so Livewire rejects such an update.
     */
    #[Locked]
    public bool $revealed = false;

    /**
     * What the student typed: wird nie gespeichert; bleibt nur im Browser der Schülerin (Livewire-Zustand).
     * It is capped below and shown back to her on this page, never written to the database and never rated by anyone.
     */
    public string $ownAnswer = '';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isStudent()
            && (bool) config('lernplattform.review.enabled');
    }

    public function mount(): void
    {
        $this->itemId = ReviewQueue::dueToday($this->student())->first()?->getKey();
    }

    /**
     * Caps what the student typed before the page uses it anywhere. Nothing of it is stored, so a pasted essay would
     * only bloat the request.
     */
    public function updatedOwnAnswer(): void
    {
        $this->ownAnswer = mb_substr($this->ownAnswer, 0, self::MAX_OWN_ANSWER_LENGTH);
    }

    /**
     * Shows the sample solution of the current question.
     */
    public function reveal(): void
    {
        $this->currentItem();

        $this->revealed = true;
    }

    /**
     * Takes the self-assessment, lets the scheduler move the item on and shows the next question.
     *
     * A card that was already rated on this school day is not rated a second time: the second click of a double
     * click, or a second tab, arrives with the state from before the first click, and that is no error for the
     * student – the page simply shows what is still due.
     */
    public function rate(string $rating): void
    {
        $reviewRating = ReviewRating::tryFrom($rating);

        // Self-assessing needs the solution on screen and one of the three ratings.
        if (! $this->revealed || $reviewRating === null) {
            abort(422);
        }

        if ($this->ratedOnThisSchoolDay()) {
            $this->ownAnswer = '';
            $this->revealed = false;
            $this->itemId = ReviewQueue::dueToday($this->student())->first()?->getKey();

            return;
        }

        $item = $this->currentItem();

        app(ReviewScheduler::class)->rate($item, $reviewRating);

        $this->ownAnswer = '';
        $this->revealed = false;
        $this->itemId = ReviewQueue::dueToday($this->student())
            ->first(fn (ReviewItem $candidate): bool => $candidate->getKey() !== $item->getKey())?->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $student = $this->student();

        $item = $this->item();

        return [
            'item' => $item,
            'prompt' => $item?->question?->prompt,
            // Before the reveal the solution is not part of the response at all, not merely hidden by CSS.
            'expectedAnswer' => $this->revealed ? $item?->question?->expected_answer : null,
            'remaining' => ReviewQueue::remainingToday($student),
            'dueCount' => ReviewQueue::dueCount($student),
            'maxOwnAnswer' => self::MAX_OWN_ANSWER_LENGTH,
            'ratings' => ReviewRating::cases(),
        ];
    }

    /**
     * The item currently on screen, if it is still due for this student.
     */
    private function item(): ?ReviewItem
    {
        if ($this->itemId === null) {
            return null;
        }

        return ReviewQueue::dueToday($this->student())
            ->first(fn (ReviewItem $candidate): bool => $candidate->getKey() === $this->itemId);
    }

    /**
     * The item of the current request, re-checked against the queue: a stale or foreign id in the browser is a 403.
     */
    private function currentItem(): ReviewItem
    {
        $item = $this->item();

        if ($item === null) {
            abort(403);
        }

        Gate::authorize('update', $item);

        return $item;
    }

    /**
     * Whether the card on screen is one of the student's own cards that was already rated on this school day.
     *
     * Only the student's own items count, so a foreign or stale id in the browser still ends in the 403 of
     * `currentItem()` instead of being waved through.
     */
    private function ratedOnThisSchoolDay(): bool
    {
        if ($this->itemId === null) {
            return false;
        }

        $item = $this->student()->reviewItems()->whereKey($this->itemId)->first();

        if ($item === null || $item->last_reviewed_at === null) {
            return false;
        }

        return $item->last_reviewed_at >= SchoolDay::startUtc();
    }

    private function student(): User
    {
        /** @var User $student */
        $student = Filament::auth()->user();

        return $student;
    }
}
