<?php

namespace Tests\Feature\Ai;

use App\Ai\ClaudeLessonAssistant;
use App\Ai\LessonAssistant;
use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ClaudeLessonAssistantTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_is_bound_when_the_connection_is_enabled(): void
    {
        $this->assertInstanceOf(ClaudeLessonAssistant::class, app(LessonAssistant::class));
    }

    public function test_drafting_sends_a_structured_output_request_with_worksheets_and_parses_the_questions(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('worksheets/arbeitsblatt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $lesson = Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'attachments' => ['worksheets/arbeitsblatt.pdf', 'worksheets/geloescht.png'],
        ]);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was ist Chlorophyll?', 'expected_answer' => 'Der grüne Farbstoff.', 'competency_id' => $competency->id],
                ['type' => 'reflection', 'prompt' => 'Was hat dich am Versuch überrascht?', 'expected_answer' => 'Individuell.', 'competency_id' => 999],
            ],
        ])]);

        $draft = app(LessonAssistant::class)->draftHomework($lesson);

        $this->assertSame('Pflanzen bauen mit Licht Glucose auf.', $draft['summary']);
        $this->assertSame(QuestionType::Comprehension, $draft['questions'][0]['type']);
        $this->assertSame($competency->id, $draft['questions'][0]['competency_id']);
        $this->assertSame(QuestionType::Reflection, $draft['questions'][1]['type']);
        $this->assertNull($draft['questions'][1]['competency_id']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $content = $body['messages'][0]['content'];

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));
            $this->assertTrue($request->hasHeader('anthropic-version', '2026-10-21'));
            $this->assertTrue($request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));
            $this->assertSame('claude-opus-5-5', $body['model']);
            $this->assertSame(16000, $body['max_tokens']);
            $this->assertSame('default', $body['fallbacks']);
            $this->assertSame('medium', $body['output_config']['effort']);
            $this->assertSame('json_schema', $body['output_config']['format']['type']);
            $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
            $this->assertSame(['summary', 'questions'], $body['output_config']['format']['schema']['required']);
            $this->assertSame([], array_intersect(['temperature', 'top_p', 'top_k', 'thinking'], array_keys($body)));
            $this->assertCount(2, $content);
            $this->assertSame('document', $content[0]['type']);
            $this->assertSame('application/pdf', $content[0]['source']['media_type']);
            $this->assertSame('text', $content[1]['type']);
            $this->assertStringContainsString('Fotosynthese', $content[1]['text']);
            $this->assertStringContainsString('- ID', $content[1]['text']);

            return true;
        });
    }

    public function test_a_practice_task_sends_only_competency_lessons_and_anonymous_gaps_and_maps_every_question_to_the_competency(): void
    {
        $student = User::factory()->student()->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg', 'email' => 'zacharias.quellenberg@example.org']);
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'area' => 'Erkenntnisgewinnung', 'name' => 'Versuche deuten', 'description' => 'Beobachtungen beschreiben und deuten']);
        $lesson = Lesson::factory()->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-21', 'notes' => 'Wasserpest unter der Lampe: Sauerstoffbläschen gezählt.']);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Hier übst du, Versuche zu deuten.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was zeigt der Versuch?', 'expected_answer' => 'Sauerstoff entsteht bei Licht.'],
                ['type' => 'comprehension', 'prompt' => 'Warum steigen Bläschen auf?', 'expected_answer' => 'Sauerstoff ist ein Gas.'],
                ['type' => 'application', 'prompt' => 'Plane einen eigenen Versuch.', 'expected_answer' => 'Eine Variable wird verändert.'],
                ['type' => 'comprehension', 'prompt' => 'Knobelaufgabe', 'expected_answer' => 'Individuell.'],
                ['type' => 'comprehension', 'prompt' => 'Fünfte Frage, zu viel', 'expected_answer' => 'Wird abgeschnitten.'],
            ],
        ])]);

        $draft = app(LessonAssistant::class)->draftPracticeTask($competency, 'Biologie', collect([$lesson]), ['Beschreibe genauer, was du beobachtest.']);

        $this->assertSame('Hier übst du, Versuche zu deuten.', $draft['summary']);
        $this->assertCount(4, $draft['questions']);
        $this->assertSame(QuestionType::Application, $draft['questions'][2]['type']);
        $this->assertSame([$competency->id], array_values(array_unique(array_column($draft['questions'], 'competency_id'))));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($student): bool {
            $body = $request->data();
            $text = $body['messages'][0]['content'][0]['text'];
            $schema = $body['output_config']['format']['schema'];

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));
            $this->assertTrue($request->hasHeader('anthropic-version', '2026-10-21'));
            $this->assertSame('default', $body['fallbacks']);
            $this->assertSame('json_schema', $body['output_config']['format']['type']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertSame(['summary', 'questions'], $schema['required']);
            $this->assertSame(['type', 'prompt', 'expected_answer'], $schema['properties']['questions']['items']['required']);
            $this->assertCount(1, $body['messages'][0]['content']);
            $this->assertStringContainsString('Fach: Biologie', $text);
            $this->assertStringContainsString('Erkenntnisgewinnung – Versuche deuten', $text);
            $this->assertStringContainsString('Beobachtungen beschreiben und deuten', $text);
            $this->assertStringContainsString('Thema: Fotosynthese', $text);
            $this->assertStringContainsString('21.10.2026', $text);
            $this->assertStringContainsString('Wasserpest unter der Lampe', $text);
            $this->assertStringContainsString('- Beschreibe genauer, was du beobachtest.', $text);
            $this->assertStringContainsString('Förderaufgabe', $body['system']);
            $this->assertStringNotContainsString('Quellenberg', $request->body());
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $request->body());
            $this->assertStringNotContainsString($student->email, $request->body());

            return true;
        });
    }

    public function test_a_practice_task_sends_only_topic_and_date_of_a_lesson_whose_notes_were_withheld(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $own = Lesson::factory()->create(['topic' => 'Fotosynthese', 'held_on' => '2026-10-21', 'notes' => 'Eigene Notizen zur Wasserpest.']);
        $colleagues = Lesson::factory()->create(['topic' => 'Zellaufbau', 'held_on' => '2026-10-22', 'notes' => 'GEHEIME NOTIZ von Kollegin']);
        $colleagues->notes = null;

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Hier übst du.',
            'questions' => [['type' => 'comprehension', 'prompt' => 'Frage?', 'expected_answer' => 'Antwort.']],
        ])]);

        app(LessonAssistant::class)->draftPracticeTask($competency, 'Biologie', collect([$own, $colleagues]), []);

        Http::assertSent(function (Request $request): bool {
            $text = $request->data()['messages'][0]['content'][0]['text'];

            $this->assertStringContainsString("Thema: Fotosynthese\nDatum der Stunde: 21.10.2026\nNotizen der Lehrkraft:\nEigene Notizen zur Wasserpest.", $text);
            $this->assertStringContainsString("Thema: Zellaufbau\nDatum der Stunde: 22.10.2026", $text);
            $this->assertSame(1, substr_count($text, 'Notizen der Lehrkraft'));
            $this->assertStringNotContainsString('GEHEIME NOTIZ', $text);

            return true;
        });
    }

    public function test_a_practice_task_without_questions_is_an_error_instead_of_an_empty_task(): void
    {
        Http::fake(['api.anthropic.com/*' => $this->messageResponse(['summary' => 'Nichts.', 'questions' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine Übungsfragen');

        app(LessonAssistant::class)->draftPracticeTask(Competency::factory()->create(), 'Biologie', collect(), []);
    }

    /**
     * Issue #3, Entscheid A: an evaluation carries the question, its expected answer and the student's answer – no
     * lesson context, no notes of the teacher, no summary of the student. Identity markers stay off the wire as before.
     */
    public function test_evaluation_sends_question_expected_answer_and_answer_but_neither_summary_nor_identity(): void
    {
        $student = User::factory()->student()->create([
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
            'email' => 'zacharias.quellenberg@example.org',
        ]);
        $lesson = Lesson::factory()->create();
        // Ein fester Fragetext, damit die Positivkontrolle unten nicht an Umlaut-Escapes im rohen JSON hängt.
        $question = Question::factory()->for($lesson)->create(['prompt' => 'Was macht Chlorophyll?']);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create(['summary' => 'Meine eigene Zusammenfassung.']);
        $submission->answers()->create(['question_id' => $question->id, 'answer' => 'Meine Antwort auf die Frage.']);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'understanding_score' => 140,
            'feedback' => 'Gut gemacht!',
            'gaps' => ['Eins', 'Zwei', 'Drei', 'Vier'],
            'answers' => [
                ['question_id' => $question->id, 'score' => 80, 'feedback' => 'Richtig.'],
                ['question_id' => 999999, 'score' => 50, 'feedback' => 'Gibt es nicht.'],
            ],
        ])]);

        $evaluation = app(LessonAssistant::class)->evaluateSubmission($submission);

        $this->assertSame(100, $evaluation['understanding_score']);
        $this->assertSame('Gut gemacht!', $evaluation['feedback']);
        $this->assertSame(['Eins', 'Zwei', 'Drei'], $evaluation['gaps']);
        $this->assertSame([$question->id => ['score' => 80, 'feedback' => 'Richtig.']], $evaluation['answers']);

        Http::assertSent(function (Request $request) use ($question): bool {
            $body = $request->body();

            // Die Zusammenfassung der Schülerin geht seit Entscheid A nicht mehr mit …
            $this->assertStringNotContainsString('Meine eigene Zusammenfassung.', $body);

            // … die Positivkontrollen zeigen, dass der Request überhaupt Inhalt trägt: Antwort und Fragetext sind drin.
            $this->assertStringContainsString('Meine Antwort auf die Frage.', $body);
            $this->assertStringContainsString($question->prompt, $body);

            $this->assertStringNotContainsString('Zacharias', $body);
            $this->assertStringNotContainsString('Quellenberg', $body);
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $body);

            return true;
        });
    }

    public function test_follow_up_and_assessment_play_frieda_without_the_students_identity(): void
    {
        $student = User::factory()->student()->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg']);
        $session = ExplanationSession::factory()->for($student, 'student')->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Pflanzen machen aus Licht Zucker.']);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody(['question' => '  Woher kommt das Licht?  ']))
            ->push($this->messageBody(['understanding_score' => -5, 'feedback' => 'Danke, das war verständlich!']))]);

        $assistant = app(LessonAssistant::class);

        $this->assertSame('Woher kommt das Licht?', $assistant->askFollowUp($session));
        $this->assertSame(['understanding_score' => 0, 'feedback' => 'Danke, das war verständlich!'], $assistant->assessExplanation($session));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('Tuto', $request['system']);
            $this->assertStringContainsString('Pflanzen machen aus Licht Zucker.', $request->body());
            $this->assertStringNotContainsString('Quellenberg', $request->body());

            return true;
        });
    }

    public function test_a_refusal_raises_an_error_instead_of_returning_content(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abgelehnt');

        app(LessonAssistant::class)->askFollowUp(ExplanationSession::factory()->create());
    }

    public function test_a_missing_api_key_fails_before_any_request(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        try {
            app(LessonAssistant::class)->assessExplanation(ExplanationSession::factory()->create());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('API-Schlüssel', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * A faked Messages API response whose text block holds the given JSON, after an (empty) thinking block.
     *
     * @param  array<string, mixed>  $result
     */
    private function messageResponse(array $result): PromiseInterface
    {
        return Http::response($this->messageBody($result));
    }

    /**
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
