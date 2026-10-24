<?php

namespace Tests\Feature\Mistakes;

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
use App\Support\MistakeInsights;
use App\Support\MistakeRecorder;
use App\Support\PracticeTasks;
use Filament\Actions\Testing\TestAction;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mistake type along the real paths: a student hands in a homework that Claude evaluates, the teacher corrects a
 * type that survives a second run of the recorder, and the most common type of a group steers the Förderaufgabe.
 *
 * Every failure path carries its positive control in the same test.
 */
class MistakeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const string SUMMARY = 'Pflanzen bauen mit Lichtenergie, Wasser und Kohlenstoffdioxid Zucker auf. Der grüne Farbstoff Chlorophyll fängt das Licht ein, dabei entsteht Sauerstoff.';

    private SchoolClass $schoolClass;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
    }

    public function test_only_a_confident_classification_of_the_ai_reaches_the_answer(): void
    {
        // The recorder refuses everything below 0.6 of 100; the values below are 10, 59 and 61 points of confidence.
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'lernplattform.mistakes.enabled' => true,
            'lernplattform.mistakes.threshold' => 80,
            'lernplattform.mistakes.min_confidence' => 0.6,
        ]);

        $students = collect(['Mia Hoffmann', 'Ben Schulz', 'Lena Wagner'])
            ->map(fn (string $name): User => User::factory()->student($this->schoolClass)->create(['name' => $name]));

        $lesson = Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor($students->all())
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-21']);

        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht die Pflanze für die Fotosynthese?',
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);

        // One answer of the AI per hand-in: too unsure, just below the minimum, just above it.
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody($this->evaluation($question, 10)))
            ->push($this->messageBody($this->evaluation($question, 59)))
            ->push($this->messageBody($this->evaluation($question, 61)))]);

        foreach ($students as $student) {
            $this->handIn($lesson, $student, $question);
        }

        Http::assertSentCount(3);

        $unsure = $this->answerOf($students[0], $lesson);
        // Fehlerpfad: a confidence of 10 is no classification at all.
        $this->assertSame(40, $unsure->score);
        $this->assertNull($unsure->mistake_type);
        $this->assertNull($unsure->mistake_source);
        $this->assertNull($unsure->mistake_confidence);

        // Fehlerpfad: 59 of 100 stays below the configured 0.6.
        $almost = $this->answerOf($students[1], $lesson);
        $this->assertSame(40, $almost->score);
        $this->assertNull($almost->mistake_type);
        $this->assertNull($almost->mistake_source);

        // Positivkontrolle: 61 of 100 reaches it, and the type is attributed to the AI.
        $confident = $this->answerOf($students[2], $lesson);
        $this->assertSame(40, $confident->score);
        $this->assertSame(MistakeType::Procedure, $confident->mistake_type);
        $this->assertSame(61, $confident->mistake_confidence);
        $this->assertSame(MistakeSource::Claude, $confident->mistake_source);

        // The evaluation itself is stored for every hand-in, only the hint is missing.
        $this->assertSame(3, Submission::query()->where('lesson_id', $lesson->getKey())->count());
        $this->assertSame(3, Submission::query()->where('lesson_id', $lesson->getKey())->whereNotNull('evaluated_at')->count());
    }

    public function test_a_teacher_correction_survives_a_second_run_of_the_recorder(): void
    {
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'lernplattform.mistakes.enabled' => true,
            'lernplattform.mistakes.threshold' => 80,
            'lernplattform.mistakes.min_confidence' => 0.6,
        ]);

        $student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);

        $lesson = Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor([$student])
            ->create(['subject' => 'Mathematik', 'topic' => 'Brüche kürzen üben', 'held_on' => '2026-10-21']);

        $first = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Wie kürzt du 6/8?',
            'expected_answer' => 'Zähler und Nenner durch 2 teilen.',
            'position' => 1,
        ]);
        $second = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Warum kürzt man überhaupt?',
            'expected_answer' => 'Damit die Zahlen kleiner werden und man sie leichter vergleicht.',
            'position' => 2,
        ]);

        // The AI's answer as its JSON schema expects it: a list of answers, each naming its question.
        $answerPayload = [
            ['question_id' => $first->getKey(), 'score' => 40, 'feedback' => 'Fast: ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
            ['question_id' => $second->getKey(), 'score' => 50, 'feedback' => 'Fast: begründe noch.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
        ];

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'understanding_score' => 45,
            'feedback' => 'Ein Schritt fehlt noch.',
            'gaps' => [],
            'answers' => $answerPayload,
        ])]);

        $this->handIn($lesson, $student, $first, $second);

        $submission = Submission::query()->sole();
        $this->assertSame(MistakeType::Procedure, $this->answerOfQuestion($submission, $first)->mistake_type);

        // The teacher's own order on the first answer.
        Livewire::actingAs($this->teacher)
            ->test(SubmissionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($submission), data: [
                'mistakes' => [$first->getKey() => MistakeType::Concept->value],
            ])
            ->assertNotified('Fehlerarten gespeichert');

        $corrected = $this->answerOfQuestion($submission, $first);
        $this->assertSame(MistakeType::Concept, $corrected->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $corrected->mistake_source);
        $this->assertNull($corrected->mistake_confidence);

        // A second evaluation of the same submission – the AI now names another type for the second answer. The
        // recorder reads the evaluation the assistant returns, keyed by question id.
        $secondEvaluation = ['answers' => [
            $first->getKey() => ['score' => 40, 'feedback' => 'Fast: ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
            $second->getKey() => ['score' => 50, 'feedback' => 'Fast: begründe noch.', 'mistake_type' => 'incomplete', 'mistake_confidence' => 80],
        ]];

        app(MistakeRecorder::class)->recordForSubmission($submission->fresh(['answers.question']), $secondEvaluation);

        // Fehlerpfad: the teacher's correction is not overwritten.
        $unchanged = $this->answerOfQuestion($submission, $first);
        $this->assertSame(MistakeType::Concept, $unchanged->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $unchanged->mistake_source);
        $this->assertNull($unchanged->mistake_confidence);
        $this->assertSame(40, $unchanged->score);

        // Positivkontrolle im selben Lauf: die Antwort ohne Korrektur wird neu eingeordnet.
        $reclassified = $this->answerOfQuestion($submission, $second);
        $this->assertSame(MistakeType::Incomplete, $reclassified->mistake_type);
        $this->assertSame(MistakeSource::Claude, $reclassified->mistake_source);
        $this->assertSame(80, $reclassified->mistake_confidence);
        // The hint never touches the marks it sits next to.
        $this->assertSame(50, $reclassified->score);
        $this->assertSame(45, $submission->refresh()->understanding_score);
    }

    public function test_the_most_common_mistake_type_steers_the_offline_practice_task(): void
    {
        // Ohne KI-Anbindung entwirft die Plattform die Förderaufgabe selbst, aus demselben Aufruf wie mit KI.
        config([
            'services.anthropic.enabled' => false,
            'lernplattform.mistakes.enabled' => true,
        ]);

        $student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        $competency = Competency::factory()->create([
            'subject' => 'Mathematik',
            'area' => 'Zahlen',
            'name' => 'Brüche kürzen',
            'description' => 'Zähler und Nenner durch denselben Faktor teilen',
        ]);

        $conceptLesson = $this->sourceLesson($student, $competency, 'Kürzen am Modell', MistakeType::Concept);
        $procedureLesson = $this->sourceLesson($student, $competency, 'Kürzen in Schritten', MistakeType::Procedure);

        $conceptTask = PracticeTasks::create(
            $this->teacher,
            $this->schoolClass,
            $competency,
            ['student_ids' => [$student->getKey()], 'lesson_ids' => [$conceptLesson->getKey()]],
            [$student->getKey()],
        );

        $procedureTask = PracticeTasks::create(
            $this->teacher,
            $this->schoolClass,
            $competency,
            ['student_ids' => [$student->getKey()], 'lesson_ids' => [$procedureLesson->getKey()]],
            [$student->getKey()],
        );

        $conceptQuestions = $conceptTask->questions()->orderBy('position')->get();
        $procedureQuestions = $procedureTask->questions()->orderBy('position')->get();

        // Positivkontrolle: beide Entwürfe sind vollständige Förderaufgaben mit genau einer Anwendungsfrage.
        $this->assertGreaterThanOrEqual(3, $conceptQuestions->count());
        $this->assertCount(1, $procedureQuestions->filter(fn (Question $question): bool => $question->type === QuestionType::Application));

        // concept: die Grundidee zuerst, danach wird geübt.
        $this->assertSame(QuestionType::Comprehension, $conceptQuestions->first()->type);
        $this->assertStringStartsWith('Lies zuerst die kurze Erklärung: ', $conceptQuestions->first()->prompt);
        $this->assertStringContainsString('Erkläre danach mit eigenen Worten', $conceptQuestions->first()->prompt);

        // procedure: der Weg in Teilschritten, die Anwendungsfrage steht vorn.
        $this->assertSame(QuestionType::Application, $procedureQuestions->first()->type);
        $this->assertStringStartsWith('Schritt für Schritt: ', $procedureQuestions->first()->prompt);
        $this->assertStringNotContainsString('Lies zuerst die kurze Erklärung', $procedureQuestions->first()->prompt);

        // Die beiden Entwürfe unterscheiden sich wirklich, nicht nur in einer Zeile.
        $this->assertNotSame($conceptQuestions->first()->prompt, $procedureQuestions->first()->prompt);
        $this->assertSame(2, Lesson::query()->where('practice_competency_id', $competency->getKey())->count());
    }

    public function test_the_ai_practice_task_names_the_mistake_type_without_naming_anyone(): void
    {
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
            'lernplattform.mistakes.enabled' => true,
        ]);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Hier übst du das Kürzen Schritt für Schritt.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was bedeutet Kürzen?', 'expected_answer' => 'Teilen durch denselben Faktor.'],
                ['type' => 'application', 'prompt' => 'Kürze 12/18 in Schritten.', 'expected_answer' => 'Durch 6 teilen ergibt 2/3.'],
            ],
        ])]);

        $student = User::factory()->student($this->schoolClass)->create([
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
        ]);
        $competency = $this->fractionsCompetency();

        $lesson = $this->sourceLesson($student, $competency, 'Brüche kürzen üben', MistakeType::Procedure);
        // The notes name the student: they are what the pseudonymizer has to cover.
        $lesson->update([
            'notes' => 'Zacharias Quellenberg hat 6/8 an der Tafel gekürzt. Danach hat er 12/18 in Schritten gerechnet.',
        ]);

        // Vorkontrolle: die häufigste Fehlerart der Gruppe ist die, die der Entwurf mitbekommen soll.
        $this->assertSame(
            MistakeType::Procedure,
            MistakeInsights::mostCommon([$student->getKey()], $competency->getKey(), [$lesson->getKey()]),
        );

        $task = PracticeTasks::create(
            $this->teacher,
            $this->schoolClass,
            $competency,
            ['student_ids' => [$student->getKey()], 'lesson_ids' => [$lesson->getKey()]],
            [$student->getKey()],
        );

        Http::assertSent(function (Request $request): bool {
            $text = collect($request->data()['messages'][0]['content'])
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");

            // The anonymous hint on the group's most common mistake type, with the instruction that fits it.
            $this->assertStringContainsString('Häufigste Fehlerart der Gruppe: Weg/Schritt falsch.', $text);
            $this->assertStringContainsString('durchgerechnetes Beispiel mit Teilschritten', $text);

            // Positivkontrolle: der Auftrag selbst kommt vollständig an …
            $this->assertStringContainsString('Fach: Mathematik', $text);
            $this->assertStringContainsString('Kompetenz: Zahlen – Brüche kürzen', $text);
            $this->assertStringContainsString('Danach hat er 12/18 in Schritten gerechnet.', $text);

            // … aber kein Name und keine Kennung einer Person.
            $this->assertStringContainsString('Schüler:in', $text);
            $this->assertStringNotContainsString('Zacharias', $text);
            $this->assertStringNotContainsString('Quellenberg', $text);
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $text);

            return true;
        });

        // The draft is stored as the Förderaufgabe of the competency, with the AI's questions.
        $this->assertSame($competency->getKey(), $task->practice_competency_id);
        $this->assertSame(2, $task->questions()->count());
    }

    /**
     * One hand-in through the real page: write a summary, answer the questions, hand in.
     */
    private function handIn(Lesson $lesson, User $student, Question ...$questions): void
    {
        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm([
                'summary' => self::SUMMARY,
                'answers' => collect($questions)->mapWithKeys(fn (Question $question): array => [$question->getKey() => 'Licht und Wasser'])->all(),
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!');
    }

    /**
     * The evaluation the AI returns for one answer with the given confidence, in the shape of its JSON schema.
     *
     * @return array<string, mixed>
     */
    private function evaluation(Question $question, int $confidence): array
    {
        return [
            'understanding_score' => 45,
            'feedback' => 'Ein Schritt fehlt noch.',
            'gaps' => [],
            'answers' => [
                [
                    'question_id' => $question->getKey(),
                    'score' => 40,
                    'feedback' => 'Fast: ein Schritt fehlt.',
                    'mistake_type' => MistakeType::Procedure->value,
                    'mistake_confidence' => $confidence,
                ],
            ],
        ];
    }

    private function answerOf(User $student, Lesson $lesson): Answer
    {
        return $student->submissions()->where('lesson_id', $lesson->getKey())->sole()->answers()->sole();
    }

    private function answerOfQuestion(Submission $submission, Question $question): Answer
    {
        return $submission->answers()->where('question_id', $question->getKey())->sole();
    }

    /**
     * An evaluated homework of the student in which the answer on the competency carries the given mistake type.
     */
    private function sourceLesson(User $student, Competency $competency, string $topic, MistakeType $type): Lesson
    {
        $lesson = Lesson::factory()
            ->for($this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->publishedFor([$student])
            ->create([
                'subject' => 'Mathematik',
                'topic' => $topic,
                'held_on' => '2026-10-20',
                'notes' => "An der Tafel wurde 6/8 gekürzt.\nDanach hat die Klasse 12/18 in Schritten gerechnet.",
            ]);

        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Wie kürzt du 6/8?',
            'expected_answer' => 'Zähler und Nenner durch 2 teilen.',
            'position' => 1,
            'competency_id' => $competency->getKey(),
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(40)->create();

        Answer::factory()
            ->for($submission)
            ->for($question)
            ->mistake($type, MistakeSource::Demo, 85)
            ->create(['answer' => '3/4', 'score' => 30]);

        return $lesson;
    }

    /**
     * A faked Messages API response.
     *
     * @param  array<string, mixed>  $result
     */
    private function messageResponse(array $result): PromiseInterface
    {
        return Http::response($this->messageBody($result));
    }

    /**
     * A faked Messages API response whose text block holds the given JSON, after an (empty) thinking block.
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
