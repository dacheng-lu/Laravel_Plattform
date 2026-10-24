<?php

namespace Tests\Feature\Guardrails;

use App\Ai\LessonAssistant;
use App\Ai\MistakeClassification;
use App\Ai\MistakeClassifier;
use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Support\LearningInsights;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Leitplanken of the mistake types (issue #3): a hint, never a mark; not in the parents' report; never across
 * schools; no name at the AI; fail closed; and only the five mistake types of the spec.
 * Written against the contract (K) and the signatures of lanes B1/B2/B3, so it stays red until those are merged.
 *
 * Every negative assertion has its positive control in the same test.
 */
#[Group('guardrails-mistakes')]
class MistakeGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_parents_report_never_names_the_mistake_type(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $teacher = $schoolClass->teacher;
        $student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);

        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(60)->create([
            'feedback' => 'Du hast die Grundidee schon gut getroffen.',
        ]);
        Answer::factory()->for($submission)->for($question)
            ->mistake(MistakeType::Procedure, MistakeSource::Claude, 90)
            ->create(['answer' => 'Licht und Wasser reichen schon.', 'score' => 55]);

        $this->actingAs($teacher);

        $this->get(ParentReport::getUrl(['student' => $student]))
            ->assertOk()
            ->assertSee(['Elterngespräch: Mia Hoffmann', 'Du hast die Grundidee schon gut getroffen.'])
            ->assertDontSee([
                MistakeType::Procedure->getLabel(),
                MistakeType::Procedure->feedbackHint(),
                MistakeType::Concept->getLabel(),
                'Fehlerart',
                'KI-Hinweis',
            ]);

        // Positivkontrolle: in der Abgaben-Ansicht der Lehrkraft steht die Fehlerart sehr wohl.
        Livewire::actingAs($teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($submission))
            ->assertMountedActionModalSee([MistakeType::Procedure->getLabel(), 'KI-Hinweis, kann falsch sein']);
    }

    public function test_a_mistake_type_changes_neither_score_nor_support_need(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $withMistakes = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $withoutMistakes = User::factory()->student($schoolClass)->create(['name' => 'Ben Schulz']);
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$withMistakes, $withoutMistakes])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'competency_id' => $competency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);

        // The same hand-in for both students, in-band classified by the AI.
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')
            ->twice()
            ->andReturn([
                'understanding_score' => 40,
                'feedback' => 'Ein Schritt fehlt noch.',
                'gaps' => ['Gehe noch einmal auf „Chlorophyll“ ein.'],
                'answers' => [$question->id => [
                    'score' => 30,
                    'feedback' => 'Fast: das Chlorophyll fehlt.',
                    'mistake_type' => MistakeType::Procedure->value,
                    'mistake_confidence' => 90,
                ]],
            ]));

        config()->set('lernplattform.mistakes.enabled', true);
        $this->handIn($lesson, $withMistakes);

        config()->set('lernplattform.mistakes.enabled', false);
        $this->handIn($lesson, $withoutMistakes);

        $classified = $withMistakes->submissions()->sole()->answers()->sole();
        $plain = $withoutMistakes->submissions()->sole()->answers()->sole();

        // Positivkontrolle: nur mit der Funktion an trägt die Antwort eine Fehlerart.
        $this->assertSame(MistakeType::Procedure, $classified->mistake_type);
        $this->assertNotNull($classified->mistake_source);
        $this->assertNull($plain->mistake_type);

        $this->assertSame(30, $classified->score);
        $this->assertSame(30, $plain->score);
        $this->assertSame(40, $classified->submission->understanding_score);
        $this->assertSame(40, $plain->submission->understanding_score);

        $needs = fn (User $student): array => LearningInsights::supportNeeds(
            LearningInsights::scoredAnswers([$student->id]),
        )[$student->id] ?? [];

        // Positivkontrolle für den Vergleich: es gibt überhaupt einen Förderbedarf zu vergleichen.
        $this->assertSame([$competency->id], array_column($needs($withMistakes), 'competency_id'));
        $this->assertSame($needs($withMistakes), $needs($withoutMistakes));
    }

    public function test_a_failing_classifier_never_breaks_the_hand_in(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create();
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        $first = $this->lessonWithQuestion($schoolClass, $student, $competency, 'Fotosynthese', 'Was braucht eine Pflanze für die Fotosynthese?');
        $second = $this->lessonWithQuestion($schoolClass, $student, $competency, 'Zellaufbau', 'Wozu braucht die Zelle einen Zellkern?');

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')
            ->twice()
            ->andReturnUsing(fn (Submission $submission): array => [
                'understanding_score' => 40,
                'feedback' => 'Ein Schritt fehlt noch.',
                'gaps' => [],
                'answers' => [$submission->answers->first()->question_id => ['score' => 30, 'feedback' => 'Fast: ein Schritt fehlt.']],
            ]));

        $this->mock(MistakeClassifier::class, fn (MockInterface $mock) => $mock->expects('classify')
            ->once()
            ->andReturn(new MistakeClassification(MistakeType::Procedure, 90, MistakeSource::Demo)));

        $this->actingAs($student);

        // Positivkontrolle: ein arbeitender Klassifizierer hinterlässt seine Einordnung.
        $this->handIn($first, $student);
        $this->assertSame(MistakeType::Procedure, $student->submissions()->sole()->answers()->sole()->mistake_type);

        $this->mock(MistakeClassifier::class, fn (MockInterface $mock) => $mock->expects('classify')
            ->once()
            ->andThrow(new RuntimeException('Klassifizierer nicht erreichbar')));

        $this->handIn($second, $student);

        $submission = $student->submissions()->where('lesson_id', $second->id)->sole();
        $answer = $submission->answers()->sole();

        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(40, $submission->understanding_score);
        $this->assertSame(30, $answer->score);
        $this->assertNull($answer->mistake_type);
    }

    public function test_a_teacher_of_another_school_sees_no_distribution(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $teacher = $schoolClass->teacher;
        $student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-23',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'competency_id' => $competency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'position' => 1,
        ]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(40)->create();
        Answer::factory()->for($submission)->for($question)
            ->mistake(MistakeType::Procedure, MistakeSource::Claude, 90)
            ->create(['score' => 30]);

        // Positivkontrolle: die eigene Lehrkraft sieht die Verteilung ihrer Klasse.
        Livewire::actingAs($teacher)->test(LearningProgress::class)
            ->assertOk()
            ->assertSee(MistakeType::Procedure->getLabel())
            ->assertSee('KI-Hinweis, kann falsch sein');

        $foreignTeacher = User::factory()->teacher()->create();
        $foreignClass = SchoolClass::factory()->for($foreignTeacher, 'teacher')->create(['name' => 'Klasse 9c']);
        $foreignStudent = User::factory()->student($foreignClass)->create(['name' => 'Ida Fremd']);
        $foreignCompetency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Zahlen ordnen']);
        $foreignLesson = Lesson::factory()->for($foreignClass)->for($foreignTeacher, 'teacher')->publishedFor([$foreignStudent])->create([
            'subject' => 'Mathematik',
            'topic' => 'Zahlen ordnen üben',
            'held_on' => '2026-10-23',
        ]);
        $foreignQuestion = Question::factory()->for($foreignLesson)->create([
            'competency_id' => $foreignCompetency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => 'Ordne die Zahlen der Größe nach.',
            'position' => 1,
        ]);
        // Her own evaluated answer, without any mistake type: the page shows her student, but no distribution.
        $foreignSubmission = Submission::factory()->for($foreignLesson)->for($foreignStudent, 'student')->evaluated(90)->create();
        Answer::factory()->for($foreignSubmission)->for($foreignQuestion)->create(['answer' => 'Zwei vor drei.', 'score' => 90]);

        // Vorkontrolle: der Negativteil prüft wirklich eine andere Schule und nicht nur eine andere Klasse.
        $this->assertNotSame($schoolClass->school_id, $foreignClass->school_id);

        Livewire::actingAs($foreignTeacher)->test(LearningProgress::class)
            ->assertOk()
            // Positivkontrolle: ihre eigene Klasse und ihre eigene Schülerin stehen auf der Seite …
            ->assertSee('Ida Fremd')
            ->assertSee('Zahlen ordnen')
            // … aber keine Fehlerart-Verteilung der anderen Schule und auch keine eigene, weil nichts eingeordnet ist.
            ->assertDontSee(MistakeType::Procedure->getLabel())
            ->assertDontSee(MistakeType::Concept->getLabel())
            ->assertDontSee('KI-Hinweis, kann falsch sein')
            ->assertDontSee('Mia Hoffmann')
            ->assertDontSee('Fachwissen anwenden');
    }

    public function test_a_student_who_moves_to_another_school_leaves_the_old_mistakes_behind(): void
    {
        // Schule A: eine Klasse mit einer eingeordneten Antwort.
        $classA = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $teacherA = $classA->teacher;
        $student = User::factory()->student($classA)->create(['name' => 'Mia Hoffmann']);
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Brüche kürzen']);

        $lessonA = Lesson::factory()->for($classA)->for($teacherA, 'teacher')->publishedFor([$student])->create([
            'subject' => 'Mathematik',
            'topic' => 'Brüche kürzen üben',
            'held_on' => '2026-10-20',
        ]);
        $questionA = Question::factory()->for($lessonA)->create([
            'competency_id' => $competency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => 'Wie kürzt du 6/8?',
            'position' => 1,
        ]);
        $submissionA = Submission::factory()->for($lessonA)->for($student, 'student')->evaluated(40)->create();
        Answer::factory()->for($submissionA)->for($questionA)
            ->mistake(MistakeType::Procedure, MistakeSource::Demo, 85)
            ->create(['score' => 30]);

        // Positivkontrolle: solange die Schülerin in Schule A ist, sieht deren Lehrkraft die Verteilung.
        Livewire::actingAs($teacherA)->test(LearningProgress::class)
            ->assertOk()
            ->assertSee('1× Weg/Schritt falsch')
            ->assertSee('Regel-Hinweis, kann falsch sein');

        // Umzug: dieselbe Schülerin, dieselben Antworten in der alten Stunde, neue Klasse in Schule B.
        $teacherB = User::factory()->teacher()->create();
        $classB = SchoolClass::factory()->for($teacherB, 'teacher')->create(['name' => 'Klasse 8a']);
        $this->assertNotSame($classA->school_id, $classB->school_id);

        $student->update(['school_class_id' => $classB->id, 'school_id' => $classB->school_id]);

        // Die neue Lehrkraft sieht die Schülerin und ihre Kompetenz – aber keine Fehlerart aus der alten Schule.
        Livewire::actingAs($teacherB)->test(LearningProgress::class)
            ->assertOk()
            ->assertSee('Mia Hoffmann')
            ->assertSee('Brüche kürzen')
            ->assertDontSee('Weg/Schritt falsch')
            ->assertDontSee('Regel-Hinweis, kann falsch sein');
    }

    public function test_the_evaluation_request_carries_no_name_of_the_student(): void
    {
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
        ]);
        Http::preventStrayRequests();

        $schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $student = User::factory()->student($schoolClass)->create([
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
            'email' => 'zacharias.quellenberg@example.org',
        ]);
        $lesson = Lesson::factory()->for($schoolClass)->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'notes' => 'Wasserpest unter der Lampe: Sauerstoffbläschen gezählt.',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
        ]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create([
            'summary' => 'Zacharias Quellenberg hat gesehen, dass die Pflanze im Licht Bläschen bildet.',
        ]);
        $submission->answers()->create([
            'question_id' => $question->id,
            'answer' => 'Zacharias Quellenberg hat gezählt, dass die Wasserpest im Licht Bläschen bildet.',
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->messageBody([
            'understanding_score' => 55,
            'feedback' => 'Guter Anfang, ein Schritt fehlt noch.',
            'gaps' => ['Gehe noch einmal auf „Chlorophyll“ ein.'],
            'answers' => [['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast: das Chlorophyll fehlt.']],
        ]))]);

        $evaluation = app(LessonAssistant::class)->evaluateSubmission($submission->load('answers'));

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, 'Genau eine Anfrage an die KI.');

        $request = $recorded->first()[0];
        $this->assertInstanceOf(Request::class, $request);

        // Der dekodierte Text: so kommt er bei der KI an, Umlaute eingeschlossen.
        $data = $request->data();
        $text = collect($data['messages'][0]['content'])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
        // Der System-Prompt ist je nach Aufbau ein String oder eine Liste von Text-Blöcken.
        $system = json_encode($data['system'] ?? '');

        // Positivkontrolle: Antwort und Zusammenfassung gehen mit, nur der Name nicht.
        $this->assertStringContainsString('hat gezählt, dass die Wasserpest im Licht Bläschen bildet.', $text);
        $this->assertStringContainsString('hat gesehen, dass die Pflanze im Licht Bläschen bildet.', $text);
        $this->assertStringContainsString('Schüler:in', $text);

        $this->assertStringNotContainsString('Zacharias', $text);
        $this->assertStringNotContainsString('Quellenberg', $text);
        $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $text);

        $this->assertStringNotContainsString('Zacharias', $system);
        $this->assertStringNotContainsString('Quellenberg', $system);
        $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $system);

        // Positivkontrolle: die Antwort der KI kommt beim Aufrufer an, die Bewertung selbst bleibt unverändert.
        $this->assertSame(55, $evaluation['answers'][$question->id]['score']);
        $this->assertNull($submission->answers()->sole()->score);
    }

    public function test_only_the_five_mistake_types_of_the_spec_exist(): void
    {
        $expected = ['concept', 'procedure', 'incomplete', 'off_topic', 'empty'];

        $this->assertSame($expected, array_column(MistakeType::cases(), 'value'));

        // Positivkontrolle: jeder Fall trägt sein Label aus der Spec, das Enum ist also wirklich gefüllt.
        $labels = collect(MistakeType::cases())
            ->mapWithKeys(fn (MistakeType $type): array => [$type->value => $type->getLabel()])
            ->all();

        $this->assertSame([
            'concept' => 'Grundidee noch unklar',
            'procedure' => 'Weg/Schritt falsch',
            'incomplete' => 'Unvollständig',
            'off_topic' => 'Am Thema vorbei',
            'empty' => 'Kein Versuch',
        ], $labels);

        // Nichts über Gefühl, Motivation oder Verhalten: eingeordnet wird die Antwort, nicht das Kind.
        $forbidden = ['gefühl', 'gefuehl', 'emotion', 'motivation', 'laune', 'stimmung', 'angst', 'frust', 'unsicher', 'verhalten'];

        foreach (MistakeType::cases() as $type) {
            foreach ($forbidden as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $type->value);
                $this->assertStringNotContainsStringIgnoringCase($word, $type->getLabel());
                $this->assertStringNotContainsStringIgnoringCase($word, $type->feedbackHint());
            }
        }
    }

    /**
     * A published lesson of the student's class with one comprehension question.
     */
    private function lessonWithQuestion(SchoolClass $schoolClass, User $student, Competency $competency, string $topic, string $prompt): Lesson
    {
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'subject' => 'Biologie',
            'topic' => $topic,
            'held_on' => '2026-10-23',
        ]);

        Question::factory()->for($lesson)->create([
            'competency_id' => $competency->id,
            'type' => QuestionType::Comprehension,
            'prompt' => $prompt,
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);

        return $lesson;
    }

    /**
     * Hands in one homework through the real page.
     */
    private function handIn(Lesson $lesson, User $student): void
    {
        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => 'Pflanzen bauen mit Lichtenergie, Wasser und Kohlenstoffdioxid Glucose auf. Der grüne Farbstoff Chlorophyll fängt das Licht ein, dabei entsteht Sauerstoff.',
                'answers' => [$lesson->questions->first()->getKey() => 'Licht und Wasser, das Chlorophyll fehlt.'],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');
    }

    /**
     * A faked Messages API response whose text block holds the given JSON.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function messageBody(array $result): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'test'],
                ['type' => 'text', 'text' => json_encode($result)],
            ],
            'stop_reason' => 'end_turn',
            'stop_details' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }
}
