<?php

namespace App\Support;

use App\Models\ReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a student sees in "Heute wiederholen": the questions that are due today, the oldest first, capped by the daily
 * limit. Only questions from lessons of the student's own school that were delegated to them – a question from another
 * school never shows up. Practice only, no score flows back from here.
 */
class ReviewQueue
{
    /**
     * How many questions the student may still repeat today: the daily limit minus what they have already rated today.
     *
     * "Today" is the school day, so a rating at 00:30 CEST counts for the day that has just begun, not for the UTC
     * day that is still running.
     */
    public static function remainingToday(User $student): int
    {
        $ratedToday = $student->reviewItems()
            ->where('last_reviewed_at', '>=', SchoolDay::startUtc())
            ->count();

        return max(0, (int) config('lernplattform.review.daily_limit') - $ratedToday);
    }

    /**
     * The questions on screen today, the oldest due date first.
     *
     * @return Collection<int, ReviewItem>
     */
    public static function dueToday(User $student): Collection
    {
        $remaining = self::remaining($student);

        if ($remaining === 0) {
            return collect();
        }

        return self::query($student)->with('question')->limit($remaining)->get();
    }

    /**
     * How many questions the student would see today; never more than the daily limit still allows.
     */
    public static function dueCount(User $student): int
    {
        $remaining = self::remaining($student);

        if ($remaining === 0) {
            return 0;
        }

        return min($remaining, self::query($student)->count());
    }

    /**
     * The daily quota left, or 0 when repeating is switched off or the user is not a student of a school.
     */
    private static function remaining(User $student): int
    {
        $mayRepeat = $student->isStudent()
            && $student->school_id !== null
            && (bool) config('lernplattform.review.enabled');

        return $mayRepeat ? self::remainingToday($student) : 0;
    }

    /**
     * Due items of this student: not settled yet, not already rated today and from a lesson of their own school that
     * was delegated to them.
     *
     * @return Builder<ReviewItem>
     */
    private static function query(User $student): Builder
    {
        return ReviewItem::query()
            ->where('student_id', $student->getKey())
            ->where('box', '<', (int) config('lernplattform.review.final_box'))
            // A school day, not the UTC day: at 00:30 CEST the questions of the new day are due.
            ->whereDate('due_on', '<=', SchoolDay::today())
            // An item rated today waits for its next interval, even while its due date still says today.
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_reviewed_at')
                ->orWhere('last_reviewed_at', '<', SchoolDay::startUtc()))
            // The school check keeps a school change from surfacing questions of the old school. There is deliberately
            // no class filter: items only come from the student's own evaluated answers, so after a class change inside
            // the same school their own questions stay visible – that homework was delegated to them.
            ->whereHas('question.lesson', fn (Builder $lesson): Builder => $lesson
                ->assignedTo($student)
                ->whereHas('schoolClass', fn (Builder $schoolClass): Builder => $schoolClass->where('school_id', $student->school_id)))
            ->orderBy('due_on')
            ->orderBy('id');
    }
}
