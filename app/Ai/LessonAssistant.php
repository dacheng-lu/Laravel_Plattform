<?php

namespace App\Ai;

use App\MistakeType;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Submission;
use App\QuestionType;
use Illuminate\Support\Collection;

/**
 * Everything the platform asks an AI to do. Implementations only compute results; callers persist them.
 *
 * Bound in AppServiceProvider: ClaudeLessonAssistant when `services.anthropic.enabled` is true,
 * otherwise the offline DemoLessonAssistant, so the platform works without any API call.
 */
interface LessonAssistant
{
    /**
     * Summarise a lesson from the teacher's notes and worksheets and draft homework questions,
     * each mapped to one of the competencies returned by Competency::forSubject($lesson->subject).
     *
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int|null}>}
     */
    public function draftHomework(Lesson $lesson): array;

    /**
     * Draft a Förderaufgabe: three or four practice questions on one competency, from easier to harder with one
     * application question, for students who showed gaps in the given lessons. `$gaps` are the anonymous hints the AI
     * named when it evaluated their homework. Every question is mapped to `$competency`; the summary says what is practised.
     * `$mistakeType` is the most common mistake type of the group, if there is one, so the task can start where the
     * students got stuck.
     *
     * @param  Collection<int, Lesson>  $sourceLessons  the lessons in which the gap showed
     * @param  list<string>  $gaps
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int}>}
     */
    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null): array;

    /**
     * Check a student's own summary and answers (`$submission->answers`) against the lesson.
     * Scores are 0–100. Per answer, `mistake_type` and `mistake_confidence` may be given as well, both optional and
     * never part of the score: a missing or implausible value simply means "no classification".
     *
     * @return array{understanding_score: int, feedback: string, gaps: list<string>, answers: array<int, array{score: int, feedback: string, mistake_type?: string|null, mistake_confidence?: int|null}>} answers keyed by question id
     */
    public function evaluateSubmission(Submission $submission): array;

    /**
     * The tutor's next follow-up question after the student's latest explanation in the session.
     */
    public function askFollowUp(ExplanationSession $session): string;

    /**
     * Final assessment of how well the student explained the topic, once they end the session.
     *
     * @return array{understanding_score: int, feedback: string}
     */
    public function assessExplanation(ExplanationSession $session): array;
}
