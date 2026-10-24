<?php

namespace App\Policies;

use App\Models\ReviewItem;
use App\Models\User;

/**
 * A review item is private to the student it belongs to. No teacher, no administration and no classmate reads or rates
 * one: the self-assessment is practice only and never reaches a score, the Lernstand or the parents' report.
 */
class ReviewItemPolicy
{
    public function view(User $user, ReviewItem $item): bool
    {
        return $user->isStudent() && $item->student_id === $user->getKey();
    }

    public function update(User $user, ReviewItem $item): bool
    {
        return $user->isStudent() && $item->student_id === $user->getKey();
    }
}
