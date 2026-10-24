<?php

namespace Tests\Feature\Mistakes;

use App\Filament\Pages\LearningProgress;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * "Lernstand & Förderbedarf" shows a teacher the mistake types of the students they may see anyway (spec AC-8),
 * per competency and marked as an AI hint. Cross-school and without a visible class there is nothing to see.
 */
class TeacherMistakeViewTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $mia;

    private User $ben;

    private User $lena;

    private Competency $fractions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->mia = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->ben = User::factory()->student($this->schoolClass)->create(['name' => 'Ben Schulz']);
        $this->lena = User::factory()->student($this->schoolClass)->create(['name' => 'Lena Wagner']);

        $this->fractions = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Brüche kürzen']);
        $sentences = Competency::factory()->create(['subject' => 'Deutsch', 'name' => 'Satzbau']);

        $miaHomework = $this->evaluatedHomework($this->mia, 'Brüche kürzen üben', '2026-10-01');
        $this->answerWithMistake($miaHomework, $this->fractions, 30, MistakeType::Procedure);
        $this->answerWithMistake($miaHomework, $this->fractions, 25, MistakeType::Procedure);
        // A strength in another competency: at and above the threshold nothing is classified, so no mistake line shows.
        $this->answer($miaHomework, $sentences, 90);

        $benHomework = $this->evaluatedHomework($this->ben, 'Zähler und Nenner', '2026-10-05');
        $this->answerWithMistake($benHomework, $this->fractions, 30, MistakeType::Procedure);
        $this->answerWithMistake($benHomework, $this->fractions, 40, MistakeType::Concept);

        $this->answerWithMistake($this->evaluatedHomework($this->lena, 'Kürzen am Modell', '2026-10-10'), $this->fractions, 35, MistakeType::Concept);
    }

    public function test_distribution_per_competency(): void
    {
        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class);

        $page->assertOk()
            // Positivkontrolle derselben Ansicht: die Verteilung der Kompetenz mit Förderbedarf ist sichtbar …
            ->assertSee('Brüche kürzen')
            ->assertSee('3× Weg/Schritt falsch')
            ->assertSee('2× Grundidee noch unklar')
            // Never an assessment: the teacher sees that the counts come from the rules.
            ->assertSee('Regel-Hinweis, kann falsch sein')
            // … die Stärke-Kompetenz steht zwar auf der Seite, bekommt aber keine Fehlerzeile.
            ->assertSee('Satzbau')
            ->assertDontSee('1× Grundidee noch unklar');
    }

    public function test_teacher_counts_are_separate_from_rule_and_ai_counts(): void
    {
        // A model's own order on the same competency: it carries the AI label, not the rule label.
        $this->answerWithMistake(
            $this->mia->submissions()->sole(),
            $this->fractions,
            40,
            MistakeType::Incomplete,
            MistakeSource::Claude,
            90,
        );

        // A teacher's own order on the same competency: it is not a hint that could be wrong.
        $this->answerWithMistake(
            $this->mia->submissions()->sole(),
            $this->fractions,
            35,
            MistakeType::OffTopic,
            MistakeSource::Teacher,
            null,
        );

        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class);

        $page->assertOk()
            // Die Regel-Zähler tragen den Regel-Hinweis …
            ->assertSee('Fehlerarten: 3× Weg/Schritt falsch, 2× Grundidee noch unklar (Regel-Hinweis, kann falsch sein)')
            // … die KI-Zähler stehen getrennt mit ihrem eigenen Hinweis …
            ->assertSee('Fehlerarten: 1× Unvollständig (KI-Hinweis, kann falsch sein)')
            // … die Lehrkraft-Zähler getrennt und ohne einen Hinweis, der nach Irrtum klingt …
            ->assertSee('Fehlerarten (von der Lehrkraft eingeordnet): 1× Am Thema vorbei')
            // … und keine Quelle zählt in die Verteilung einer anderen hinein.
            ->assertDontSee('1× Unvollständig (Regel-Hinweis, kann falsch sein)')
            ->assertDontSee('2× Grundidee noch unklar, 1× Unvollständig (Regel-Hinweis, kann falsch sein)');
    }

    public function test_the_kill_switch_hides_the_distribution(): void
    {
        // Kill-Switch: ausgeschaltet zeigt der Lernstand keine Verteilung, auch wenn Antworten eingeordnet sind.
        config()->set('lernplattform.mistakes.enabled', false);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertSee('Brüche kürzen')
            ->assertDontSee('Weg/Schritt falsch')
            ->assertDontSee('Grundidee noch unklar')
            ->assertDontSee('Regel-Hinweis, kann falsch sein');

        // Positivkontrolle: eingeschaltet steht sie da.
        config()->set('lernplattform.mistakes.enabled', true);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertSee('3× Weg/Schritt falsch')
            ->assertSee('Regel-Hinweis, kann falsch sein');
    }

    public function test_cross_school_sees_nothing(): void
    {
        // Positivkontrolle: the class teacher sees the distribution (see test_distribution_per_competency).
        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->assertSee('3× Weg/Schritt falsch');

        // A teacher of another school has no visible class at all.
        $foreignTeacher = User::factory()->teacher()->create();

        Livewire::actingAs($foreignTeacher)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertDontSee('3× Weg/Schritt falsch')
            ->assertDontSee('Mia Hoffmann');

        // With a class of their own they still never reach into this school's data.
        $ownClass = SchoolClass::factory()->for($foreignTeacher, 'teacher')->create(['name' => '5a']);

        Livewire::actingAs($foreignTeacher)
            ->test(LearningProgress::class)
            ->assertSet('schoolClassId', $ownClass->id)
            ->assertDontSee('3× Weg/Schritt falsch')
            ->set('schoolClassId', $this->schoolClass->id)
            ->assertForbidden();

        // An admin sees the classes of their own school – and only those.
        $admin = User::factory()->admin()->create();
        $adminClass = SchoolClass::factory()
            ->for(User::factory()->teacher()->for($admin->school)->create(), 'teacher')
            ->create(['name' => '3a']);
        $adminStudent = User::factory()->student($adminClass)->create();
        $this->answerWithMistake($this->evaluatedHomework($adminStudent, 'Brüche im Alltag', '2026-10-02'), $this->fractions, 20, MistakeType::Incomplete);

        Livewire::actingAs($admin)
            ->test(LearningProgress::class)
            ->assertSet('schoolClassId', $adminClass->id)
            // Positivkontrolle: the admin sees the distribution of their own class.
            ->assertSee('1× Unvollständig')
            ->assertSee('Regel-Hinweis, kann falsch sein')
            // But never the distribution of the other school.
            ->assertDontSee('3× Weg/Schritt falsch');
    }

    /**
     * An answer with a classified mistake, next to the answers the class already has.
     */
    private function answerWithMistake(Submission $submission, Competency $competency, int $score, MistakeType $type, MistakeSource $source = MistakeSource::Demo, ?int $confidence = 80): Answer
    {
        return Answer::factory()
            ->for($submission)
            ->for(Question::factory()->for($submission->lesson)->create(['competency_id' => $competency->id]))
            ->mistake($type, $source, $confidence)
            ->create(['score' => $score]);
    }
}
