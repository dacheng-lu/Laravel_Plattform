<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Role;
use App\Support\ClassStatistics;
use App\Support\LearningInsights;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Class figures for teachers and school administration: averages only, never a single student's result.
 * A class with fewer than `statistics.min_group` students shows no figures at all, so nobody can be recognised.
 */
class Statistics extends Page
{
    protected string $view = 'filament.pages.statistics';

    protected static ?string $title = 'Statistik';

    protected static ?string $slug = 'statistik';

    protected static string|UnitEnum|null $navigationGroup = 'Unterricht';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 2;

    #[Url(as: 'klasse')]
    public ?int $schoolClassId = null;

    #[Url(as: 'fach')]
    public ?string $subject = null;

    #[Url(as: 'wochen')]
    public int $weeks = 10;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    public function mount(): void
    {
        $visibleClasses = LearningInsights::visibleClasses(auth()->user());

        $this->schoolClassId ??= $visibleClasses->orderBy('name')->value('id');

        abort_unless($this->schoolClassId === null || $visibleClasses->whereKey($this->schoolClassId)->exists(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $classes = LearningInsights::visibleClasses($user)->orderBy('name')->pluck('name', 'id');

        if ($this->schoolClassId === null) {
            return ['classes' => $classes, 'statistics' => null, 'kpis' => null];
        }

        abort_unless($classes->has($this->schoolClassId), 403);

        $statistics = ClassStatistics::forViewer($user, $this->schoolClassId, $this->subject, $this->weeks);

        return [
            'classes' => $classes,
            'statistics' => $statistics,
            'kpis' => $statistics->kpis(),
        ];
    }
}
