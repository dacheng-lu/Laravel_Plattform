<?php

namespace App\Livewire\Statistics;

use App\UnderstandingLevel;
use Illuminate\Support\Str;

/**
 * Average answer score per competency as horizontal bars; competencies with too few students are left out.
 * Long competency names are shortened on the axis, the bar itself keeps the full figure.
 */
class CompetencyAveragesChart extends StatisticsChart
{
    protected ?string $heading = 'Ø je Kompetenz';

    protected ?array $options = [
        'indexAxis' => 'y',
        'scales' => [
            'x' => ['min' => 0, 'max' => 100],
        ],
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    protected function getData(): array
    {
        $statistics = $this->statistics();

        if ($statistics === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $averages = $statistics->competencyAverages();

        return [
            'datasets' => [
                [
                    'label' => 'Ø Punkte',
                    'data' => array_values($averages),
                    'backgroundColor' => array_map(fn (int $average): string => $this->colorOf($average), array_values($averages)),
                ],
            ],
            'labels' => array_map(fn (string $name): string => Str::limit($name, 34), array_keys($averages)),
        ];
    }

    /**
     * The traffic light of "Lernstand & Förderbedarf": understood, partly, needs support.
     */
    private function colorOf(int $average): string
    {
        return match (UnderstandingLevel::fromScore($average)) {
            UnderstandingLevel::Understood => '#34d399',
            UnderstandingLevel::Partly => '#fbbf24',
            UnderstandingLevel::NeedsSupport => '#f87171',
        };
    }
}
