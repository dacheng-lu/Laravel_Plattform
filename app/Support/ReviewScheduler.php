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
 * A broken setting never creates or moves an item: the method reports the problem and does nothing (fail closed).
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

        $firstInterval = self::intervalFor(1);

        if ($firstInterval === null) {
            return 0;
        }

        $dueOn = today()->addDays($firstInterval);
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
            $item->refresh();

            $finalBox = self::finalBox();

            // A settled item (box final_box) is never asked again, and a broken setting changes nothing.
            if ($finalBox === null || $item->box >= $finalBox) {
                return $item;
            }

            $box = match ($rating) {
                ReviewRating::Knew => min($item->box + 1, $finalBox),
                ReviewRating::Hard => $item->box,
                ReviewRating::Forgot => 1,
            };

            // Only a box below final_box gets a new due date; the settled box keeps it and the queue stops asking.
            $dueOn = $item->due_on;

            if ($box < $finalBox) {
                $interval = self::intervalFor($box);

                if ($interval === null) {
                    return $item;
                }

                $dueOn = today()->addDays($interval);
            }

            $item->update([
                'box' => $box,
                'due_on' => $dueOn,
                'last_reviewed_at' => now(),
                'knew_count' => $item->knew_count + ($rating === ReviewRating::Knew ? 1 : 0),
                'hard_count' => $item->hard_count + ($rating === ReviewRating::Hard ? 1 : 0),
                'forgot_count' => $item->forgot_count + ($rating === ReviewRating::Forgot ? 1 : 0),
            ]);

            return $item;
        });
    }

    /**
     * Days until an item of the given box is due again; null (and a report) when the setting is unusable.
     */
    private static function intervalFor(int $box): ?int
    {
        $intervals = config('lernplattform.review.intervals');
        $days = is_array($intervals) ? ($intervals[$box] ?? null) : null;

        if (! is_int($days) || $days < 1) {
            report(new RuntimeException(
                "Ungültiges Wiederholungs-Intervall: lernplattform.review.intervals[{$box}] muss eine ganze Zahl ab 1 sein.",
            ));

            return null;
        }

        return $days;
    }

    /**
     * The box that counts as settled; null (and a report) when the setting is unusable.
     */
    private static function finalBox(): ?int
    {
        $finalBox = config('lernplattform.review.final_box');

        if (! is_int($finalBox) || $finalBox < 2) {
            report(new RuntimeException('Ungültige Einstellung: lernplattform.review.final_box muss eine ganze Zahl ab 2 sein.'));

            return null;
        }

        return $finalBox;
    }
}
