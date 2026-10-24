<?php

namespace App\Livewire\Statistics;

use App\MistakeType;

/**
 * Classified mistakes per subject, one stacked dataset per mistake type.
 */
class MistakeTypesChart extends StatisticsChart
{
    protected ?string $heading = 'Fehlerarten je Fach';

    protected ?array $options = [
        'scales' => [
            'x' => ['stacked' => true],
            'y' => ['stacked' => true],
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

        $mistakes = $statistics->mistakeTypes();
        $datasets = [];

        foreach (MistakeType::cases() as $type) {
            $datasets[] = [
                'label' => $type->getLabel(),
                'data' => array_map(fn (array $counts): int => $counts[$type->value], array_values($mistakes)),
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_keys($mistakes),
        ];
    }
}
