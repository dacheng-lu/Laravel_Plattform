<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\ReviewToday;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use App\Support\LearningInsights;
use App\Support\ReviewQueue;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The student's start page: greeting, what's due next, how well they understood their homework so far,
 * the latest feedback and the way into "Erklär's mir" and "Heute wiederholen". Only the logged-in student's own
 * data is read.
 */
class StudentLearningWidget extends Widget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.student-learning-widget';

    public static function canView(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $student */
        $student = auth()->user();

        $openHomework = Lesson::query()
            ->assignedTo($student)
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->whereBelongsTo($student, 'student'))
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->get();

        $evaluatedSubmissions = LearningInsights::evaluatedSubmissions($student);

        $averageScore = $evaluatedSubmissions->isEmpty() ? null : (int) round($evaluatedSubmissions->avg('understanding_score'));
        $latestFeedback = $evaluatedSubmissions
            ->sortByDesc('evaluated_at')
            ->first(fn (Submission $submission): bool => filled($submission->feedback));

        $competencies = LearningInsights::studentCompetencies($student);

        return [
            'firstName' => str($student->name)->before(' ')->toString(),
            'practiceNeeds' => $competencies['practice']->take(3)->map(function (array $need): array {
                $lesson = $need['lessons']->first();

                return [
                    'competency' => $need['competency'],
                    'lesson' => $lesson,
                    'url' => ExplainIt::getUrl($lesson ? ['lesson' => $lesson->getKey()] : []),
                    'practiceTaskUrl' => $need['practice_task'] ? WorkOnHomework::getUrl(['lesson' => $need['practice_task']]) : null,
                ];
            }),
            'hasCompetencyData' => collect($competencies)->contains(fn (Collection $group): bool => $group->isNotEmpty()),
            'progressUrl' => MyLearningProgress::getUrl(),
            'openCount' => $openHomework->count(),
            'nextHomework' => $openHomework->take(3)->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'url' => WorkOnHomework::getUrl(['lesson' => $lesson]),
                'due' => $this->describeDueDate($lesson->due_on),
            ]),
            'averageScore' => $averageScore,
            'averageLevel' => $averageScore === null ? null : LearningInsights::studentLevel($averageScore),
            'evaluatedCount' => $evaluatedSubmissions->count(),
            'evaluationPending' => $student->submissions()->whereNull('evaluated_at')->exists(),
            'latestFeedback' => $latestFeedback,
            'latestFeedbackLevel' => $latestFeedback ? LearningInsights::studentLevel((int) $latestFeedback->understanding_score) : null,
            'homeworkUrl' => MyHomework::getUrl(),
            'explainItUrl' => ExplainIt::getUrl(),
            'reviewDueCount' => ReviewQueue::dueCount($student),
            'reviewUrl' => ReviewToday::getUrl(),
        ];
    }

    /**
     * @return array{date: ?string, day: ?string, month: ?string, label: string, color: string, icon: Heroicon}
     */
    protected function describeDueDate(?CarbonInterface $dueOn): array
    {
        if ($dueOn === null) {
            return ['date' => null, 'day' => null, 'month' => null, 'label' => 'Ohne Abgabetermin', 'color' => 'gray', 'icon' => Heroicon::OutlinedCalendar];
        }

        $dueOn = $dueOn->copy()->locale('de');
        $daysLeft = (int) today()->diffInDays($dueOn, false);

        return [
            'date' => $dueOn->isoFormat('dddd, D. MMMM'),
            'day' => $dueOn->isoFormat('D'),
            'month' => $dueOn->isoFormat('MMM'),
            ...match (true) {
                $daysLeft < 0 => ['label' => 'Überfällig', 'color' => 'danger', 'icon' => Heroicon::OutlinedExclamationTriangle],
                $daysLeft === 0 => ['label' => 'Heute fällig', 'color' => 'warning', 'icon' => Heroicon::OutlinedClock],
                $daysLeft === 1 => ['label' => 'Morgen fällig', 'color' => 'warning', 'icon' => Heroicon::OutlinedClock],
                default => ['label' => "In {$daysLeft} Tagen fällig", 'color' => 'gray', 'icon' => Heroicon::OutlinedCalendarDays],
            },
        ];
    }
}
