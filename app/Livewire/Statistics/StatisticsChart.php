<?php

namespace App\Livewire\Statistics;

use App\Models\User;
use App\Support\ClassStatistics;
use Filament\Widgets\ChartWidget;

/**
 * Base of the charts on the "Statistik" page. Every chart receives the class, subject and period as Livewire
 * properties from the page and re-authorizes on every render: those properties reach the component from the client
 * and can be manipulated. An empty result renders no datasets, which Chart.js shows as an empty chart.
 */
abstract class StatisticsChart extends ChartWidget
{
    public ?int $schoolClassId = null;

    public ?string $subject = null;

    public int $weeks = 10;

    protected ?string $maxHeight = '280px';

    /**
     * The aggregates for the current properties, or null while no class is chosen. A class the viewer may not see
     * aborts with 403 inside ClassStatistics::forViewer().
     */
    protected function statistics(): ?ClassStatistics
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->schoolClassId === null) {
            return null;
        }

        return ClassStatistics::forViewer($user, $this->schoolClassId, $this->subject, $this->weeks);
    }
}
