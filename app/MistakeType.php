<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Why an answer is not (fully) right. Used for an encouraging hint, for the teacher's overview and for practice
 * tasks – never as a mark: the type changes neither score, understanding_score nor a grade.
 */
enum MistakeType: string implements HasLabel
{
    case Concept = 'concept';
    case Procedure = 'procedure';
    case Incomplete = 'incomplete';
    case OffTopic = 'off_topic';
    case Empty = 'empty';

    public function getLabel(): string
    {
        return match ($this) {
            self::Concept => 'Grundidee noch unklar',
            self::Procedure => 'Weg/Schritt falsch',
            self::Incomplete => 'Unvollständig',
            self::OffTopic => 'Am Thema vorbei',
            self::Empty => 'Kein Versuch',
        };
    }

    /**
     * The sentence a student reads next to the answer: names the next step and never says "falsch".
     */
    public function feedbackHint(): string
    {
        return match ($this) {
            self::Concept => 'Hier lohnt es sich, die Grundidee noch einmal anzuschauen.',
            self::Procedure => 'Du hast richtig angesetzt, ein Schritt ist noch nicht ganz richtig.',
            self::Incomplete => 'Guter Anfang! Es fehlt noch ein Teil der Antwort.',
            self::OffTopic => 'Deine Antwort passt zu einer anderen Frage. Lies die Frage noch einmal genau.',
            self::Empty => 'Probier es ruhig: Auch eine unvollständige Antwort hilft dir weiter.',
        };
    }
}
