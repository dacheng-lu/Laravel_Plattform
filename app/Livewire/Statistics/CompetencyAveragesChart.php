<?php

namespace App\Livewire\Statistics;

/**
 * Average answer score per competency as horizontal bars; competencies with too few students are left out.
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
                ],
            ],
            'labels' => array_keys($averages),
        ];
    }
}
