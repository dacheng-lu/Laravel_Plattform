<?php

namespace Tests\Feature\Mistakes;

use App\Ai\ClaudeLessonAssistant;
use App\Ai\DemoLessonAssistant;
use App\MistakeType;
use App\Models\Competency;
use App\Models\Lesson;
use App\QuestionType;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #3, AC-9: the group's most common mistake type steers the Förderaufgabe – offline through the order and the
 * wording of the questions, at Claude through one added line in the prompt. Without a type nothing changes.
 */
class PracticeTaskMistakeFocusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const array GAPS = [
        'Gehe noch einmal auf „Chlorophyll“ ein.',
        'Schreib deine Zusammenfassung ausführlicher: Was war der Ablauf der Stunde?',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
        ]);
    }

    public function test_demo_differs_by_mistake_type(): void
    {
        $assistant = app(DemoLessonAssistant::class);
        [$competency, $lesson] = $this->competencyAndLesson();

        $concept = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS, MistakeType::Concept);
        $procedure = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS, MistakeType::Procedure);
        $incomplete = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS, MistakeType::Incomplete);
        $withoutArgument = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS);
        $explicitNull = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS, null);

        // Concept starts with the idea, Procedure with the application question.
        $this->assertSame(QuestionType::Comprehension, $concept['questions'][0]['type']);
        $this->assertStringStartsWith('Lies zuerst die kurze Erklärung: ', $concept['questions'][0]['prompt']);
        $this->assertSame(QuestionType::Application, $procedure['questions'][0]['type']);
        $this->assertStringStartsWith('Schritt für Schritt: ', $procedure['questions'][0]['prompt']);
        $this->assertStringContainsString('Jetzt wird es praktisch:', $procedure['questions'][0]['prompt']);

        // Incomplete asks with a sentence frame instead of a free answer.
        foreach ($incomplete['questions'] as $question) {
            $this->assertStringStartsWith('Ergänze: ', $question['prompt']);
            $this->assertStringContainsString('… weil …', $question['prompt']);
        }

        // Every variant keeps the shape of a Förderaufgabe: at most four questions, exactly one application.
        foreach ([$concept, $procedure, $incomplete, $withoutArgument] as $draft) {
            $applications = array_filter($draft['questions'], fn (array $question): bool => $question['type'] === QuestionType::Application);

            $this->assertLessThanOrEqual(4, count($draft['questions']));
            $this->assertCount(1, $applications);
        }

        // Positivkontrolle: no mistake type (missing argument or explicit null) leaves the draft exactly as it was.
        $this->assertSame($withoutArgument, $explicitNull);
        $this->assertSame(QuestionType::Comprehension, $withoutArgument['questions'][0]['type']);
        $this->assertStringStartsWith('Zum Aufwärmen: ', $withoutArgument['questions'][0]['prompt']);
        $this->assertSame($withoutArgument['summary'], $procedure['summary']);
    }

    public function test_claude_prompt_mentions_mistake_type(): void
    {
        [$competency, $lesson] = $this->competencyAndLesson();

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Hier übst du, Versuche zu deuten.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was zeigt der Versuch?', 'expected_answer' => 'Sauerstoff entsteht bei Licht.'],
                ['type' => 'application', 'prompt' => 'Plane einen eigenen Versuch.', 'expected_answer' => 'Eine Variable wird verändert.'],
            ],
        ])]);

        $assistant = app(ClaudeLessonAssistant::class);
        $withType = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS, MistakeType::Procedure);
        $withoutType = $assistant->draftPracticeTask($competency, 'Biologie', collect([$lesson]), self::GAPS);

        $bodies = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values();

        $this->assertCount(2, $bodies);

        $focused = $bodies[0]['messages'][0]['content'][0]['text'];
        $neutral = $bodies[1]['messages'][0]['content'][0]['text'];

        $this->assertStringContainsString('Häufigste Fehlerart der Gruppe: Weg/Schritt falsch.', $focused);
        $this->assertStringContainsString('durchgerechnetes Beispiel mit Teilschritten', $focused);

        // Positivkontrolle: without a mistake type neither the line nor any instruction about it appears.
        $this->assertStringNotContainsString('Fehlerart', $neutral);
        $this->assertStringNotContainsString('Fehlerart', $bodies[1]['system']);
        $this->assertStringContainsString('Fach: Biologie', $neutral);
        $this->assertStringContainsString('- Gehe noch einmal auf „Chlorophyll“ ein.', $neutral);

        $this->assertSame('Hier übst du, Versuche zu deuten.', $withType['summary']);
        $this->assertSame($withType['summary'], $withoutType['summary']);
    }

    /**
     * @return array{0: Competency, 1: Lesson}
     */
    private function competencyAndLesson(): array
    {
        $competency = Competency::factory()->create([
            'subject' => 'Biologie',
            'area' => 'Erkenntnisgewinnung',
            'name' => 'Versuche deuten',
            'description' => 'Beobachtungen beschreiben und deuten',
        ]);
        $lesson = Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-21',
            'notes' => "Wasserpest unter der Lampe: Sauerstoffbläschen gezählt.\nJe heller das Licht, desto mehr Bläschen.",
        ]);

        return [$competency, $lesson];
    }

    /**
     * A faked Messages API response whose text block holds the given JSON, after an (empty) thinking block.
     *
     * @param  array<string, mixed>  $result
     */
    private function messageResponse(array $result): PromiseInterface
    {
        return Http::response([
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
        ]);
    }
}
