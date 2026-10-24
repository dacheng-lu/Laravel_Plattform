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

        Http::assertSent(function (Request $request) use ($student): bool {
            $raw = $request->body();
            $body = (string) json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // Fehlerpfad: neither the name nor any other identifier of the student leaves the platform.
            $this->assertStringNotContainsString('Zacharias', $raw);
            $this->assertStringNotContainsString('Quellenberg', $raw);
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $raw);
            $this->assertStringNotContainsString($student->email, $raw);
            $this->assertStringNotContainsString('Schüler:in '.$student->id, $body);
            $this->assertStringNotContainsString('student_id', $body);

            // Positivkontrolle: the rest of the texts arrives, and the name became the neutral label.
            $this->assertSame(2, substr_count($body, 'Schüler:in A'));
            $this->assertStringContainsString('hat gesehen, dass Pflanzen Licht brauchen', $body);
            $this->assertStringContainsString('Licht und Wasser reichen schon', $body);
            $this->assertStringContainsString('Was braucht die Pflanze für die Fotosynthese?', $body);

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
            $this->assertSame(array_column(MistakeType::cases(), 'value'), $items['properties']['mistake_type']['anyOf'][0]['enum']);
            $this->assertCount(5, $items['properties']['mistake_type']['anyOf'][0]['enum']);
            $this->assertSame(['type' => 'string', 'enum' => ['concept', 'procedure', 'incomplete', 'off_topic', 'empty']], $items['properties']['mistake_type']['anyOf'][0]);
            $this->assertSame(['type' => 'null'], $items['properties']['mistake_type']['anyOf'][1]);
            $this->assertSame([['type' => 'integer'], ['type' => 'null']], $items['properties']['mistake_confidence']['anyOf']);
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

    /**
     * A submission whose summary and answer both name the student of the lesson's class.
     *
     * @return array{0: Submission, 1: Question, 2: User}
     */
    private function submissionNamingTheStudent(): array
    {
        $lesson = Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-21',
            'notes' => 'Wasserpest unter der Lampe: Sauerstoffbläschen gezählt.',
        ]);
        $teacher = User::factory()->teacher()->create([
            'name' => 'Hannelore Trautmann',
            'school_id' => $lesson->schoolClass->school_id,
        ]);
        $lesson->update(['teacher_id' => $teacher->id]);

        $student = User::factory()->student($lesson->schoolClass)->create([
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
            'email' => 'zacharias.quellenberg@example.org',
        ]);
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
