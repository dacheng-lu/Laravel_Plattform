<?php

namespace App\Support;

use App\Ai\LessonAssistant;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Förderaufgaben auf Knopfdruck": a Förderaufgabe is an ordinary Lesson (so distribution, hand-in and AI evaluation
 * work unchanged) whose `practice_competency_id` names the competency it practises.
 */
class PracticeTasks
{
    /**
     * Newest lessons the AI draft draws on: enough context, few enough notes for a small request.
     */
    private const int MAX_SOURCE_LESSONS = 5;

    private const int MAX_GAPS = 8;

    /**
     * Drafts a Förderaufgabe with the assistant and stores it for the class, delegated to the given students.
     * Without a due date it stays a draft for the teacher to review; with one it is published right away.
     * Nothing is stored when the assistant fails. The callers authorize and validate the students.
     * The draft draws on ordinary lessons only (never on an earlier Förderaufgabe) and on the notes of those lessons
     * the teacher may view: of a colleague's lesson, only its topic and date reach the draft and the stored notes.
     * It also gets the most common mistake type the supported students made in that competency, if one was classified.
     *
     * @param  array{student_ids: list<int>, lesson_ids: list<int>}  $support  see LearningInsights::competencySupport()
     * @param  list<int>  $studentIds  students of the class who receive the task
     */
    public static function create(User $teacher, SchoolClass $schoolClass, Competency $competency, array $support, array $studentIds, ?CarbonInterface $dueOn = null): Lesson
    {
        $sourceLessons = Lesson::query()
            ->whereKey($support['lesson_ids'])
            ->whereBelongsTo($schoolClass)
            ->whereNull('practice_competency_id')
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->limit(self::MAX_SOURCE_LESSONS)
            ->get()
            // In memory only, never saved: the notes of a lesson the teacher may not view must not leave it.
            ->each(function (Lesson $lesson) use ($teacher): void {
                if (Gate::forUser($teacher)->denies('view', $lesson)) {
                    $lesson->notes = null;
                }
            });

        $subject = $competency->subject
            ?? $sourceLessons->countBy('subject')->sortDesc()->keys()->first()
            ?? 'Fachübergreifend';

        $gaps = Submission::query()
            ->whereIn('lesson_id', $sourceLessons->modelKeys())
            ->whereIn('student_id', $support['student_ids'])
            ->whereNotNull('evaluated_at')
            ->pluck('gaps')
            ->flatten()
            ->filter(fn (mixed $gap): bool => is_string($gap) && trim($gap) !== '')
            ->unique()
            ->take(self::MAX_GAPS)
            ->values()
            ->all();

        $draft = app(LessonAssistant::class)->draftPracticeTask($competency, $subject, $sourceLessons, $gaps, MistakeInsights::mostCommon($support['student_ids'], $competency->getKey(), $sourceLessons->modelKeys()));

        return DB::transaction(function () use ($teacher, $schoolClass, $competency, $subject, $sourceLessons, $draft, $studentIds, $dueOn): Lesson {
            $lesson = Lesson::query()->create([
                'school_class_id' => $schoolClass->getKey(),
                'teacher_id' => $teacher->getKey(),
                'subject' => $subject,
                'topic' => Str::limit("Förderaufgabe: {$competency->name}", 255, ''),
                'held_on' => today(),
                'notes' => static::notes($competency, $sourceLessons),
                'summary' => $draft['summary'],
                'practice_competency_id' => $competency->getKey(),
                'published_at' => $dueOn === null ? null : now(),
                'due_on' => $dueOn,
            ]);

            // AI output is untrusted: whatever competency it names, every question practises the chosen one.
            $lesson->questions()->createMany(collect($draft['questions'])->values()->map(fn (array $question, int $index): array => [
                'type' => $question['type'],
                'prompt' => $question['prompt'],
                'expected_answer' => $question['expected_answer'],
                'competency_id' => $competency->getKey(),
                'position' => $index + 1,
            ])->all());

            $lesson->students()->sync($studentIds);

            return $lesson;
        });
    }

    /**
     * The Förderaufgaben already created for a class, newest first, grouped by the competency they practise, with
     * hand-in counts. Other teachers' drafts stay invisible.
     *
     * @return Collection<int, Collection<int, Lesson>> keyed by competency id
     */
    public static function forClass(User $viewer, int $schoolClassId): Collection
    {
        return Lesson::query()
            ->where('school_class_id', $schoolClassId)
            ->whereNotNull('practice_competency_id')
            ->withCount(['students', 'submissions'])
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Lesson $lesson): bool => $lesson->isPublished() || $viewer->can('update', $lesson))
            ->groupBy('practice_competency_id');
    }

    /**
     * Those of the given students who still hold an open Förderaufgabe for the competency: delegated to them, published
     * and not yet handed in.
     *
     * @param  list<int>  $studentIds
     * @return list<int>
     */
    public static function studentIdsWithOpenTask(array $studentIds, int $competencyId): array
    {
        return User::query()
            ->whereKey($studentIds)
            ->whereHas('assignedLessons', fn (Builder $lessons) => $lessons
                ->published()
                ->where('practice_competency_id', $competencyId)
                ->whereDoesntHave('submissions', fn (Builder $submissions) => $submissions->whereColumn('submissions.student_id', 'users.id')))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The notes the AI later evaluates the hand-ins against: what is practised and what the lessons were about.
     *
     * @param  Collection<int, Lesson>  $sourceLessons
     */
    private static function notes(Competency $competency, Collection $sourceLessons): string
    {
        return collect([
            "Förderaufgabe zur Kompetenz „{$competency->name}“.",
            $competency->description,
            ...$sourceLessons->map(fn (Lesson $lesson): string => "Aus der Stunde „{$lesson->topic}“ vom {$lesson->held_on->format('d.m.Y')}".($lesson->notes === null ? '.' : ":\n".Str::limit($lesson->notes, 1500)))->all(),
        ])->filter()->implode("\n\n");
    }
}
