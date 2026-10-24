<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * How a student rates their own recall in "Heute wiederholen". Practice only: the rating moves the Leitner box
 * and nothing else, never a score, the Lernstand or a grade.
 */
enum ReviewRating: string implements HasLabel
{
    case Knew = 'knew';
    case Hard = 'hard';
    case Forgot = 'forgot';

    public function getLabel(): string
    {
        return match ($this) {
            self::Knew => 'Sofort gewusst',
            self::Hard => 'Mit Mühe',
            self::Forgot => 'Nicht gewusst',
        };
    }
}
