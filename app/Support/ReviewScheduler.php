<?php

namespace App\Support;

use App\Models\ReviewItem;
use App\Models\Submission;
use App\QuestionType;
use App\ReviewRating;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps "Heute wiederholen" in shape: new review items after an evaluated submission, and the Leitner box after a
 * student's self-assessment. Intervals and the daily limit come from `lernplattform.review`.
 *
 * Not final, so tests can replace it with a mock.
 * A broken setting never creates or moves an item: the call reports the problem once and does nothing (fail closed).
 * Nothing here ever writes to answers or submissions – self-assessments are practice only.
 */
class ReviewScheduler
{
    /**
     * Question types with a fixed expected answer; a Reflection question has none and is never repeated.
     *
     * @var list<QuestionType>
     */
    private const array REPEATED_TYPES = [QuestionType::Comprehension, QuestionType::Application];

    /**
     * Puts every answered comprehension and application question into box 1, due tomorrow, without duplicates.
     *
     * @return int the number of items that were created
     */
    public function scheduleForSubmission(Submission $submission): int
    {
        if (! config('lernplattform.review.enabled') || ! $submission->isEvaluated()) {
            return 0;
        }

        // Read and check the interval setting once per call; a broken setting reports once and schedules nothing.
        $settings = self::settings();

        if ($settings === null) {
            return 0;
        }

        $dueOn = today()->addDays($settings['intervals'][1]);
        $created = 0;

        foreach ($submission->answers()->with('question')->get() as $answer) {
            $question = $answer->question;

            // A blank answer is not "answered", and a Reflection question has no solution to repeat.
            if (! in_array($question?->type, self::REPEATED_TYPES, true) || trim((string) $answer->answer) === '') {
                continue;
            }

            $item = ReviewItem::query()->firstOrCreate(
                ['student_id' => $submission->student_id, 'question_id' => $question->getKey()],
                ['box' => 1, 'due_on' => $dueOn],
            );

            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Moves the item one box on ("knew"), resets its interval in the same box ("hard") or sends it back to box 1
     * ("forgot") and counts the rating.
     */
    public function rate(ReviewItem $item, ReviewRating $rating): ReviewItem
    {
        return DB::transaction(function () use ($item, $rating): ReviewItem {
            // Another tab may have rated the item in the meantime: read the row again, locked, and work on that.
            $locked = ReviewItem::query()->lockForUpdate()->findOrFail($item->id);

            // Read and check the interval setting once per call, so a broken setting reports at most once.
            $settings = self::settings();

            // A broken setting changes nothing (fail closed) – just like a settled item below.
            if ($settings === null) {
                return $locked;
            }

            $finalBox = $settings['final_box'];

            if ($locked->box >= $finalBox) {
                return $locked;
            }

            $box = match ($rating) {
                ReviewRating::Knew => min($locked->box + 1, $finalBox),
                ReviewRating::Hard => $locked->box,
                ReviewRating::Forgot => 1,
            };

            // Only a box below final_box gets a new due date; a settled item keeps its date. Whether it is still
            // listed is up to the queue (box < final_box), which is checked against the productive page.
            $dueOn = $locked->due_on;

            if ($box < $finalBox) {
                $dueOn = today()->addDays($settings['intervals'][$box]);
            }

            $locked->update([
                'box' => $box,
                'due_on' => $dueOn,
                'last_reviewed_at' => now(),
                'knew_count' => $locked->knew_count + ($rating === ReviewRating::Knew ? 1 : 0),
                'hard_count' => $locked->hard_count + ($rating === ReviewRating::Hard ? 1 : 0),
                'forgot_count' => $locked->forgot_count + ($rating === ReviewRating::Forgot ? 1 : 0),
            ]);

            return $locked;
        });
    }

    /**
     * The review settings both methods need: the days per box and the box that counts as settled.
     *
     * Read and checked once per call, so a broken setting is reported at most once. Null means "unusable":
     * then nothing is created or moved (fail closed), because a guessed interval would teach the wrong rhythm.
     *
     * @return array{intervals: array<int, int>, final_box: int}|null
     */
    private static function settings(): ?array
    {
        $intervals = config('lernplattform.review.intervals');
        $finalBox = config('lernplattform.review.final_box');

        $usable = is_array($intervals) && is_int($finalBox) && $finalBox >= 2;
        $boxes = $usable ? range(1, $finalBox - 1) : [];

        // Every box below final_box needs a sane interval, otherwise a rating could not move its item.
        foreach ($boxes as $box) {
            $days = $intervals[$box] ?? null;

            if (! is_int($days) || $days < 1) {
                $usable = false;
            }
        }

        if (! $usable) {
            report(new RuntimeException(
                'Ungültige Wiederholungs-Einstellung: lernplattform.review.intervals braucht für jede Box unter '
                .'final_box eine ganze Zahl ab 1, lernplattform.review.final_box eine ganze Zahl ab 2.',
            ));

            return null;
        }

        return ['intervals' => $intervals, 'final_box' => $finalBox];
    }
}
