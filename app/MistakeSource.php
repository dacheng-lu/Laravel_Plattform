<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Who classified an answer's mistake type, so every stored type stays traceable.
 */
enum MistakeSource: string implements HasLabel
{
    case Demo = 'demo';
    case Claude = 'claude';
    case Clef = 'clef';
    case Teacher = 'teacher';

    public function getLabel(): string
    {
        return match ($this) {
            self::Demo => 'Demo-Regeln',
            self::Claude => 'KI (Claude)',
            self::Clef => 'KI (lokal)',
            self::Teacher => 'Lehrkraft',
        };
    }

    /**
     * Whether the classification comes from a model – a teacher's own correction always wins over it.
     */
    public function isAi(): bool
    {
        return $this !== self::Teacher;
    }
}
