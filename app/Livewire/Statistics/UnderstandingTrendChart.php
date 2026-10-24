<?php

namespace App\Livewire\Statistics;

/**
 * Average understanding per calendar week of the period; a week with too few students stays empty.
 */
class UnderstandingTrendChart extends StatisticsChart
{
    protected ?string $heading = 'Ø Verständnis je Woche';

    protected ?array $options = [
        'scales' => [
            'y' => ['min' => 0, 'max' => 100],
        ],
    ];

    protected function getType(): string
    {
        return 'line';
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

        $trend = $statistics->understandingTrend();

        return [
            'datasets' => [
                [
                    'label' => 'Ø Verständnis',
                    'data' => array_values($trend),
                ],
            ],
            'labels' => array_keys($trend),
        ];
    }
}
