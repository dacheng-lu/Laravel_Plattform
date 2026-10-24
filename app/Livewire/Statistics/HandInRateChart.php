<?php

namespace App\Livewire\Statistics;

/**
 * Hand-in rate per homework in per cent; homework still open shows its running rate.
 */
class HandInRateChart extends StatisticsChart
{
    protected ?string $heading = 'Abgabequote je Hausaufgabe';

    protected ?array $options = [
        'scales' => [
            'y' => ['min' => 0, 'max' => 100],
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

        $rates = $statistics->handInRates();

        return [
            'datasets' => [
                [
                    'label' => 'Abgabequote',
                    'data' => array_column($rates, 'rate'),
                ],
            ],
            'labels' => array_column($rates, 'topic'),
        ];
    }
}
