<?php

namespace App\Policies;

use App\Models\Answer;
use App\Models\User;
use App\Support\LearningInsights;

/**
 * Answers are part of their submission and follow its rules.
 */
class AnswerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Answer $answer): bool
    {
        return $user->can('view', $answer->submission);
    }

    public function create(User $user): bool
    {
        return $user->isStudent();
    }

    public function update(User $user, Answer $answer): bool
    {
        return false;
    }

    public function delete(User $user, Answer $answer): bool
    {
        return $user->can('delete', $answer->submission);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * Only the teacher of the lesson and the school's admins may re-classify an answer, and only for a student they
     * may see anyway (cross-school never). The teacher's own classification wins over the AI's hint.
     */
    public function correctMistake(User $user, Answer $answer): bool
    {
        return ($user->isTeacher() || $user->isAdmin())
            && $user->can('view', $answer->submission)
            && LearningInsights::canView($user, $answer->submission->student);
    }
}
