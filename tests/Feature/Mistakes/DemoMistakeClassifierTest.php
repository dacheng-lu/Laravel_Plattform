<?php

namespace Tests\Feature\Mistakes;

use App\Ai\DemoMistakeClassifier;
use App\Ai\MistakeClassification;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;
use App\QuestionType;
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

    public function test_an_answer_of_function_words_only_is_not_off_topic(): void
    {
        $prompt = 'Was braucht eine Pflanze zur Fotosynthese?';
        $expected = 'Licht und Wasser.';

        // "eine" stands in the question as well, so without the stop words this would look like a shared word.
        $this->assertSame(MistakeType::OffTopic, $this->classify('Das ist eine Frage über Autos', $expected, $prompt)?->type);

        // Nothing but function words says nothing about the topic: no label instead of a wrong one.
        $this->assertNull($this->classify('ja, das ist so', $expected, $prompt));

        // Positivkontrolle: an answer with content of the question stays without that label.
        $this->assertNull($this->classify('Pflanzen brauchen Licht und Wasser.', $expected, $prompt));
    }

    public function test_a_matching_answer_stays_unclassified(): void
    {
        $this->assertNull($this->classify('Pflanzen brauchen Licht und Wasser sowie Kohlendioxid für die Fotosynthese.'));

        // Positivkontrolle: the same question with an unrelated answer does get a label.
        $this->assertInstanceOf(MistakeClassification::class, $this->classify('Der Ausflug der Klasse ging in den Zoo zu den Elefanten.'));
    }

    public function test_a_reflection_question_never_gets_the_off_topic_label(): void
    {
        $unrelated = 'Der Ausflug der Klasse ging in den Zoo zu den Elefanten.';

        // Eine Reflexionsfrage hat keine einzige richtige Antwort: die Regeln schweigen hier bewusst, statt eine offene
        // Antwort als „am Thema vorbei" zu brandmarken (Spec-Leitplanke: kein Rateschluss).
        $this->assertNull($this->classify($unrelated, null, self::PROMPT, QuestionType::Reflection));

        // Positivkontrolle in derselben Methode: dieselbe Antwort auf eine Verständnisfrage wird eingeordnet.
        $classified = $this->classify($unrelated, null, self::PROMPT, QuestionType::Comprehension);

        $this->assertNotNull($classified);
        $this->assertSame(MistakeType::OffTopic, $classified->type);
        $this->assertSame(65, $classified->confidence);
        $this->assertSame(MistakeSource::Demo, $classified->source);
    }

    public function test_a_short_answer_on_a_reflection_question_stays_unlabelled(): void
    {
        // Three words against a fourteen-word expectation: the length rule would call this "incomplete".
        $short = 'Zoo, Elefanten, Ausflug.';

        // On a reflection question the rules stay silent altogether – an open answer is not "incomplete" either.
        $this->assertNull($this->classify($short, null, self::PROMPT, QuestionType::Reflection));

        // Positivkontrolle in derselben Methode: dieselbe kurze Antwort auf eine Verständnisfrage ist „unvollständig".
        $classified = $this->classify($short, null, self::PROMPT, QuestionType::Comprehension);

        $this->assertNotNull($classified);
        $this->assertSame(MistakeType::Incomplete, $classified->type);
        $this->assertSame(70, $classified->confidence);
    }

    private function classify(string $answer, ?string $expected = null, string $prompt = self::PROMPT, QuestionType $type = QuestionType::Comprehension): ?MistakeClassification
    {
        $question = Question::factory()->create([
            'type' => $type,
            'prompt' => $prompt,
            'expected_answer' => $expected ?? self::EXPECTED,
        ]);

        return app(DemoMistakeClassifier::class)->classify(
            $question,
            Answer::factory()->for($question)->create(['answer' => $answer]),
        );
    }
}
