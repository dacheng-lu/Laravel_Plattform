<?php

namespace Tests\Feature\Review;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Datenauskunft (Art. 15 DSGVO) for issue #2 and #3: the student's own repeat state and the mistake type of an
 * answer. Self-assessments stay invisible to the school administration.
 */
class ReviewDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_export_contains_the_repeat_state_with_the_counters(): void
    {
        [$student, , $item] = $this->evaluatedHandIn();

        $export = PersonalDataExport::for($student);

        $this->assertArrayHasKey('wiederholen', $export);
        $this->assertCount(1, $export['wiederholen']);
        $this->assertSame([
            'frage' => 'Was macht der Zellkern?',
            'fach' => 'Biologie',
            'thema' => 'Zellen',
            'box' => 2,
            'faellig_am' => '2026-10-25',
            'zuletzt_wiederholt_am' => $item->last_reviewed_at?->toIso8601String(),
            'sofort_gewusst' => 3,
            'mit_muehe' => 1,
            'nicht_gewusst' => 2,
        ], $export['wiederholen'][0]);

        // No individual attempts, only the three counters (data minimisation).
        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('nicht_gewusst', $json);
        $this->assertStringNotContainsString('expected_answer', $json);

        // The mistake type is part of the answer, together with its source.
        $answer = $export['abgaben'][0]['antworten'][0];
        $this->assertSame('Er steuert die Zelle.', $answer['antwort']);
        $this->assertSame('Weg/Schritt falsch', $answer['fehlerart']);
        $this->assertSame('Lehrkraft', $answer['fehlerart_quelle']);
    }

    public function test_an_export_requested_for_the_administration_holds_no_self_assessments(): void
    {
        [$student] = $this->evaluatedHandIn();
        $admin = User::factory()->admin()->for($student->school)->create();

        $export = PersonalDataExport::for($student, $admin);

        // The administration does receive the hand-in (they may see it anyway) …
        $this->assertCount(1, $export['abgaben']);
        $this->assertSame('Weg/Schritt falsch', $export['abgaben'][0]['antworten'][0]['fehlerart']);

        // … but never the self-assessments: no key, and none of the counter names anywhere in the export.
        $this->assertArrayNotHasKey('wiederholen', $export);
        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['sofort_gewusst', 'mit_muehe', 'nicht_gewusst'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        // Positivkontrolle: the student's own export of the very same data does contain them.
        $own = json_encode(PersonalDataExport::for($student), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['sofort_gewusst', 'mit_muehe', 'nicht_gewusst'] as $counter) {
            $this->assertStringContainsString($counter, $own);
        }
    }

    /**
     * One evaluated hand-in of a student with a review item and a classified answer.
     *
     * @return array{User, Question, ReviewItem}
     */
    private function evaluatedHandIn(): array
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Zellen',
            'held_on' => '2026-09-10',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was macht der Zellkern?',
            'expected_answer' => 'GEHEIMER ERWARTUNGSHORIZONT',
            'position' => 1,
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(60)->create([
            'summary' => 'Meine Zusammenfassung zur Stunde über die Zelle.',
            'feedback' => 'Solide Arbeit, schau dir die Chloroplasten noch einmal an.',
            'gaps' => ['Die Rolle der Chloroplasten'],
        ]);
        Answer::factory()->for($submission)->for($question)
            ->mistake(MistakeType::Procedure, MistakeSource::Teacher, null)
            ->create(['answer' => 'Er steuert die Zelle.', 'score' => 55, 'feedback' => 'Fast.']);

        $item = ReviewItem::factory()->for($student, 'student')->for($question)->create([
            'box' => 2,
            'due_on' => '2026-10-25',
            'last_reviewed_at' => '2026-10-24 08:30:00',
            'knew_count' => 3,
            'hard_count' => 1,
            'forgot_count' => 2,
        ]);

        return [$student, $question, $item];
    }
}
