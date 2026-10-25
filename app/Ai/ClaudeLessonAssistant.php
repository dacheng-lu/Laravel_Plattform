<?php

namespace App\Ai;

use App\Filament\Pages\ExplainIt;
use App\MessageAuthor;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Competency;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\QuestionType;
use App\Support\Pseudonymizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * LessonAssistant backed by Claude through the Messages API (plain HTTP, no SDK dependency).
 *
 * Only bound when `services.anthropic.enabled` is true. Privacy: requests carry lesson and student texts only,
 * never names, usernames or user ids of students.
 */
class ClaudeLessonAssistant implements LessonAssistant
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2026-10-21';

    /**
     * Enables `fallbacks: "default"`: a declined request is retried server-side on the recommended fallback model.
     */
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const array IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const string ROLE_PROMPT = 'Du bist ein Assistent für Lehrkräfte an deutschen Schulen. Du arbeitest ausschließlich mit den Unterrichtsinhalten, die dir gegeben werden, und schreibst auf Deutsch. Schülerinnen und Schüler sprichst du mit „du“ an.';

    public function draftHomework(Lesson $lesson): array
    {
        $competencies = Competency::query()->forSubject((string) $lesson->subject)->orderBy('id')->get();
        $competencyList = $competencies->isEmpty()
            ? 'Keine hinterlegt – setze competency_id überall auf null.'
            : $competencies->map(fn (Competency $competency): string => "- ID {$competency->id}: {$competency->area} – {$competency->name}")->implode("\n");
        // The teacher's notes may name students, so the whole lesson context is pseudonymised before it leaves the platform.
        $lessonContext = $this->pseudonymizerFor($lesson)->apply($this->lessonContext($lesson));

        $result = $this->ask(
            system: self::ROLE_PROMPT.' Aus den Notizen und Arbeitsblättern einer gehaltenen Stunde schreibst du eine verständliche Zusammenfassung für die Klasse (3 bis 5 Sätze) und entwirfst Hausaufgaben: zwei Verständnisfragen (comprehension), eine Anwendungsfrage (application), die das Gelernte auf eine neue Situation überträgt, und eine Reflexionsfrage (reflection). Die Reflexionsfrage muss sich auf einen konkreten Moment der Stunde aus den Notizen beziehen (zum Beispiel einen Versuch, eine Diskussion oder ein Beispiel an der Tafel) und das Datum der Stunde nennen, damit eine KI ohne Unterrichtsbesuch sie nicht beantworten kann. Ordne jede Frage der passendsten Kompetenz aus der gegebenen Liste zu (competency_id) oder setze null, wenn keine passt. Der Erwartungshorizont (expected_answer) ist nur für die Lehrkraft bestimmt und beschreibt, was eine gute Antwort enthält.',
            content: [
                ...$this->worksheetBlocks($lesson),
                ['type' => 'text', 'text' => $lessonContext."\n\nKompetenzen aus dem Referenzrahmen:\n".$competencyList],
            ],
            schema: $this->objectSchema([
                'summary' => ['type' => 'string'],
                'questions' => ['type' => 'array', 'items' => $this->objectSchema([
                    'type' => ['type' => 'string', 'enum' => array_column(QuestionType::cases(), 'value')],
                    'prompt' => ['type' => 'string'],
                    'expected_answer' => ['type' => 'string'],
                    'competency_id' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                ])],
            ]),
        );

        return [
            'summary' => (string) $result['summary'],
            'questions' => array_map(fn (array $question): array => [
                'type' => QuestionType::from($question['type']),
                'prompt' => (string) $question['prompt'],
                'expected_answer' => (string) $question['expected_answer'],
                'competency_id' => in_array($question['competency_id'], $competencies->modelKeys(), true) ? $question['competency_id'] : null,
            ], $result['questions']),
        ];
    }

    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null): array
    {
        $gapList = $gaps === [] ? 'Keine konkreten Hinweise vorhanden.' : collect($gaps)->map(fn (string $gap): string => "- {$gap}")->implode("\n");
        // Notes and topics may name students of the lesson's class, so each lesson block is pseudonymised on its own:
        // that keeps the labels ("Schüler:in A") of its own class instead of mixing several classes into one list.
        $lessons = $sourceLessons->map(fn (Lesson $lesson): string => $this->pseudonymizerFor($lesson)->apply(implode("\n", array_filter([
            "Thema: {$lesson->topic}",
            'Datum der Stunde: '.($lesson->held_on?->format('d.m.Y') ?? 'unbekannt'),
            // The caller withholds the notes of lessons the teacher may not view.
            blank($lesson->notes) ? null : "Notizen der Lehrkraft:\n{$lesson->notes}",
        ]))))->implode("\n\n---\n\n");

        $result = $this->ask(
            system: self::ROLE_PROMPT.' Für Schülerinnen und Schüler, bei denen sich in der Hausaufgabe eine Lücke gezeigt hat, entwirfst du eine Förderaufgabe zu genau einer Kompetenz: eine kurze Zusammenfassung (2 bis 4 Sätze), die ermutigend sagt, was geübt wird, und drei bis vier Übungsfragen, die von leicht zu schwer aufgebaut sind. Die erste Frage ist eine Verständnisfrage (comprehension), genau eine Frage ist eine Anwendungsfrage (application), die das Gelernte auf eine neue Situation überträgt. Reflexionsfragen gibt es nicht. Alle Fragen üben die genannte Kompetenz und knüpfen an die Inhalte der genannten Stunden an. Greife die Hinweise auf Lücken auf, ohne sie wörtlich zu zitieren, und formuliere ermutigend und konkret. Der Erwartungshorizont (expected_answer) ist nur für die Lehrkraft bestimmt und beschreibt, was eine gute Antwort enthält.',
            content: [[
                'type' => 'text',
                'text' => implode("\n\n", array_filter([
                    "Fach: {$subject}",
                    "Kompetenz: {$competency->area} – {$competency->name}\nBeschreibung: ".($competency->description ?: '(keine)'),
                    "Hinweise der KI auf Lücken (anonym):\n".$gapList,
                    "Stunden, in denen sich die Lücke gezeigt hat:\n\n".($lessons ?: '(keine)'),
                    $this->mistakeFocus($mistakeType),
                ])),
            ]],
            schema: $this->objectSchema([
                'summary' => ['type' => 'string'],
                'questions' => ['type' => 'array', 'items' => $this->objectSchema([
                    'type' => ['type' => 'string', 'enum' => array_column(QuestionType::cases(), 'value')],
                    'prompt' => ['type' => 'string'],
                    'expected_answer' => ['type' => 'string'],
                ])],
            ]),
        );

        $questions = array_slice($result['questions'], 0, 4);

        if ($questions === []) {
            throw new RuntimeException('Die KI hat keine Übungsfragen geliefert. Bitte versuche es erneut.');
        }

        return [
            'summary' => (string) $result['summary'],
            'questions' => array_map(fn (array $question): array => [
                'type' => QuestionType::from($question['type']),
                'prompt' => (string) $question['prompt'],
                'expected_answer' => (string) $question['expected_answer'],
                'competency_id' => $competency->getKey(),
            ], $questions),
        ];
    }

    public function evaluateSubmission(Submission $submission): array
    {
        $lesson = $submission->lesson;
        $answers = $submission->answers()->get()->keyBy('question_id');
        $pseudonymizer = $this->pseudonymizerFor($lesson, $submission->student?->name);
        $threshold = (int) config('lernplattform.mistakes.threshold', 80);
        // With the mistake types switched off the request carries neither the schema fields nor a word about them: an
        // answer then simply stays unclassified, whatever the AI would have said.
        $mistakesEnabled = (bool) config('lernplattform.mistakes.enabled');

        $questions = $lesson->questions->map(fn (Question $question): string => implode("\n", [
            "Frage-ID {$question->id} ({$question->type->getLabel()}): ".$pseudonymizer->apply((string) $question->prompt),
            'Erwartungshorizont: '.$pseudonymizer->apply((string) $question->expected_answer),
            'Antwort: '.$this->ownAnswer($answers->get($question->id)?->answer, $pseudonymizer),
        ]))->implode("\n\n");

        $answerProperties = [
            'question_id' => ['type' => 'integer'],
            'score' => ['type' => 'integer'],
            'feedback' => ['type' => 'string'],
        ];

        if ($mistakesEnabled) {
            $answerProperties['mistake_type'] = ['anyOf' => [['type' => 'string', 'enum' => array_column(MistakeType::cases(), 'value')], ['type' => 'null']]];
            $answerProperties['mistake_confidence'] = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
        }

        $result = $this->ask(
            system: self::ROLE_PROMPT.' Du prüfst Hausaufgaben darauf, ob die Schülerin oder der Schüler die Stunde verstanden hat. Vergleiche jede Antwort mit dem Erwartungshorizont. Reflexionsfragen haben keine einzig richtige Antwort: Bewerte dort eigene Gedanken und Begründungen. Alle Punktzahlen liegen zwischen 0 und 100. Das Feedback ist ermutigend, konkret und richtet sich direkt an die Schülerin oder den Schüler. Nenne in gaps höchstens drei kurze Hinweise, was noch einmal wiederholt werden sollte. Zitiere den Erwartungshorizont nicht wörtlich.'
                .($mistakesEnabled
                    ? ' Nur wenn die Punktzahl einer Antwort unter '.$threshold.' liegt, ordnest du zusätzlich ihre Fehlerart ein (mistake_type) und nennst deine Sicherheit von 0 bis 100 (mistake_confidence); ab dieser Punktzahl setzt du beides auf null. Ordne die Antwort ein, nie die Person; keine Aussagen über Motivation oder Gefühle.'
                    : ''),
            content: [
                // Issue #3, decision A: an evaluation carries the question, its expected answer and the student's own
                // answer – nothing else. No lesson context (Fach/Thema/Datum), no summary of the teacher, no notes of
                // the student, no worksheet files. The names inside these three texts are still replaced before they
                // leave the platform.
                ['type' => 'text', 'text' => "Fragen und Antworten:\n".$questions],
            ],
            schema: $this->objectSchema([
                'understanding_score' => ['type' => 'integer'],
                'feedback' => ['type' => 'string'],
                'gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'answers' => ['type' => 'array', 'items' => $this->objectSchema($answerProperties)],
            ]),
        );

        $evaluatedAnswers = [];

        foreach ($result['answers'] as $answer) {
            if (! $answers->has($answer['question_id'])) {
                continue;
            }

            $evaluatedAnswers[$answer['question_id']] = $this->evaluatedAnswer($answer, $mistakesEnabled, $threshold);
        }

        return [
            'understanding_score' => $this->clamp($result['understanding_score']),
            'feedback' => (string) $result['feedback'],
            'gaps' => array_values(array_slice($result['gaps'], 0, 3)),
            'answers' => $evaluatedAnswers,
        ];
    }

    /**
     * One answer of the evaluation result: score and feedback always, the mistake type only while the feature is on
     * and it is a plausible classification below the configured threshold. Otherwise the keys stay away, so the caller
     * cannot mistake a missing value for a type.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function evaluatedAnswer(array $answer, bool $mistakesEnabled, int $threshold): array
    {
        $evaluated = [
            'score' => $this->clamp($answer['score']),
            'feedback' => (string) $answer['feedback'],
        ];

        if (! $mistakesEnabled) {
            return $evaluated;
        }

        // The threshold holds here as well: an answer that is good enough never carries a mistake type, whatever the
        // AI returns. Only the answer is classified, never the person.
        if ($evaluated['score'] >= $threshold) {
            return $evaluated;
        }

        $classification = MistakeClassification::fromEvaluation($answer, MistakeSource::Claude);

        if ($classification === null) {
            return $evaluated;
        }

        return [...$evaluated, 'mistake_type' => $classification->type->value, 'mistake_confidence' => $classification->confidence];
    }

    /**
     * Names of the lesson's class and of its teacher, plus one more name (the submitting student) when the platform
     * knows them, so that no name leaves the platform.
     */
    private function pseudonymizerFor(Lesson $lesson, ?string $extraName = null): Pseudonymizer
    {
        $names = $lesson->schoolClass?->students()->orderBy('id')->pluck('name')->all() ?? [];

        // A student who is already on the class list keeps their label; listing the name twice would make it "Person".
        if ($extraName !== null && ! in_array($extraName, $names, true)) {
            $names[] = $extraName;
        }

        return Pseudonymizer::forPeople($names, $lesson->teacher?->name);
    }

    /**
     * The student's answer as it may go to the AI: without names, and labelled when there is none.
     */
    private function ownAnswer(?string $answer, Pseudonymizer $pseudonymizer): string
    {
        $answer = (string) $answer;

        return trim($answer) === '' ? '(keine Antwort)' : $pseudonymizer->apply($answer);
    }

    /**
     * The line that points the practice task at the group's most common mistake type; nothing without a type.
     */
    private function mistakeFocus(?MistakeType $mistakeType): ?string
    {
        if ($mistakeType === null) {
            return null;
        }

        $instruction = match ($mistakeType) {
            MistakeType::Concept => 'Beginne mit einer kurzen Erklärung der Grundidee und stelle leichte Einstiegsaufgaben.',
            MistakeType::Procedure => 'Zeige ein durchgerechnetes Beispiel mit Teilschritten.',
            MistakeType::Incomplete => 'Gib Aufgaben mit einem Gerüst, das die fehlenden Teile vorgibt.',
            MistakeType::OffTopic, MistakeType::Empty => 'Bleibe bei der Kompetenz und formuliere die Aufgaben neutral.',
        };

        return "Häufigste Fehlerart der Gruppe: {$mistakeType->getLabel()}. {$instruction}";
    }

    public function askFollowUp(ExplanationSession $session): string
    {
        $result = $this->ask(
            system: $this->tutorPersona().' Stelle genau eine kurze, freundliche Rückfrage in der Ich-Form, höchstens zwei Sätze. Frage nach Lücken in der Erklärung, nach dem Warum, nach einem Beispiel oder nach einer Übertragung auf eine neue Situation. Erkläre nichts selbst, verrate keine Antworten und wiederhole keine Frage, die du schon gestellt hast.',
            content: [['type' => 'text', 'text' => $this->explanationContext($session)."\n\nStelle jetzt deine nächste Rückfrage."]],
            schema: $this->objectSchema(['question' => ['type' => 'string']]),
        );

        return trim((string) $result['question']);
    }

    public function assessExplanation(ExplanationSession $session): array
    {
        $result = $this->ask(
            system: $this->tutorPersona().' Das Gespräch ist jetzt beendet. Beurteile, wie gut die Schülerin oder der Schüler das Thema verstanden hat: fachliche Richtigkeit, Vollständigkeit gemessen an den Inhalten der Stunde, Begründungen und Beispiele. Gib einen Verständniswert von 0 bis 100 und ein kurzes, ermutigendes Feedback in deiner Ich-Form an die Schülerin oder den Schüler (Du-Form), das nennt, was gut erklärt war und was noch gefehlt hat.',
            content: [['type' => 'text', 'text' => $this->explanationContext($session)]],
            schema: $this->objectSchema([
                'understanding_score' => ['type' => 'integer'],
                'feedback' => ['type' => 'string'],
            ]),
        );

        return [
            'understanding_score' => $this->clamp($result['understanding_score']),
            'feedback' => (string) $result['feedback'],
        ];
    }

    /**
     * Sends one request with a JSON schema for the answer and returns the decoded JSON.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function ask(string $system, array $content, array $schema): array
    {
        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            throw new RuntimeException('Die KI-Anbindung ist aktiviert, aber es ist kein API-Schlüssel hinterlegt (ANTHROPIC_API_KEY).');
        }

        $message = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta' => self::FALLBACK_BETA,
        ])->acceptJson()->timeout(120)->post(self::ENDPOINT, [
            'model' => config('services.anthropic.model') ?: 'claude-opus-5-5',
            'max_tokens' => 16000,
            'fallbacks' => 'default',
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            'output_config' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ])->throw()->json();

        if (($message['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Die KI hat diese Anfrage abgelehnt. Bitte prüfe die Inhalte und versuche es erneut.');
        }

        if (($message['stop_reason'] ?? null) === 'max_tokens') {
            throw new RuntimeException('Die Antwort der KI war zu lang und wurde abgeschnitten. Bitte versuche es erneut.');
        }

        $text = collect($message['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $result = json_decode($text, true);

        if (! is_array($result)) {
            throw new RuntimeException('Die Antwort der KI konnte nicht gelesen werden. Bitte versuche es erneut.');
        }

        return $result;
    }

    /**
     * Strict object schema: structured outputs need every property required and no additional ones.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * The "Erklär's mir" tutor the ExplainIt page introduces to the student.
     */
    private function tutorPersona(): string
    {
        return 'Du bist '.ExplainIt::TUTOR_NAME.', ein neugieriger Lernpartner, der von dem Thema noch nie gehört hat. Eine Schülerin oder ein Schüler erklärt es dir („Lernen durch Lehren“). Du schreibst auf Deutsch, locker und kurz, und sprichst sie oder ihn mit „du“ an.';
    }

    private function lessonContext(Lesson $lesson): string
    {
        return implode("\n", array_filter([
            "Fach: {$lesson->subject}",
            "Thema: {$lesson->topic}",
            'Datum der Stunde: '.($lesson->held_on?->format('d.m.Y') ?? 'unbekannt'),
            $lesson->summary ? "Zusammenfassung der Lehrkraft:\n{$lesson->summary}" : null,
            "Notizen der Lehrkraft (Tafelbild, Verlauf, Transkript):\n{$lesson->notes}",
        ]));
    }

    /**
     * The conversation so far, labelled by role only, plus the lesson as background for the tutor.
     */
    private function explanationContext(ExplanationSession $session): string
    {
        $transcript = $session->messages()->get()
            ->map(fn (ExplanationMessage $message): string => ($message->author === MessageAuthor::Student ? 'Schüler:in' : ExplainIt::TUTOR_NAME.' (du)').': '.$message->content)
            ->implode("\n\n");

        return implode("\n\n", array_filter([
            "Thema der Erklärung: {$session->topic}",
            $session->lesson ? "Inhalte der zugehörigen Stunde (Hintergrund, nicht verraten):\n".$this->lessonContext($session->lesson) : null,
            "Bisheriges Gespräch:\n".($transcript ?: '(noch keine Nachrichten)'),
        ]));
    }

    /**
     * Photos and PDFs of the lesson's worksheets as content blocks; other file types are skipped.
     *
     * @return list<array<string, mixed>>
     */
    private function worksheetBlocks(Lesson $lesson): array
    {
        $disk = Storage::disk('local');
        $blocks = [];

        foreach ($lesson->attachments ?? [] as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            $mediaType = $disk->mimeType($path);
            $blockType = match (true) {
                in_array($mediaType, self::IMAGE_TYPES, true) => 'image',
                $mediaType === 'application/pdf' => 'document',
                default => null,
            };

            if ($blockType !== null) {
                $blocks[] = [
                    'type' => $blockType,
                    'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => base64_encode((string) $disk->get($path))],
                ];
            }
        }

        return $blocks;
    }

    private function clamp(mixed $score): int
    {
        return max(0, min(100, (int) $score));
    }
}
