<?php

namespace Tests\Feature\Mistakes;

use App\Ai\ClaudeLessonAssistant;
use App\MistakeType;
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
use Tests\TestCase;

/**
 * The Claude path of issue #3: the mistake type arrives in-band with the evaluation, implausible values are dropped
 * and no name of a person reaches the API.
 */
class ClaudeEvaluationMistakeTest extends TestCase
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
            'lernplattform.mistakes.threshold' => 80,
        ]);
    }

    public function test_request_is_pseudonymised(): void
    {
        [$submission, $question, $student] = $this->submissionNamingTheStudent();
        // A teacher's summary of the lesson: it names no one, but it must not leave the platform either.
        $submission->lesson->update(['summary' => 'Lehrkraft-Zusammenfassung: Wasserpest, Licht und Bläschen.']);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'understanding_score' => 62,
            'feedback' => 'Guter Anfang!',
            'gaps' => ['Gehe noch einmal auf „Chlorophyll“ ein.'],
            'answers' => [
                ['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast richtig: ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 85],
            ],
        ])]);

        $evaluation = app(ClaudeLessonAssistant::class)->evaluateSubmission($submission);

        $this->assertSame(55, $evaluation['answers'][$question->id]['score']);
        $this->assertSame('procedure', $evaluation['answers'][$question->id]['mistake_type']);

        Http::assertSent(function (Request $request) use ($student, $question): bool {
            $raw = $request->body();
            $body = (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // Fehlerpfad: neither the names nor any other identifier of the students reach the API – not from the
            // answer, not from the summary and not from the teacher's notes.
            foreach (['Zacharias', 'Quellenberg', 'Mia', 'Klarheit', 'Hannelore', 'Trautmann'] as $namePart) {
                $this->assertDoesNotMatchRegularExpression('/\b'.$namePart.'\b/u', $body);
            }
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $raw);
            $this->assertStringNotContainsString($student->email, $raw);
            $this->assertStringNotContainsString('student_id', $body);
            $this->assertStringNotContainsString('Schüler-ID', $body);

            // The id itself must not appear as a number either. It is set explicitly in the fixture, so the check
            // searches for exactly this number and nothing else.
            $this->assertSame(987654, $student->getKey());
            $this->assertDoesNotMatchRegularExpression('/\b987654\b/', $body);

            // Positivkontrolle: Frage, Erwartungshorizont und Antwort gehen mit, die Namen darin sind ersetzt.
            $this->assertSame(1, substr_count($body, 'Schüler:in A'));
            $this->assertStringContainsString('Schüler:in A sagt: Licht und Wasser reichen schon.', $body);
            $this->assertStringContainsString('Was braucht die Pflanze für die Fotosynthese?', $body);
            $this->assertStringContainsString('Licht, Wasser und Kohlenstoffdioxid.', $body);
            $this->assertMatchesRegularExpression('/\b'.$question->id.'\b/', $body);

            // Entscheidung A (Issue #3): Stundenkontext und Zusammenfassungen bleiben auf der Plattform – weder die
            // Notizen der Lehrkraft noch ihre Zusammenfassung noch die Zusammenfassung der Schülerin gehen mit.
            $this->assertStringNotContainsString('Wasserpest unter der Lampe', $body);
            $this->assertStringNotContainsString('Lehrkraft-Zusammenfassung', $body);
            $this->assertStringNotContainsString('hat gesehen, dass Pflanzen Licht brauchen', $body);

            return true;
        });
    }

    public function test_schema_extended(): void
    {
        [$submission, $question] = $this->submissionNamingTheStudent();

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'understanding_score' => 62,
            'feedback' => 'Guter Anfang!',
            'gaps' => [],
            'answers' => [
                ['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast richtig.', 'mistake_type' => null, 'mistake_confidence' => null],
            ],
        ])]);

        app(ClaudeLessonAssistant::class)->evaluateSubmission($submission);

        Http::assertSent(function (Request $request): bool {
            $system = $request->data()['system'];
            $items = $request->data()['output_config']['format']['schema']['properties']['answers']['items'];

            $this->assertSame(
                ['mistake_type', 'mistake_confidence'],
                array_values(array_intersect(array_keys($items['properties']), ['mistake_type', 'mistake_confidence'])),
            );
            // The schema offers exactly the five types of the spec, compared as values instead of as a nested structure.
            $this->assertSame(array_values(array_column(MistakeType::cases(), 'value')), $items['properties']['mistake_type']['anyOf'][0]['enum']);
            $this->assertSame('string', $items['properties']['mistake_type']['anyOf'][0]['type']);
            $this->assertSame('null', $items['properties']['mistake_type']['anyOf'][1]['type']);
            $this->assertSame(['integer', 'null'], array_values(array_column($items['properties']['mistake_confidence']['anyOf'], 'type')));
            $this->assertContains('mistake_type', $items['required']);
            $this->assertContains('mistake_confidence', $items['required']);
            $this->assertFalse($items['additionalProperties']);

            $this->assertStringContainsString('unter 80', $system);
            $this->assertStringContainsString('Fehlerart', $system);
            $this->assertStringContainsString('nie die Person', $system);
            $this->assertStringContainsString('Motivation oder Gefühle', $system);

            return true;
        });
    }

    public function test_disabled_mistakes_leave_request_and_result_untouched(): void
    {
        [$submission, $question] = $this->submissionNamingTheStudent();

        // The fake answers with a classification anyway: with the feature switched off it must never reach an answer.
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody([
                'understanding_score' => 62,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast richtig.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
                ],
            ]))
            ->push($this->messageBody([
                'understanding_score' => 62,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast richtig.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
                ],
            ]))]);

        $assistant = app(ClaudeLessonAssistant::class);

        // Fehlerpfad: ausgeschaltet fragt die KI nichts über Fehlerarten und liefert keine.
        config()->set('lernplattform.mistakes.enabled', false);
        $disabled = $assistant->evaluateSubmission($submission);

        $this->assertSame([$question->id => ['score' => 55, 'feedback' => 'Fast richtig.']], $disabled['answers']);
        $this->assertSame(62, $disabled['understanding_score']);

        $sentWhileDisabled = Http::recorded()[0][0]->data();
        $itemProperties = $sentWhileDisabled['output_config']['format']['schema']['properties']['answers']['items']['properties'];

        $this->assertArrayNotHasKey('mistake_type', $itemProperties);
        $this->assertArrayNotHasKey('mistake_confidence', $itemProperties);
        $this->assertStringNotContainsString('Fehlerart', $sentWhileDisabled['system']);
        $this->assertStringNotContainsString('mistake_type', $sentWhileDisabled['system']);

        // Positivkontrolle: eingeschaltet stehen Schema, Anweisung und Ergebnis wieder da.
        config()->set('lernplattform.mistakes.enabled', true);
        $enabled = $assistant->evaluateSubmission($submission);

        $this->assertSame('procedure', $enabled['answers'][$question->id]['mistake_type']);
        $this->assertSame(90, $enabled['answers'][$question->id]['mistake_confidence']);

        $sentWhileEnabled = Http::recorded()[1][0]->data();
        $itemProperties = $sentWhileEnabled['output_config']['format']['schema']['properties']['answers']['items']['properties'];

        $this->assertArrayHasKey('mistake_type', $itemProperties);
        $this->assertArrayHasKey('mistake_confidence', $itemProperties);
        $this->assertStringContainsString('Fehlerart', $sentWhileEnabled['system']);

        Http::assertSentCount(2);
    }

    public function test_invalid_mistake_dropped(): void
    {
        [$submission, $question] = $this->submissionNamingTheStudent();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody([
                'understanding_score' => 62,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 55, 'feedback' => 'Fast richtig.', 'mistake_type' => 'gefuehl', 'mistake_confidence' => 90],
                ],
            ]))
            ->push($this->messageBody([
                'understanding_score' => 62,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 40, 'feedback' => 'Da fehlt noch Wesentliches.', 'mistake_type' => 'procedure', 'mistake_confidence' => 150],
                ],
            ]))
            ->push($this->messageBody([
                'understanding_score' => 85,
                'feedback' => 'Stark!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 70, 'feedback' => 'Gut erklärt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 70],
                ],
            ]))]);

        $assistant = app(ClaudeLessonAssistant::class);

        // Fehlerpfad: an unknown type is no classification at all, and the score stays as the AI gave it.
        $unknownType = $assistant->evaluateSubmission($submission);
        $this->assertSame([$question->id => ['score' => 55, 'feedback' => 'Fast richtig.']], $unknownType['answers']);
        $this->assertSame(62, $unknownType['understanding_score']);

        // Fehlerpfad: a confidence above 100 is implausible, so the keys are missing instead of being clamped.
        $implausibleConfidence = $assistant->evaluateSubmission($submission);
        $this->assertSame([$question->id => ['score' => 40, 'feedback' => 'Da fehlt noch Wesentliches.']], $implausibleConfidence['answers']);
        $this->assertSame(62, $implausibleConfidence['understanding_score']);

        // Positivkontrolle: a plausible classification is kept next to the unchanged score.
        $valid = $assistant->evaluateSubmission($submission);
        $this->assertEquals([$question->id => ['score' => 70, 'feedback' => 'Gut erklärt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 70]], $valid['answers']);
        $this->assertSame('procedure', $valid['answers'][$question->id]['mistake_type']);
        $this->assertSame(70, $valid['answers'][$question->id]['mistake_confidence']);

        Http::assertSentCount(3);
    }

    public function test_threshold_gates_the_mistake_type(): void
    {
        [$submission, $question] = $this->submissionNamingTheStudent();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody([
                'understanding_score' => 90,
                'feedback' => 'Stark!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 85, 'feedback' => 'Sehr gut.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
                ],
            ]))
            ->push($this->messageBody([
                'understanding_score' => 82,
                'feedback' => 'Gut gemacht!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 80, 'feedback' => 'Passt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
                ],
            ]))
            ->push($this->messageBody([
                'understanding_score' => 55,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 50, 'feedback' => 'Ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90],
                ],
            ]))]);

        $assistant = app(ClaudeLessonAssistant::class);

        // Fehlerpfad: above the threshold the server drops the type, even though the AI returned one.
        $aboveThreshold = $assistant->evaluateSubmission($submission);
        $this->assertSame([$question->id => ['score' => 85, 'feedback' => 'Sehr gut.']], $aboveThreshold['answers']);
        $this->assertArrayNotHasKey('mistake_type', $aboveThreshold['answers'][$question->id]);

        // Grenzfall: score == threshold counts as good enough for no mistake type.
        $atThreshold = $assistant->evaluateSubmission($submission);
        $this->assertSame([$question->id => ['score' => 80, 'feedback' => 'Passt.']], $atThreshold['answers']);

        // Positivkontrolle: below the threshold the same classification is kept.
        $belowThreshold = $assistant->evaluateSubmission($submission);
        $this->assertEquals([$question->id => ['score' => 50, 'feedback' => 'Ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 90]], $belowThreshold['answers']);
        $this->assertSame('procedure', $belowThreshold['answers'][$question->id]['mistake_type']);

        Http::assertSentCount(3);
    }

    /**
     * The spec guardrail: an evaluation carries texts only. Even a lesson with a worksheet sends no document or image
     * block; the homework draft of the same lesson still does, so the guard is not simply a broken attachment path.
     */
    public function test_the_evaluation_sends_no_worksheet_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('worksheets/arbeitsblatt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        [$submission, $question] = $this->submissionNamingTheStudent();
        $lesson = $submission->lesson;
        $lesson->update(['attachments' => ['worksheets/arbeitsblatt.pdf']]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody([
                'understanding_score' => 55,
                'feedback' => 'Guter Anfang!',
                'gaps' => [],
                'answers' => [
                    ['question_id' => $question->id, 'score' => 50, 'feedback' => 'Ein Schritt fehlt.', 'mistake_type' => 'procedure', 'mistake_confidence' => 85],
                ],
            ]))
            ->push($this->messageBody([
                'summary' => 'Pflanzen brauchen Licht und Wasser.',
                'questions' => [
                    ['type' => 'comprehension', 'prompt' => 'Was ist Chlorophyll?', 'expected_answer' => 'Der grüne Farbstoff.', 'competency_id' => null],
                ],
            ]))]);

        app(ClaudeLessonAssistant::class)->evaluateSubmission($submission->fresh());

        // Positivkontrolle: der Hausaufgaben-Entwurf derselben Stunde schickt das Arbeitsblatt weiterhin mit.
        app(ClaudeLessonAssistant::class)->draftHomework($lesson->fresh());

        $contents = Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data()['messages'][0]['content'])
            ->values();

        $this->assertCount(2, $contents);

        // Auswertung: ein Textblock, kein Bild und kein Dokument – auch wenn die Stunde ein Arbeitsblatt hat.
        $this->assertSame(['text'], array_column($contents[0], 'type'));
        $this->assertStringContainsString('Fragen und Antworten', $contents[0][0]['text']);
        $this->assertStringNotContainsString('application/pdf', (string) json_encode($contents[0]));

        // Der Entwurf dagegen hängt das PDF als Dokument an.
        $this->assertSame('document', $contents[1][0]['type']);
        $this->assertSame('application/pdf', $contents[1][0]['source']['media_type']);
    }

    /**
     * A submission whose summary, answer and the lesson notes name students of the lesson's class.
     *
     * @return array{0: Submission, 1: Question, 2: User}
     */
    private function submissionNamingTheStudent(): array
    {
        $lesson = Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-21',
            'notes' => 'Wasserpest unter der Lampe: Sauerstoffbläschen gezählt. Zacharias Quellenberg hat die Bläschen gezählt, Mia Klarheit hat sie notiert.',
        ]);
        $teacher = User::factory()->teacher()->create([
            'name' => 'Hannelore Trautmann',
            'school_id' => $lesson->schoolClass->school_id,
        ]);
        $lesson->update(['teacher_id' => $teacher->id]);

        $student = User::factory()->student($lesson->schoolClass)->create([
            'id' => 987654,
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
            'email' => 'zacharias.quellenberg@example.org',
        ]);
        // A classmate who is only mentioned in the notes: no submission, no account activity of their own. The label
        // order follows the ids, so the classmate gets the id right after the student's for a stable "Schüler:in A/B".
        User::factory()->student($lesson->schoolClass)->create(['id' => 987655, 'name' => 'Mia Klarheit']);

        $question = Question::factory()->for($lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht die Pflanze für die Fotosynthese?',
            'expected_answer' => 'Licht, Wasser und Kohlenstoffdioxid.',
            'position' => 1,
        ]);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create([
            'summary' => 'Zacharias Quellenberg hat gesehen, dass Pflanzen Licht brauchen.',
        ]);
        $submission->answers()->create([
            'question_id' => $question->id,
            'answer' => 'Zacharias Quellenberg sagt: Licht und Wasser reichen schon.',
        ]);

        return [$submission, $question, $student];
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
