<?php

namespace Tests\Feature\Mistakes;

use App\Ai\DemoMistakeClassifier;
use App\Ai\MistakeClassification;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offline rules of the spec, one test per rule: no attempt, incomplete, off topic – and "otherwise no label".
 */
class DemoMistakeClassifierTest extends TestCase
{
    use RefreshDatabase;

    private const string PROMPT = 'Was braucht eine Pflanze für die Fotosynthese?';

    private const string EXPECTED = 'Pflanzen brauchen Licht, Wasser und Kohlendioxid für die Fotosynthese; daraus entsteht Zucker und Sauerstoff.';

    public function test_a_blank_answer_is_no_attempt(): void
    {
        foreach (['leer' => '', 'nur Leerzeichen' => '   ', 'nur Zeilenumbrüche' => " \n\t "] as $case => $answer) {
            $classification = $this->classify($answer);

            $this->assertNotNull($classification, $case);
            $this->assertSame(MistakeType::Empty, $classification->type, $case);
            $this->assertSame(100, $classification->confidence, $case);
            $this->assertSame(MistakeSource::Demo, $classification->source, $case);
        }

        // Positivkontrolle: an answered question is not "no attempt".
        $this->assertNotSame(MistakeType::Empty, $this->classify(self::EXPECTED)?->type);
    }

    public function test_a_very_short_answer_is_incomplete(): void
    {
        // The expected answer has 14 words, three words stay below the configured 30 % share.
        $classification = $this->classify('Licht und Wasser');

        $this->assertNotNull($classification);
        $this->assertSame(MistakeType::Incomplete, $classification->type);
        $this->assertSame(70, $classification->confidence);
        $this->assertSame(MistakeSource::Demo, $classification->source);

        // Positivkontrolle: an answer as long as the expected answer is not "incomplete".
        $this->assertNotSame(MistakeType::Incomplete, $this->classify(self::EXPECTED)?->type);
    }

    public function test_an_answer_without_a_shared_content_word_is_off_topic(): void
    {
        $classification = $this->classify('Der Ausflug der Klasse ging in den Zoo zu den Elefanten.');

        $this->assertNotNull($classification);
        $this->assertSame(MistakeType::OffTopic, $classification->type);
        $this->assertSame(65, $classification->confidence);
        $this->assertSame(MistakeSource::Demo, $classification->source);

        // Positivkontrolle: an answer that names the subject stays without that label.
        $this->assertNotSame(MistakeType::OffTopic, $this->classify('Pflanzen brauchen Licht und Wasser zum Wachsen.')?->type);
    }

    public function test_a_derived_word_counts_as_the_same_content(): void
    {
        // "fotosynthetisch" is derived from the expected answer's "Fotosynthese"; nothing else overlaps.
        $this->assertNull($this->classify('Hier passiert alles fotosynthetisch, das erklärt die grünen Blätter.'));

        // Positivkontrolle: an answer without a shared stem stays "off topic".
        $this->assertSame(MistakeType::OffTopic, $this->classify('Der Ausflug der Klasse ging in den Zoo zu den Elefanten.')?->type);
    }

    public function test_a_matching_answer_stays_unclassified(): void
    {
        $this->assertNull($this->classify('Pflanzen brauchen Licht und Wasser sowie Kohlendioxid für die Fotosynthese.'));

        // Positivkontrolle: the same question with an unrelated answer does get a label.
        $this->assertInstanceOf(MistakeClassification::class, $this->classify('Der Ausflug der Klasse ging in den Zoo zu den Elefanten.'));
    }

    private function classify(string $answer, ?string $expected = null, string $prompt = self::PROMPT): ?MistakeClassification
    {
        $question = Question::factory()->create([
            'prompt' => $prompt,
            'expected_answer' => $expected ?? self::EXPECTED,
        ]);

        return app(DemoMistakeClassifier::class)->classify(
            $question,
            Answer::factory()->for($question)->create(['answer' => $answer]),
        );
    }
}
