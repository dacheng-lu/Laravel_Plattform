<?php

namespace App\Support;

use App\MistakeType;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The aggregates behind the "Statistik" page: hand-in rate, average understanding, support share, saved correction
 * time, the understanding trend, competency averages, mistake types per subject and "Erklär's mir" usage.
 *
 * Everything is a class average. Homework counts for hand-in rate, understanding and trend; Förderaufgaben reach
 * only a few students and count for competencies, mistake types and the saved time. Groups smaller than
 * `statistics.min_group` are suppressed, private self-assessments ("Heute wiederholen") are never read, and only a
 * viewer who may see the class gets numbers at all (see LearningInsights::visibleClasses()).
 */
final class ClassStatistics
{
    private function __construct(
        private readonly SchoolClass $schoolClass,
        private readonly ?string $subject,
        private readonly int $weeks,
    ) {}

    /**
     * Statistics of one class for one viewer, re-authorized on every call because the class comes from a URL or a
     * Livewire property. Periods the page does not offer fall back to the configured default.
     */
    public static function forViewer(User $viewer, int $schoolClassId, ?string $subject = null, int $weeks = 10): self
    {
        $schoolClass = LearningInsights::visibleClasses($viewer)->whereKey($schoolClassId)->first();

        abort_if($schoolClass === null, 403);

        return new self($schoolClass, blank($subject) ? null : $subject, self::period($weeks));
    }

    public function schoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    /**
     * The subjects of the class's published lessons, alphabetically.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return Lesson::query()
            ->where('school_class_id', $this->schoolClass->getKey())
            ->published()
            ->orderBy('subject')
            ->pluck('subject')
            ->unique()
            ->values()
            ->all();
    }

    public function studentCount(): int
    {
        return User::query()
            ->withRole(Role::Student)
            ->where('school_class_id', $this->schoolClass->getKey())
            ->count();
    }

    public function hasEnoughStudents(): bool
    {
        return $this->studentCount() >= $this->minGroup();
    }

    /**
     * @return array{hand_in_rate: ?int, average_understanding: ?int, support_share: ?int, minutes_saved_per_week: ?int, evaluated_submissions: int}
     */
    public function kpis(): array
    {
        $evaluated = $this->evaluatedSubmissions(includePracticeTasks: true);

        return [
            'hand_in_rate' => $this->handInRate(),
            'average_understanding' => $this->averageUnderstanding(),
            'support_share' => $this->supportShare(),
            'minutes_saved_per_week' => (int) round($evaluated->count() * (int) config('statistics.minutes_per_evaluation') / $this->weeks),
            'evaluated_submissions' => $evaluated->count(),
        ];
    }

    /**
     * Every calendar week of the period, empty weeks included.
     *
     * @return array<string, ?int>
     */
    public function understandingTrend(): array
    {
        $averages = $this->evaluatedSubmissions(includePracticeTasks: false)
            ->groupBy(fn (Submission $submission): int => (int) $submission->created_at->isoWeek)
            ->map(fn (Collection $ofWeek): ?int => $this->weekAverage($ofWeek));

        $trend = [];

        foreach ($this->periodWeeks() as $week) {
            $trend['KW '.$week] = $averages->get($week);
        }

        return $trend;
    }

    /**
     * Competency name to average answer score, alphabetically. A competency appears only when at least
     * `statistics.min_group` students answered questions mapped to it.
     *
     * @return array<string, int>
     */
    public function competencyAverages(): array
    {
        $answers = DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('questions', 'questions.id', '=', 'answers.question_id')
            ->join('lessons', 'lessons.id', '=', 'submissions.lesson_id')
            ->join('competencies', 'competencies.id', '=', 'questions.competency_id')
            ->where('lessons.school_class_id', $this->schoolClass->getKey())
            ->whereNotNull('lessons.published_at')
            ->whereNotNull('answers.score')
            ->where('submissions.created_at', '>=', $this->periodStart());

        if ($this->subject !== null) {
            $answers->where('lessons.subject', $this->subject);
        }

        $rows = $answers
            ->groupBy('competencies.id', 'competencies.name')
            ->selectRaw('competencies.name as competency, avg(answers.score) as average, count(distinct submissions.student_id) as students')
            ->get();

        $averages = [];

        foreach ($rows as $row) {
            if ((int) $row->students >= $this->minGroup()) {
                $averages[(string) $row->competency] = (int) round((float) $row->average);
            }
        }

        ksort($averages, SORT_STRING);

        return $averages;
    }

    /**
     * Classified mistakes per subject and mistake type, all five types always as keys. A subject appears only when at
     * least `statistics.min_group` students answered a classified question of it.
     *
     * @return array<string, array<string, int>>
     */
    public function mistakeTypes(): array
    {
        $query = DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('lessons', 'lessons.id', '=', 'submissions.lesson_id')
            ->where('lessons.school_class_id', $this->schoolClass->getKey())
            ->whereNotNull('lessons.published_at')
            ->whereNotNull('answers.mistake_type')
            ->where('submissions.created_at', '>=', $this->periodStart());

        if ($this->subject !== null) {
            $query->where('lessons.subject', $this->subject);
        }

        $answers = $query->get(['lessons.subject', 'answers.mistake_type', 'submissions.student_id']);

        $mistakes = [];

        foreach ($answers->groupBy('subject')->sortKeys() as $subject => $answersOfSubject) {
            if ($answersOfSubject->pluck('student_id')->unique()->count() < $this->minGroup()) {
                continue;
            }

            $counts = [];

            foreach (MistakeType::cases() as $type) {
                $counts[$type->value] = 0;
            }

            foreach ($answersOfSubject as $answer) {
                $counts[(string) $answer->mistake_type]++;
            }

            $mistakes[(string) $subject] = $counts;
        }

        return $mistakes;
    }

    /**
     * Hand-in rate per homework, oldest due date first. Homework still open shows its running rate; homework delegated
     * to fewer than `statistics.min_group` students is left out.
     *
     * @return list<array{topic: string, due_on: string, rate: int}>
     */
    public function handInRates(): array
    {
        return $this->homework()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $this->periodStart())
            ->withCount(['students', 'submissions'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (Lesson $lesson): bool => $lesson->students_count >= $this->minGroup())
            ->map(fn (Lesson $lesson): array => [
                'topic' => $lesson->topic,
                'due_on' => $lesson->due_on->toDateString(),
                'rate' => (int) round($lesson->submissions_count / $lesson->students_count * 100),
            ])
            ->values()
            ->all();
    }

    /**
     * "Erklär's mir" sessions per calendar week, only for the class's lessons: a session on a freely chosen topic has
     * no lesson and stays private to the student. A week with fewer than `statistics.min_group` students stays null.
     *
     * @return array<string, ?int>
     */
    public function explainItUsage(): array
    {
        $sessions = ExplanationSession::query()
            ->whereNotNull('lesson_id')
            ->whereIn('lesson_id', $this->lessons()->select('lessons.id'))
            ->where('created_at', '>=', $this->periodStart())
            ->get(['student_id', 'created_at'])
            ->groupBy(fn (ExplanationSession $session): int => (int) $session->created_at->isoWeek);

        $usage = [];

        foreach ($this->periodWeeks() as $week) {
            $ofWeek = $sessions->get($week);

            if ($ofWeek === null || $ofWeek->pluck('student_id')->unique()->count() < $this->minGroup()) {
                $usage['KW '.$week] = null;

                continue;
            }

            $usage['KW '.$week] = $ofWeek->count();
        }

        return $usage;
    }

    /**
     * Handed-in homework over assigned students, across the homework that was due in the period and is closed;
     * null while no homework was due.
     */
    private function handInRate(): ?int
    {
        $lessons = $this->homework()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $this->periodStart())
            ->whereDate('due_on', '<', today())
            ->withCount(['students', 'submissions'])
            ->get();

        $assigned = (int) $lessons->sum('students_count');

        if ($assigned === 0) {
            return null;
        }

        return (int) round($lessons->sum('submissions_count') / $assigned * 100);
    }

    private function averageUnderstanding(): ?int
    {
        $average = $this->evaluatedSubmissions(includePracticeTasks: false)->avg('understanding_score');

        return $average === null ? null : (int) round((float) $average);
    }

    /**
     * Share of the students with evaluated homework whose own average lies below the support threshold. Null while
     * fewer than `statistics.min_group` students have data.
     */
    private function supportShare(): ?int
    {
        $averages = $this->evaluatedSubmissions(includePracticeTasks: false)
            ->groupBy('student_id')
            ->map(fn (Collection $submissions): float => (float) $submissions->avg('understanding_score'));

        if ($averages->count() < $this->minGroup()) {
            return null;
        }

        $supported = $averages->filter(fn (float $average): bool => $average < UnderstandingLevel::SUPPORT_THRESHOLD)->count();

        return (int) round($supported / $averages->count() * 100);
    }

    /**
     * Average understanding of one calendar week; null while fewer than `statistics.min_group` students handed in.
     *
     * @param  Collection<int, Submission>  $submissions
     */
    private function weekAverage(Collection $submissions): ?int
    {
        if ($submissions->pluck('student_id')->unique()->count() < $this->minGroup()) {
            return null;
        }

        return (int) round((float) $submissions->avg('understanding_score'));
    }

    /**
     * Evaluated hand-ins of the period; Förderaufgaben only count for the saved time.
     *
     * @return Collection<int, Submission>
     */
    private function evaluatedSubmissions(bool $includePracticeTasks): Collection
    {
        $lessons = $includePracticeTasks ? $this->lessons() : $this->homework();

        return Submission::query()
            ->whereIn('lesson_id', $lessons->select('lessons.id'))
            ->whereNotNull('evaluated_at')
            ->where('created_at', '>=', $this->periodStart())
            ->get();
    }

    /**
     * Published homework of the class, without Förderaufgaben: those reach only a few students and would distort
     * hand-in rate, understanding and trend.
     *
     * @return Builder<Lesson>
     */
    private function homework(): Builder
    {
        return $this->lessons()->whereNull('practice_competency_id');
    }

    /**
     * Published lessons of the class, filtered by the chosen subject.
     *
     * @return Builder<Lesson>
     */
    private function lessons(): Builder
    {
        return Lesson::query()
            ->where('school_class_id', $this->schoolClass->getKey())
            ->published()
            ->when($this->subject !== null, fn (Builder $query): Builder => $query->where('subject', $this->subject));
    }

    /**
     * @return list<int>
     */
    private function periodWeeks(): array
    {
        $weeks = [];
        $cursor = $this->periodStart()->startOfWeek();
        $lastWeek = today()->endOfWeek();

        while ($cursor->lessThanOrEqualTo($lastWeek)) {
            $weeks[] = (int) $cursor->isoWeek;
            $cursor = $cursor->copy()->addWeek();
        }

        return $weeks;
    }

    private function periodStart(): CarbonInterface
    {
        return today()->subWeeks($this->weeks)->startOfDay();
    }

    private function minGroup(): int
    {
        return (int) config('statistics.min_group');
    }

    /**
     * Only the periods the page offers are accepted; anything else (a manipulated URL as well) uses the default.
     */
    private static function period(int $weeks): int
    {
        if (in_array($weeks, (array) config('statistics.weeks'), true)) {
            return $weeks;
        }

        return (int) config('statistics.default_weeks');
    }
}
