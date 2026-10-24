<?php

namespace App\Filament\Pages;

use App\Models\ReviewItem;
use App\Models\User;
use App\ReviewRating;
use App\Support\ReviewQueue;
use App\Support\ReviewScheduler;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
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

    /** The sample solution is only part of the response once the student asked for it. */
    public bool $revealed = false;

    /** Only for the student's own eyes on this page: never stored, never sent anywhere, and capped below. */
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
     */
    public function rate(string $rating): void
    {
        $item = $this->currentItem();
        $reviewRating = ReviewRating::tryFrom($rating);

        // Self-assessing needs the solution on screen and one of the three ratings.
        if (! $this->revealed || $reviewRating === null) {
            abort(422);
        }

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

    private function student(): User
    {
        /** @var User $student */
        $student = Filament::auth()->user();

        return $student;
    }
}
