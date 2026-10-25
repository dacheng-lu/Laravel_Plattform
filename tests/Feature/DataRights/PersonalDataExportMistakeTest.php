<?php

namespace Tests\Feature\DataRights;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Art. 15/20 DSGVO: an old row whose stored mistake type the enum no longer knows must not break the export. The data
 * subject gets the stored raw value instead, and everything else in the answer stays complete.
 */
class PersonalDataExportMistakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_mistake_type_never_breaks_the_export(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $student = User::factory()->student($schoolClass)->create(['name' => 'Lena Muster']);

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Mathematik',
            'topic' => 'Brüche kürzen üben',
            'held_on' => '2026-10-20',
        ]);

        $first = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Wie kürzt du 6/8?',
            'position' => 1,
        ]);
        $second = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Warum kürzt man überhaupt?',
            'position' => 2,
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create([
            'summary' => 'Meine Zusammenfassung',
        ]);

        $legacy = Answer::factory()->for($submission)->for($first)->create([
            'answer' => 'Durch 2 teilen.',
            'score' => 40,
        ]);

        // Positivkontrolle: eine gültige Einordnung, die der Export mit Label und Quelle zeigen soll.
        $valid = Answer::factory()->for($submission)->for($second)
            ->mistake(MistakeType::Procedure, MistakeSource::Teacher, null)
            ->create(['answer' => 'Damit die Zahlen kleiner werden.', 'score' => 45]);

        // Ein Altwert, den das Enum nicht mehr kennt: er kommt nur an der Enum-Umwandlung vorbei in die Datenbank –
        // genau so sähe eine Zeile aus, die vor einer Umbenennung geschrieben wurde.
        DB::table('answers')->where('id', $legacy->getKey())->update([
            'mistake_type' => 'legacy_type',
            'mistake_source' => MistakeSource::Demo->value,
        ]);

        $export = PersonalDataExport::for($student->fresh());

        $answers = collect($export['abgaben'][0]['antworten'])->keyBy('frage');

        $this->assertCount(2, $answers);

        // Der Altwert blockiert die Auskunft nicht: er steht als Rohtext im Export, die Quelle bleibt lesbar.
        $this->assertSame('legacy_type', $answers['Wie kürzt du 6/8?']['fehlerart']);
        $this->assertSame('Demo-Regeln', $answers['Wie kürzt du 6/8?']['fehlerart_quelle']);

        // Positivkontrolle: der gültige Wert erscheint mit seinem Label.
        $this->assertSame('Weg/Schritt falsch', $answers['Warum kürzt man überhaupt?']['fehlerart']);
        $this->assertSame('Lehrkraft', $answers['Warum kürzt man überhaupt?']['fehlerart_quelle']);

        // Und die übrige Auskunft bleibt vollständig.
        $this->assertSame('Lena Muster', $export['konto']['name']);
        $this->assertSame('Mathematik', $export['abgaben'][0]['fach']);
        $this->assertSame('Meine Zusammenfassung', $export['abgaben'][0]['zusammenfassung']);
        $this->assertSame('Damit die Zahlen kleiner werden.', $answers['Warum kürzt man überhaupt?']['antwort']);
        $this->assertSame(45, $valid->refresh()->score);
    }
}
