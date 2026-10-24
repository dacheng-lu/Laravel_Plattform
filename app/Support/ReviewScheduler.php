<?php

namespace App\Support;

use App\Models\ReviewItem;
use App\Models\Submission;
use App\ReviewRating;

/**
 * Keeps "Heute wiederholen" in shape: new review items after an evaluated submission, and the Leitner box after a
 * student's self-assessment. Intervals and the daily limit come from `lernplattform.review`.
 *
 * Not final, so tests can replace it with a mock.
 * Right now both methods are no-ops; the scheduling logic follows in lane A1.
 */
class ReviewScheduler
{
    /**
     * Puts every answered comprehension and application question into box 1, due tomorrow, without duplicates.
     *
     * @return int the number of items that were created
     */
    public function scheduleForSubmission(Submission $submission): int
    {
        return 0;
    }

    /**
     * Moves the item one box on ("knew"), resets its interval in the same box ("hard") or sends it back to box 1
     * ("forgot") and counts the rating.
     */
    public function rate(ReviewItem $item, ReviewRating $rating): ReviewItem
    {
        return $item;
    }
}
