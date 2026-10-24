<?php

namespace App\Livewire\Statistics;

/**
 * "Erklär's mir" sessions per calendar week, only sessions about a lesson of the class are counted.
 */
class ExplainItUsageChart extends StatisticsChart
{
    protected ?string $heading = "Erklär's-mir-Sitzungen je Woche";

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

        $usage = $statistics->explainItUsage();

        return [
            'datasets' => [
                [
                    'label' => 'Sitzungen',
                    'data' => array_values($usage),
                ],
            ],
            'labels' => array_keys($usage),
        ];
    }
}
