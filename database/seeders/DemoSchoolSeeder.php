<?php

namespace Database\Seeders;

use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Role;
use App\Support\DemoMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Two demo schools. "Gesamtschule am Lernpark" has a school administration (schulleitung), two teachers, classes 7a and 7b
 * and lessons in every state (draft, homework with results, open homework, homework for selected students, an open
 * Förderaufgabe for the competency Mia and Ben need support in) plus "Erklär's mir" sessions for Mia Hoffmann (login schueler). "Gymnasium Musterstadt" is a small second school
 * (gym-admin, one teacher, one class, one lesson) that shows what the schools cannot see of each other.
 * The platform operator (hackathon) belongs to no school and is created by DatabaseSeeder.
 *
 * Accounts are updated on every run, which also moves demo accounts and classes of databases from before the schools
 * existed into the demo school. Lessons and sessions are only created when missing, so changes made while trying out
 * the platform survive re-seeding. Every demo account gets the password from DEMO_PASSWORD on every run, so changing
 * the variable and seeding again rotates all of them. Outside demo mode (see App\Support\DemoMode) the seeder does
 * nothing at all.
 *
 * USERNAMES names every account created here and in DatabaseSeeder, so instances without demo mode can still find the
 * accounts that were public in the repository (see `php artisan lernplattform:lock-demo-accounts`).
 */
class DemoSchoolSeeder extends Seeder
{
    /**
     * Every username this seeder (and DatabaseSeeder for the operator) creates.
     *
     * @var list<string>
     */
    public const array USERNAMES = [
        'hackathon',
        'schulleitung',
        'lehrer',
        'weber',
        'schueler',
        'ben.krueger',
        'lena.schulz',
        'jonas.becker',
        'elif.yilmaz',
        'noah.wagner',
        'emma.fischer',
        'luca.meyer',
        'paul.richter',
        'hannah.koch',
        'leon.wolf',
        'sophie.neumann',
        'gym-admin',
        'gym-lehrer',
        'gym-schueler1',
        'gym-schueler2',
    ];

    public function run(): void
    {
        if (! DemoMode::isActive()) {
            $this->command?->warn('Demo schools skipped: demo mode is off (see config/lernplattform.php, demo).');

            return;
        }

        DB::transaction(function (): void {
            $school = $this->school('4711', 'Gesamtschule am Lernpark', 'Gesamtschule', 'Fulda', 'Hessen', 'sekretariat@lernpark.example');

            $this->user('schulleitung', 'Petra Krause', Role::Admin, $school);
            $anna = $this->user('lehrer', 'Anna Schneider', Role::Teacher, $school);
            $weber = $this->user('weber', 'Thomas Weber', Role::Teacher, $school);

            $class7b = $this->schoolClass($school, '7b', $anna);
            $class7a = $this->schoolClass($school, '7a', $weber);

            $students7b = collect([
                'schueler' => 'Mia Hoffmann',
                'ben.krueger' => 'Ben Krüger',
                'lena.schulz' => 'Lena Schulz',
                'jonas.becker' => 'Jonas Becker',
                'elif.yilmaz' => 'Elif Yılmaz',
                'noah.wagner' => 'Noah Wagner',
                'emma.fischer' => 'Emma Fischer',
                'luca.meyer' => 'Luca Meyer',
            ])->map(fn (string $name, string $username): User => $this->user($username, $name, Role::Student, $school, $class7b));

            foreach (['paul.richter' => 'Paul Richter', 'hannah.koch' => 'Hannah Koch', 'leon.wolf' => 'Leon Wolf', 'sophie.neumann' => 'Sophie Neumann'] as $username => $name) {
                $this->user($username, $name, Role::Student, $school, $class7a);
            }

            $this->seedRemedialMathematics($class7b, $anna, $students7b->only(['schueler', 'ben.krueger', 'noah.wagner']));
            $this->seedPracticeTask($class7b, $anna, $students7b->only(['schueler', 'ben.krueger']));
            $photosynthesis = $this->seedPhotosynthesis($class7b, $anna, $students7b);
            $pythagoras = $this->seedPythagoras($class7b, $anna, $students7b);
            $this->seedFrenchRevolution($class7b, $anna);
            $this->seedExplanationSessions($students7b['schueler'], $photosynthesis, $pythagoras);

            $this->seedGymnasium();
        });
    }

    /**
     * The school number is the natural key; it is unique in the schools table.
     */
    private function school(string $number, string $name, string $type, string $city, string $state, string $contactEmail): School
    {
        return School::updateOrCreate(
            ['school_number' => $number],
            ['name' => $name, 'school_type' => $type, 'city' => $city, 'state' => $state, 'contact_email' => $contactEmail],
        );
    }

    /**
     * A class of the school. A class without a school (from before the schools existed) is adopted by it.
     * The join code is set here because seeding runs without model events, which would otherwise generate it.
     */
    private function schoolClass(School $school, string $name, User $teacher): SchoolClass
    {
        $schoolClass = SchoolClass::query()
            ->where('name', $name)
            ->where(fn (Builder $query) => $query->whereNull('school_id')->orWhere('school_id', $school->id))
            ->firstOrNew(['name' => $name]);

        $schoolClass->forceFill([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'join_code' => $schoolClass->join_code ?? SchoolClass::generateJoinCode(),
        ])->save();

        return $schoolClass;
    }

    /**
     * A second, smaller school whose people and lesson none of the first school's accounts see.
     */
    private function seedGymnasium(): void
    {
        $school = $this->school('4712', 'Gymnasium Musterstadt', 'Gymnasium', 'Musterstadt', 'Niedersachsen', 'sekretariat@gymnasium-musterstadt.example');

        $this->user('gym-admin', 'Stefan Brandt', Role::Admin, $school);
        $teacher = $this->user('gym-lehrer', 'Julia Berger', Role::Teacher, $school);
        $schoolClass = $this->schoolClass($school, '8c', $teacher);

        $students = [
            $this->user('gym-schueler1', 'Finn Albrecht', Role::Student, $school, $schoolClass),
            $this->user('gym-schueler2', 'Clara Vogt', Role::Student, $school, $schoolClass),
        ];

        $this->lesson($schoolClass, $teacher, [
            'subject' => 'Mathematik',
            'topic' => 'Lineare Funktionen',
            'held_on' => $this->daysAgo(2),
            'published_at' => $this->daysAgo(2, '15:00'),
            'due_on' => $this->daysAgo(-3),
            'notes' => 'Mathe 8c – Lineare Funktionen. Steigung als Änderungsrate (Beispiel Handytarif), Funktionsgleichung y = m · x + b, Steigungsdreieck am Graphen ablesen. Übung: Wertetabelle zu Gleichung und Graph zeichnen.',
            'summary' => 'Eine lineare Funktion hat die Gleichung y = m · x + b. Die Steigung m sagt, wie stark sich y ändert, wenn x um 1 größer wird. Der y-Achsenabschnitt b ist der Wert bei x = 0. Am Graphen liest man die Steigung mit einem Steigungsdreieck ab.',
        ], $students, [
            [QuestionType::Application, 'Mathematik', 'Mathematische Darstellungen verwenden',
                'Zeichne den Graphen der Funktion y = 2 · x − 1 für x von −2 bis 3. Lege zuerst eine Wertetabelle an.',
                'Wertetabelle: x = −2, −1, 0, 1, 2, 3 ergibt y = −5, −3, −1, 1, 3, 5. Die Punkte liegen auf einer Geraden, die die y-Achse bei −1 schneidet und pro Schritt nach rechts um 2 steigt.'],
            [QuestionType::Comprehension, 'Mathematik', 'Mathematisch argumentieren',
                'Erkläre in eigenen Worten, was die Steigung m einer linearen Funktion bedeutet.',
                'Die Steigung gibt an, um wie viel sich y ändert, wenn x um 1 wächst. Bei positivem m steigt der Graph, bei negativem m fällt er.'],
        ]);
    }

    private function user(string $username, string $name, Role $role, School $school, ?SchoolClass $schoolClass = null): User
    {
        return User::updateOrCreate(
            ['username' => $username],
            ['name' => $name, 'password' => DemoMode::password(), 'role' => $role, 'school_id' => $school->id, 'school_class_id' => $schoolClass?->id],
        );
    }

    private function daysAgo(int $days, string $time = '00:00'): Carbon
    {
        return today()->subDays($days)->setTimeFromTimeString($time);
    }

    /**
     * Creates the lesson with questions, delegation and submissions, but only on the first run.
     *
     * @param  array<string, mixed>  $attributes
     * @param  iterable<User>  $students  students the homework is delegated to
     * @param  list<array{0: QuestionType, 1: string|null, 2: string, 3: string, 4: string}>  $questions  type, competency subject, competency name, prompt, expected answer
     * @param  array<string, array{at: Carbon, summary: string, score: int, feedback: string, gaps: list<string>, answers: list<array{0: string, 1: int, 2: string}>}>  $submissions  keyed by username; answers in question order as answer, score, feedback
     */
    private function lesson(SchoolClass $schoolClass, User $teacher, array $attributes, iterable $students = [], array $questions = [], array $submissions = []): Lesson
    {
        $lesson = Lesson::firstOrCreate(
            ['school_class_id' => $schoolClass->id, 'subject' => $attributes['subject'], 'topic' => $attributes['topic']],
            [...$attributes, 'teacher_id' => $teacher->id],
        );

        if (! $lesson->wasRecentlyCreated) {
            return $lesson;
        }

        $lesson->students()->attach(collect($students)->pluck('id'));

        $questionIds = [];

        foreach ($questions as $position => [$type, $subject, $competency, $prompt, $expectedAnswer]) {
            $questionIds[] = $lesson->questions()->create([
                'type' => $type,
                'competency_id' => Competency::query()->where('subject', $subject)->where('name', $competency)->value('id'),
                'prompt' => $prompt,
                'expected_answer' => $expectedAnswer,
                'position' => $position + 1,
            ])->id;
        }

        foreach ($submissions as $username => $submission) {
            $model = Submission::forceCreate([
                'lesson_id' => $lesson->id,
                'student_id' => User::query()->where('username', $username)->value('id'),
                'summary' => $submission['summary'],
                'understanding_score' => $submission['score'],
                'feedback' => $submission['feedback'],
                'gaps' => $submission['gaps'],
                'evaluated_at' => $submission['at']->copy()->addMinutes(2),
                'created_at' => $submission['at'],
                'updated_at' => $submission['at'],
            ]);

            foreach ($submission['answers'] as $index => [$answer, $score, $feedback]) {
                $model->answers()->create(['question_id' => $questionIds[$index], 'answer' => $answer, 'score' => $score, 'feedback' => $feedback]);
            }
        }

        return $lesson;
    }

    /**
     * @param  iterable<User>  $students
     */
    private function seedRemedialMathematics(SchoolClass $schoolClass, User $teacher, iterable $students): void
    {
        $this->lesson($schoolClass, $teacher, [
            'subject' => 'Mathematik',
            'topic' => 'Flächeninhalt von Dreiecken (Förderaufgabe)',
            'held_on' => $this->daysAgo(12),
            'published_at' => $this->daysAgo(12, '14:10'),
            'due_on' => $this->daysAgo(7),
            'notes' => <<<'NOTES'
                Mathe 7b – Förderband Flächeninhalt Dreieck (Kleingruppe: Mia, Ben, Noah), 45 min

                Anlass: In der Lernstandsüberprüfung Flächen (Rechteck/Dreieck) Fehler bei der Zuordnung Grundseite – Höhe, Formel oft A = g · h ohne „: 2“. Muss vor Pythagoras sitzen.

                Ablauf:
                - Rechteck auf Karopapier, Diagonale einzeichnen, auseinanderschneiden → zwei gleiche Dreiecke → A = g · h : 2. Mia: „Ach so, deshalb durch zwei!“
                - Höhe einzeichnen: Geodreieck senkrecht auf die Grundseite. Beim stumpfwinkligen Dreieck liegt die Höhe außerhalb – Ben noch unsicher.
                - Förder-AB 3, Nr. 1–6: erst Kästchen zählen, dann rechnen.
                - Sachaufgabe Beet: alle drei scheitern daran, die richtigen Maße aus dem Text zu holen und die Einheiten (cm/m) anzugleichen.

                Beobachtung: Mit der Formel rechnen klappt nach dem Zerschneiden gut, Textaufgaben bleiben schwierig (Informationen herausfiltern, Skizze). Strategie „Skizze zuerst, dann Einheiten prüfen“ eingeübt.
                Noah war die zweite Hälfte beim Schulsozialarbeiter.
                NOTES,
            'summary' => 'In der Förderstunde wurde die Flächenformel für Dreiecke hergeleitet: Ein Dreieck ist ein halbes Rechteck, deshalb gilt A = g · h : 2. Die Höhe steht immer senkrecht auf der Grundseite und kann bei stumpfwinkligen Dreiecken außerhalb liegen. Für Sachaufgaben wurde die Strategie „erst Skizze, dann Einheiten angleichen, dann rechnen“ geübt.',
        ], $students, [
            [QuestionType::Application, 'Mathematik', 'Mit symbolischen, formalen und technischen Elementen umgehen',
                'Berechne den Flächeninhalt eines rechtwinkligen Dreiecks mit den Katheten 6 cm und 8 cm. Schreibe die Formel und den Rechenweg auf.',
                'A = g · h : 2 = 6 cm · 8 cm : 2 = 24 cm². Beim rechtwinkligen Dreieck können die beiden Katheten als Grundseite und Höhe genutzt werden.'],
            [QuestionType::Application, 'Mathematik', 'Probleme mathematisch lösen',
                'Familie Demir legt ein dreieckiges Beet an. Die Grundseite ist 4,50 m lang, die Höhe beträgt 240 cm. Eine Packung Blumensamen reicht für 2 m². Wie viele Packungen müssen sie kaufen? Fertige zuerst eine Skizze an.',
                'Einheiten angleichen: 240 cm = 2,40 m. A = 4,50 m · 2,40 m : 2 = 5,40 m². 5,40 m² : 2 m² = 2,7, also müssen 3 Packungen gekauft werden (aufrunden). Skizze mit Grundseite und senkrecht dazu eingezeichneter Höhe.'],
        ], [
            'schueler' => [
                'at' => $this->daysAgo(9, '18:20'),
                'summary' => 'Wir haben gelernt, dass ein Dreieck ein halbes Rechteck ist. Deshalb rechnet man g mal h durch 2. Die Höhe muss senkrecht auf der Grundseite stehen, beim stumpfen Dreieck ist sie außen.',
                'score' => 50,
                'feedback' => 'Den Kern der Formel hast du verstanden und gut begründet (halbes Rechteck). Bei Sachaufgaben fehlen noch die Zwischenschritte: erst Skizze, dann Einheiten prüfen, am Ende das Ergebnis auf Plausibilität kontrollieren.',
                'gaps' => ['Einheiten vor dem Rechnen angleichen (cm ↔ m)', 'Ergebnis auf Plausibilität prüfen', 'Skizze zu Textaufgaben anfertigen'],
                'answers' => [
                    ['A = g · h : 2 = 6 · 8 : 2 = 24. Der Flächeninhalt ist 24.', 62, 'Formel und Rechnung sind richtig. Die Einheit (cm²) fehlt, und es wird nicht erwähnt, warum die Katheten hier Grundseite und Höhe sind.'],
                    ['4,50 · 240 = 1080 : 2 = 540. 540 : 2 = 270. Sie brauchen 270 Packungen.', 38, 'Die Dreiecksformel wurde richtig gewählt, aber Meter und Zentimeter wurden gemischt; dadurch ist das Ergebnis viel zu groß. Sind 270 Packungen für ein Beet realistisch? Eine Skizze fehlt.'],
                ],
            ],
            'ben.krueger' => [
                'at' => $this->daysAgo(7, '21:05'),
                'summary' => 'Dreieck Fläche ausrechnen. Rechteck durchschneiden.',
                'score' => 40,
                'feedback' => 'Die Zusammenfassung ist sehr knapp. Die Formel A = g · h : 2 und die Begründung, warum durch 2 geteilt wird, fehlen.',
                'gaps' => ['Flächenformel des Dreiecks', 'Sachaufgaben schrittweise lösen'],
                'answers' => [
                    ['6 · 8 = 48 cm²', 50, 'Das ist der Flächeninhalt des passenden Rechtecks. Beim Dreieck muss noch durch 2 geteilt werden: 24 cm².'],
                    ['4,50 + 240 = 244,5. Sie brauchen 122 Packungen.', 30, 'Die Längen wurden addiert statt in die Flächenformel eingesetzt, und die Einheiten passen nicht zusammen. Schrittfolge üben: Skizze – Einheiten angleichen – Formel – Antwortsatz.'],
                ],
            ],
        ]);
    }

    /**
     * An open Förderaufgabe like the teacher creates it on "Lernstand & Förderbedarf": for "Probleme mathematisch lösen",
     * where Mia and Ben are below the support threshold after the remedial lesson (the other students have no
     * evaluated answer there). Nobody has handed it in yet, so Mia finds it on "Mein Lernstand" and her start page.
     *
     * @param  iterable<User>  $students
     */
    private function seedPracticeTask(SchoolClass $schoolClass, User $teacher, iterable $students): void
    {
        $this->lesson($schoolClass, $teacher, [
            'subject' => 'Mathematik',
            'topic' => 'Förderaufgabe: Probleme mathematisch lösen',
            'held_on' => $this->daysAgo(1),
            'published_at' => $this->daysAgo(1, '14:30'),
            'due_on' => today()->addDays(6),
            'practice_competency_id' => Competency::query()->where('subject', 'Mathematik')->where('name', 'Probleme mathematisch lösen')->value('id'),
            'notes' => <<<'NOTES'
                Förderaufgabe zur Kompetenz „Probleme mathematisch lösen“.

                Zu einer Problemsituation Fragen stellen, Lösungsstrategien (z. B. Skizze, Rückwärtsarbeiten, Zerlegen) auswählen und Ergebnisse überprüfen.

                Aus der Stunde „Flächeninhalt von Dreiecken (Förderaufgabe)“: Bei Sachaufgaben fehlen die Zwischenschritte. Eingeübt wurde „erst Skizze, dann Einheiten angleichen, dann rechnen“, am Ende wird das Ergebnis auf Plausibilität geprüft.
                NOTES,
            'summary' => 'Eine Sachaufgabe löst du in festen Schritten: Zuerst eine Skizze anfertigen und gegebene und gesuchte Größen herausschreiben. Dann die Einheiten angleichen (z. B. cm in m umrechnen), die passende Formel wählen und rechnen. Zum Schluss prüfst du, ob das Ergebnis zur Aufgabe passen kann, und schreibst einen Antwortsatz.',
        ], $students, [
            [QuestionType::Comprehension, 'Mathematik', 'Probleme mathematisch lösen',
                'Beschreibe in eigenen Worten, welche Schritte du bei einer Sachaufgabe nacheinander gehst, bevor und nachdem du rechnest.',
                'Skizze anfertigen und gegebene und gesuchte Größen aus dem Text herausschreiben; Einheiten angleichen; passende Formel wählen und rechnen; Ergebnis auf Plausibilität prüfen; Antwortsatz schreiben.'],
            [QuestionType::Application, 'Mathematik', 'Probleme mathematisch lösen',
                'Der rechteckige Schulgarten ist 8 m lang und 450 cm breit. Ein Sack Rindenmulch reicht für 3 m². Wie viele Säcke werden gebraucht, um den ganzen Garten abzudecken? Fertige zuerst eine Skizze an.',
                'Einheiten angleichen: 450 cm = 4,50 m. A = 8 m · 4,50 m = 36 m². 36 m² : 3 m² = 12, also werden 12 Säcke gebraucht. Skizze mit den beiden Seitenlängen.'],
            [QuestionType::Reflection, 'Mathematik', 'Probleme mathematisch lösen',
                'Bei welchem Schritt einer Sachaufgabe passieren dir die meisten Fehler, und woran merkst du, dass ein Ergebnis nicht stimmen kann? Nenne ein Beispiel.',
                'Eine ehrliche Selbsteinschätzung (z. B. Einheiten mischen, Maße aus dem Text herausfiltern) und ein Plausibilitätscheck am Beispiel, etwa: 270 Packungen Blumensamen für ein kleines Beet sind unrealistisch, also muss irgendwo ein Rechen- oder Einheitenfehler stecken.'],
        ]);
    }

    /**
     * @param  iterable<User>  $students
     */
    private function seedPhotosynthesis(SchoolClass $schoolClass, User $teacher, iterable $students): Lesson
    {
        return $this->lesson($schoolClass, $teacher, [
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => $this->daysAgo(9),
            'published_at' => $this->daysAgo(9, '15:30'),
            'due_on' => $this->daysAgo(4),
            'notes' => <<<'NOTES'
                Bio 7b – Fotosynthese (Doppelstunde)

                Einstieg (10 min): Kresse-Versuch von letzter Woche mitgebracht – Schale aus dem dunklen Schrank vs. Fensterbank. Kresse im Dunkeln gelb, lang, dünn. Frage: „Was fehlt der Pflanze?“ → Licht. Jonas: „Die hat doch Wasser bekommen, am Essen kann’s nicht liegen.“ → Überleitung: Woher bekommt eine Pflanze ihre Nährstoffe?

                Erarbeitung I (20 min): Versuch Wasserpest (Elodea) im Becherglas unter der Lampe, Abstand 10 / 30 / 50 cm, Gasbläschen pro Minute gezählt (3 Gruppen, Mittelwerte).
                Ergebnis an der Tafel: 10 cm: 38 | 30 cm: 17 | 50 cm: 6 → je mehr Licht, desto mehr Gas.
                Glimmspanprobe als Demo: Glimmspan leuchtet auf → Sauerstoff.
                Hinweis Temperatur: Lampe erwärmt das Wasser – nur kurz angesprochen, nächstes Mal Wasserbad als Wärmefilter.

                Tafelbild:
                Kohlenstoffdioxid + Wasser ——(Lichtenergie, Chlorophyll)——> Traubenzucker + Sauerstoff
                (6 CO₂ + 6 H₂O → C₆H₁₂O₆ + 6 O₂ nur als Ausblick, Wortgleichung reicht für die Arbeit)
                Ort: Chloroplasten in den Blattzellen (Mikroskopbild Moosblättchen aus Stunde 3 noch mal gezeigt)
                CO₂ über die Spaltöffnungen, Wasser über die Wurzeln.

                Erarbeitung II (25 min): AB „Die Pflanze als Zuckerfabrik“ (Lückentext + Pfeildiagramm beschriften). Fabrik-Analogie: Rohstoffe – Energie – Produkt – „Abfallprodukt“ Sauerstoff (für uns lebenswichtig).

                Schülerfrage Jonas: „Warum erstickt die Pflanze nachts nicht?“ → Zellatmung kurz angerissen: Pflanzen atmen Tag und Nacht, nachts keine Fotosynthese. Im Tafelbild mit Pfeilen Tag/Nacht ergänzt. Ausführlich nächste Woche.
                Elif stellt selbst den Bezug zur Nahrungskette her: „Dann kommt eigentlich all unser Essen vom Licht.“

                Sicherung (10 min): Wortgleichung ins Heft, 3 Kontrollfragen mündlich. Ben und Noah verwechseln noch Ausgangsstoffe und Produkte, Lena/Elif sehr sicher. Luca fehlte in der 2. Stunde (Arzttermin).

                HA: Zusammenfassung + Fragen auf der Plattform. Nächste Stunde: Zellatmung.
                NOTES,
            'summary' => 'In der Doppelstunde ging es um die Frage, wie Pflanzen ihre Nährstoffe selbst herstellen. Der Kresse-Versuch zeigte, dass Pflanzen ohne Licht nicht gesund wachsen; im Wasserpest-Versuch entstanden umso mehr Sauerstoffbläschen, je näher die Lampe stand (38, 17 und 6 Bläschen pro Minute bei 10, 30 und 50 cm). Festgehalten wurde die Wortgleichung: Kohlenstoffdioxid + Wasser → Traubenzucker + Sauerstoff, angetrieben von Lichtenergie und Chlorophyll in den Chloroplasten. Kohlenstoffdioxid gelangt über die Spaltöffnungen ins Blatt, Wasser über die Wurzeln. Auf eine Schülerfrage hin wurde angerissen, dass Pflanzen Tag und Nacht atmen (Zellatmung); das wird in der nächsten Stunde vertieft.',
        ], $students, [
            [QuestionType::Comprehension, 'Biologie', 'Sachkompetenz',
                'Erkläre mit eigenen Worten, was bei der Fotosynthese passiert. Nenne die Ausgangsstoffe, die Produkte und wo in der Pflanze sie stattfindet.',
                'Pflanzen stellen mithilfe von Lichtenergie aus Kohlenstoffdioxid und Wasser Traubenzucker her; dabei entsteht Sauerstoff. Das geschieht in den Chloroplasten der Blattzellen, der grüne Farbstoff Chlorophyll nimmt das Licht auf. Kohlenstoffdioxid gelangt über die Spaltöffnungen ins Blatt, Wasser über die Wurzeln.'],
            [QuestionType::Application, 'Biologie', 'Erkenntnisgewinnung',
                'Im Versuch mit der Wasserpest habt ihr bei 10 cm, 30 cm und 50 cm Abstand zur Lampe 38, 17 und 6 Gasbläschen pro Minute gezählt. Formuliere eine passende Hypothese und erkläre, was das Ergebnis zeigt. Welche Bedingungen müssen gleich bleiben?',
                'Hypothese: Je mehr Licht die Pflanze bekommt, desto mehr Fotosynthese betreibt sie und desto mehr Sauerstoff entsteht. Die Messwerte bestätigen das: Mit zunehmendem Abstand (weniger Licht) sinkt die Zahl der Bläschen. Gleich bleiben müssen z. B. Wassertemperatur, Pflanze, Wassermenge und Zählzeit, damit nur die Lichtstärke verändert wird (Variablenkontrolle).'],
            [QuestionType::Application, 'Biologie', 'Bewertung',
                'Der Regenwald wird oft als „grüne Lunge der Erde“ bezeichnet. Beurteile mithilfe deines Wissens über die Fotosynthese, ob dieser Vergleich passt.',
                'Dafür: Pflanzen nehmen Kohlenstoffdioxid auf und geben Sauerstoff ab, der Regenwald bindet große Mengen CO₂. Dagegen: Eine Lunge nimmt Sauerstoff auf und gibt Kohlenstoffdioxid ab, also genau umgekehrt; außerdem verbraucht der Wald einen Großteil seines Sauerstoffs durch Atmung und Zersetzung selbst. Ein begründetes Urteil wägt beide Seiten ab.'],
            [QuestionType::Reflection, null, 'Eigenes Lernen reflektieren',
                'Jonas hat in der Stunde gefragt, warum eine Pflanze nachts nicht „erstickt“. Wie wurde das im Tafelbild ergänzt, und was hat dir persönlich geholfen, den Unterschied zwischen Tag und Nacht zu verstehen?',
                'Im Tafelbild wurden Pfeile für Tag und Nacht ergänzt: Pflanzen atmen ständig (Zellatmung, Sauerstoff wird verbraucht), Fotosynthese findet nur bei Licht statt; tagsüber überwiegt sie. Dazu eine persönliche Aussage, was beim Verstehen geholfen hat (z. B. die Pfeile, die Fabrik-Analogie, die Frage selbst).'],
        ], [
            'schueler' => [
                'at' => $this->daysAgo(6, '17:42'),
                'summary' => 'Wir haben gelernt, dass Pflanzen ihre Nahrung selbst herstellen. Das nennt man Fotosynthese. Mit Licht machen sie aus Kohlenstoffdioxid und Wasser Traubenzucker und Sauerstoff. Beim Versuch mit der Wasserpest kamen mehr Bläschen, wenn die Lampe nah war. Die Kresse im dunklen Schrank war gelb, weil sie kein Licht hatte. Das alles passiert in den Chloroplasten.',
                'score' => 74,
                'feedback' => 'Eigenständige Zusammenfassung mit den wichtigsten Inhalten und beiden Versuchen. Das Fachwissen sitzt sicher; beim Auswerten von Versuchen und beim Abwägen von Argumenten noch genauer werden.',
                'gaps' => ['Variablenkontrolle: welche Bedingungen im Versuch gleich bleiben müssen', 'Begründetes Urteil mit Argumenten dafür und dagegen'],
                'answers' => [
                    ['Bei der Fotosynthese stellt die Pflanze aus Kohlenstoffdioxid und Wasser Traubenzucker her. Dafür braucht sie Licht und das Chlorophyll. Dabei entsteht Sauerstoff, den wir zum Atmen brauchen. Das passiert in den Chloroplasten in den Blättern. Das CO₂ kommt durch die Spaltöffnungen rein.', 85, 'Ausgangsstoffe, Produkte, Licht und Ort sind richtig und vollständig genannt. Es fehlt nur, wie das Wasser in die Pflanze gelangt (über die Wurzeln).'],
                    ['Hypothese: Je näher die Lampe ist, desto mehr Bläschen. Das stimmt, weil bei 10 cm 38 Bläschen waren und bei 50 cm nur 6. Die Bläschen sind Sauerstoff. Gleich bleiben muss die Pflanze.', 70, 'Die Messwerte sind richtig ausgewertet und der Sauerstoff wird erkannt. Die Hypothese beschreibt aber nur die Beobachtung (Abstand) statt den Zusammenhang Licht → Fotosynthese. Bei den Bedingungen fehlen Temperatur und Zählzeit.'],
                    ['Ich finde, der Vergleich passt, weil der Regenwald ganz viel Sauerstoff macht und wir den zum Atmen brauchen. Ohne Regenwald hätten wir keine Luft.', 55, 'Ein Argument für den Vergleich ist genannt. Es fehlt die Gegenseite: Eine Lunge nimmt Sauerstoff auf, die Pflanze gibt ihn ab. „Ohne Regenwald keine Luft“ ist zu stark verallgemeinert.'],
                    ['Frau Schneider hat Pfeile für Tag und Nacht an die Tafel gemalt. Die Pflanze atmet immer, also auch nachts, sie macht nur nachts keine Fotosynthese. Mir hat geholfen, dass Jonas das gefragt hat, weil ich mir das auch nicht erklären konnte. Die Fabrik mit dem Abfallprodukt fand ich auch gut.', 80, 'Der Unterschied zwischen Tag und Nacht ist richtig wiedergegeben und der eigene Lernweg nachvollziehbar beschrieben. Den Fachbegriff Zellatmung noch verwenden.'],
                ],
            ],
            'ben.krueger' => [
                'at' => $this->daysAgo(5, '20:10'),
                'summary' => 'Pflanzen brauchen Licht, damit sie wachsen. Wir haben einen Versuch mit einer Wasserpflanze gemacht und Bläschen gezählt. Die Kresse im Schrank war gelb.',
                'score' => 44,
                'feedback' => 'Die Versuche sind erinnert, die Fotosynthese selbst (Ausgangsstoffe, Produkte) fehlt in der Zusammenfassung. Ausgangsstoffe und Produkte werden noch verwechselt.',
                'gaps' => ['Ausgangsstoffe und Produkte der Fotosynthese (CO₂ wird aufgenommen, O₂ abgegeben)', 'Deutung des Wasserpest-Versuchs', 'Behauptungen begründen'],
                'answers' => [
                    ['Die Pflanze macht aus Sauerstoff und Wasser Zucker. Dafür braucht sie Sonne.', 45, 'Licht und Zucker als Produkt sind richtig. Sauerstoff ist aber ein Produkt, kein Ausgangsstoff; der Ausgangsstoff ist Kohlenstoffdioxid. Der Ort (Chloroplasten) fehlt.'],
                    ['Wenn die Lampe nah ist, kommen mehr Bläschen. Das ist Luft.', 30, 'Die Beobachtung ist richtig, aber es fehlen eine Hypothese, die Deutung (Sauerstoff aus der Fotosynthese) und die Bedingungen, die gleich bleiben müssen.'],
                    ['Ja, weil Bäume grün sind und Luft machen.', 40, 'Die Aussage ist nicht begründet. Hilfreich: zuerst erklären, was eine Lunge macht und was eine Pflanze bei der Fotosynthese macht, dann beides vergleichen.'],
                    ['Es wurde mit Pfeilen gezeigt. Die Pflanze atmet auch nachts. Die Pfeile haben mir geholfen.', 60, 'Die Kernaussage ist richtig. Der Unterschied zwischen Tag und Nacht (ohne Licht keine Fotosynthese) könnte genauer beschrieben werden.'],
                ],
            ],
            'lena.schulz' => [
                'at' => $this->daysAgo(7, '16:05'),
                'summary' => 'Die Fotosynthese ist der Vorgang, bei dem grüne Pflanzen mit Lichtenergie aus Kohlenstoffdioxid und Wasser Traubenzucker und Sauerstoff herstellen. Sie findet in den Chloroplasten statt. Der Kresse-Versuch zeigt, dass Pflanzen ohne Licht nicht gesund wachsen; der Wasserpest-Versuch zeigt, dass mit mehr Licht mehr Sauerstoff entsteht. Pflanzen atmen außerdem Tag und Nacht (Zellatmung).',
                'score' => 88,
                'feedback' => 'Sehr vollständige und strukturierte Zusammenfassung; die Versuche werden mit der Theorie verknüpft.',
                'gaps' => [],
                'answers' => [
                    ['Bei der Fotosynthese wandeln Pflanzen Lichtenergie in chemische Energie um. In den Chloroplasten der Blattzellen wird mithilfe von Chlorophyll aus Kohlenstoffdioxid (über die Spaltöffnungen) und Wasser (über die Wurzeln) Traubenzucker hergestellt. Als Nebenprodukt entsteht Sauerstoff, der über die Spaltöffnungen abgegeben wird.', 92, 'Vollständig und fachlich präzise, einschließlich der Energieumwandlung.'],
                    ['Hypothese: Je stärker das Licht, desto mehr Fotosynthese und desto mehr Sauerstoff. Die Messwerte bestätigen das, die Bläschenzahl sinkt von 38 auf 6, wenn die Lampe weiter weg ist. Gleich bleiben müssen die Temperatur, dieselbe Pflanze und die Zählzeit von einer Minute.', 88, 'Hypothese, Auswertung und Variablenkontrolle sind korrekt. Ergänzung möglich: Die Lampe erwärmt auch das Wasser, ein Wärmefilter wäre sinnvoll.'],
                    ['Teilweise. Wie eine Lunge ist der Regenwald wichtig für den Gasaustausch der Erde und nimmt viel CO₂ auf. Aber eine Lunge nimmt Sauerstoff auf und gibt CO₂ ab, die Pflanzen machen es bei der Fotosynthese umgekehrt. Der Vergleich passt also nur, wenn man damit meint, dass der Regenwald lebenswichtig ist.', 80, 'Ausgewogenes Urteil mit Argument und Gegenargument. Zusätzlich könnte erwähnt werden, dass der Wald einen großen Teil des Sauerstoffs selbst wieder verbraucht.'],
                    ['Es wurden Pfeile für Tag und Nacht ergänzt: Zellatmung läuft immer, Fotosynthese nur bei Licht. Tagsüber produziert die Pflanze mehr Sauerstoff, als sie verbraucht, nachts nimmt sie nur Sauerstoff auf. Mir hat geholfen, die beiden Vorgänge als Gegenteile zu sehen.', 90, 'Präzise und mit eigenem Verständnisweg reflektiert.'],
                ],
            ],
            'jonas.becker' => [
                'at' => $this->daysAgo(6, '19:30'),
                'summary' => 'Thema war Fotosynthese. Pflanzen machen aus CO₂ und Wasser mit Licht Traubenzucker und Sauerstoff. Wir haben die Wasserpest unter die Lampe gestellt und Bläschen gezählt. Ich habe gefragt, warum Pflanzen nachts nicht ersticken – weil sie auch atmen.',
                'score' => 63,
                'feedback' => 'Inhaltlich korrekt. Bei der Auswertung des Versuchs bleibt es beim Beschreiben der Messwerte, die Deutung fehlt.',
                'gaps' => ['Versuchsergebnisse deuten statt nur beschreiben', 'Hypothesen formulieren'],
                'answers' => [
                    ['Die Pflanze nimmt CO₂ und Wasser auf und macht daraus Zucker und Sauerstoff. Dafür braucht sie Licht. Das ist in den Blättern.', 70, 'Ausgangsstoffe und Produkte sind korrekt. Genauer wäre: in den Chloroplasten, mithilfe von Chlorophyll.'],
                    ['Bei 10 cm waren es 38, bei 30 cm 17 und bei 50 cm 6. Also werden es weniger.', 45, 'Die Messwerte werden nur wiedergegeben. Es fehlen die Hypothese, die Erklärung (weniger Licht → weniger Fotosynthese → weniger Sauerstoff) und die konstanten Bedingungen.'],
                    ['Der Vergleich passt nicht ganz, weil die Lunge Sauerstoff braucht und der Wald Sauerstoff macht. Aber beide sind wichtig für die Luft.', 65, 'Der zentrale Unterschied ist erkannt. Das Urteil könnte deutlicher begründet und abschließend formuliert werden.'],
                    ['Das war meine Frage :) Pflanzen atmen auch, das ist die Zellatmung, nachts gibt es aber keine Fotosynthese. Mir hat geholfen, dass Frau Schneider das gleich an der Tafel ergänzt hat und nicht erst nächste Woche.', 75, 'Inhalt richtig; die eigene Frage als Lernanlass gut reflektiert.'],
                ],
            ],
            'elif.yilmaz' => [
                'at' => $this->daysAgo(7, '18:15'),
                'summary' => 'Pflanzen sind Selbstversorger: Mit Licht stellen sie aus Kohlenstoffdioxid und Wasser Traubenzucker her, dabei entsteht Sauerstoff. Das ist die Fotosynthese, sie läuft in den Chloroplasten ab. Am Anfang jeder Nahrungskette steht deshalb eine Pflanze. Im Versuch mit der Wasserpest gab es bei mehr Licht mehr Sauerstoffbläschen.',
                'score' => 83,
                'feedback' => 'Strukturierte Zusammenfassung mit eigenständigem Transfer zur Nahrungskette.',
                'gaps' => ['Konstante Versuchsbedingungen vollständig benennen (Temperatur)'],
                'answers' => [
                    ['Pflanzen bauen in den Chloroplasten mithilfe von Licht und Chlorophyll aus Kohlenstoffdioxid und Wasser Traubenzucker auf. Dabei wird Sauerstoff frei. Das CO₂ kommt durch die Spaltöffnungen an der Blattunterseite, das Wasser von den Wurzeln.', 88, 'Vollständig und korrekt.'],
                    ['Meine Hypothese: Wenn die Pflanze mehr Licht bekommt, macht sie mehr Fotosynthese und es entsteht mehr Sauerstoff. Das sieht man an den Werten 38 – 17 – 6. Gleich bleiben müssen die Pflanze, das Wasser und wie lange man zählt.', 82, 'Hypothese und Auswertung korrekt. Die Temperatur als wichtige Störgröße fehlt.'],
                    ['Ich finde, der Vergleich ist nur halb richtig. Der Regenwald macht viel Sauerstoff und speichert CO₂, das ist wichtig für alle. Aber die Lunge macht das Gegenteil von der Fotosynthese. Außerdem kommt viel Sauerstoff auch aus dem Meer (Algen).', 76, 'Abgewogenes Urteil mit eigenem Zusatzwissen (Algen).'],
                    ['Es wurden Pfeile für Tag und Nacht gezeichnet. Nachts atmet die Pflanze nur. Geholfen hat mir mein eigener Gedanke, dass dann ja all unser Essen vom Licht kommt – dadurch habe ich verstanden, warum die Fotosynthese so wichtig ist.', 85, 'Eigener Gedankengang klar beschrieben; dass die Pflanze auch tagsüber atmet, könnte noch erwähnt werden.'],
                ],
            ],
            'noah.wagner' => [
                'at' => $this->daysAgo(3, '07:25'),
                'summary' => 'Wir hatten Fotosynthese. Pflanzen brauchen Licht. Wasserpest-Versuch mit Lampe.',
                'score' => 47,
                'feedback' => 'Die Zusammenfassung ist sehr knapp; Ausgangsstoffe, Produkte und das Ergebnis des Versuchs fehlen.',
                'gaps' => ['Wortgleichung der Fotosynthese', 'Unterschied zwischen Fotosynthese und Zellatmung', 'Urteile begründen'],
                'answers' => [
                    ['Die Pflanze braucht Licht, Wasser und Sauerstoff und macht daraus Zucker und CO₂. Das passiert im Blatt im Chlorophyll.', 55, 'Licht, Wasser und Zucker sind richtig. Sauerstoff und Kohlenstoffdioxid sind vertauscht. Chlorophyll ist der Farbstoff, der Ort sind die Chloroplasten.'],
                    ['Hypothese: Die Lampe macht Bläschen. Bei 10 cm waren es am meisten. Gleich bleiben muss die Lampe.', 48, 'Die Messung ist richtig abgelesen. Die Hypothese sollte den Zusammenhang Licht – Fotosynthese – Sauerstoff beschreiben. Der Abstand der Lampe ist gerade die Größe, die verändert wird.'],
                    ['Ja.', 35, 'Ohne Begründung lässt sich das Urteil nicht nachvollziehen. Mindestens ein Argument dafür und eines dagegen nennen.'],
                    ['Mit Pfeilen. Ich habe nicht ganz verstanden, warum die Pflanze dann Sauerstoff braucht.', 50, 'Ehrliche Rückmeldung; die offene Frage zur Zellatmung wird in der nächsten Stunde vertieft. Den Tafelbild-Eintrag (Atmung Tag und Nacht) noch einmal im Heft nachlesen.'],
                ],
            ],
            'emma.fischer' => [
                'at' => $this->daysAgo(5, '16:50'),
                'summary' => 'Pflanzen stellen bei der Fotosynthese mit Lichtenergie aus Kohlenstoffdioxid und Wasser Traubenzucker und Sauerstoff her. Das findet in den Chloroplasten statt. Der Wasserpest-Versuch hat gezeigt: mehr Licht = mehr Sauerstoff. Pflanzen atmen auch nachts.',
                'score' => 75,
                'feedback' => 'Korrekte und knappe Zusammenfassung der Kerninhalte.',
                'gaps' => ['Aufnahmewege von Kohlenstoffdioxid und Wasser'],
                'answers' => [
                    ['Kohlenstoffdioxid + Wasser → Traubenzucker + Sauerstoff, mit Lichtenergie. Findet in den Chloroplasten statt, das Chlorophyll fängt das Licht ein.', 78, 'Korrekte Wortgleichung und Ort. Woher Kohlenstoffdioxid und Wasser in die Pflanze gelangen, fehlt.'],
                    ['Je mehr Licht, desto mehr Sauerstoff. Bei 10 cm 38 Bläschen, bei 50 cm nur 6, also stimmt die Hypothese. Gleich bleiben sollte die Temperatur vom Wasser.', 72, 'Hypothese und Auswertung passen, die Temperatur ist als Bedingung erkannt. Weitere konstante Bedingungen (gleiche Pflanze, gleiche Zählzeit) ergänzen.'],
                    ['Der Vergleich passt, weil der Regenwald CO₂ aus der Luft holt und Sauerstoff abgibt, und wir brauchen Sauerstoff zum Atmen. Nur macht die Lunge das umgekehrt.', 68, 'Das Argument dafür ist gut begründet, das Gegenargument angedeutet. Ein abschließendes Urteil fehlt.'],
                    ['Es wurden Pfeile für Tag und Nacht ergänzt. Pflanzen atmen immer, nur tagsüber machen sie zusätzlich Fotosynthese. Mir hat die Zeichnung geholfen, weil ich Sachen besser verstehe, wenn ich sie sehe.', 82, 'Richtig und mit Bezug zum eigenen Lernen.'],
                ],
            ],
        ]);
    }

    /**
     * @param  iterable<User>  $students
     */
    private function seedPythagoras(SchoolClass $schoolClass, User $teacher, iterable $students): Lesson
    {
        return $this->lesson($schoolClass, $teacher, [
            'subject' => 'Mathematik',
            'topic' => 'Satz des Pythagoras',
            'held_on' => $this->daysAgo(3),
            'published_at' => $this->daysAgo(3, '13:45'),
            'due_on' => today()->addDays(4),
            'notes' => <<<'NOTES'
                Mathe 7b – Satz des Pythagoras (Einführung)

                Einstieg: Foto vom Sportplatz – der Hausmeister will für das neue Kleinfeld einen rechten Winkel abstecken, nur mit einem Seil. Zwölfknotenschnur (alte Ägypter) ausgeteilt. Gruppe am Fenster findet nach ein paar Versuchen 3-4-5.

                Erarbeitung (Gruppenarbeit, 25 min): Jede Gruppe ein Dreieck auf Karopapier (rechtwinklig 3/4/5 und 6/8/10, dazu ein spitzwinkliges und ein stumpfwinkliges). Quadrate an die Seiten zeichnen, ausschneiden, Kästchen zählen bzw. Flächen vergleichen.
                Elif zuerst: „Das klappt bei jedem Dreieck.“ → Gruppe prüft am stumpfwinkligen Dreieck nach: a² + b² < c², passt nicht. Gute Diskussion, wie man eine Vermutung widerlegt (ein Gegenbeispiel reicht).

                Tafelbild:
                Im rechtwinkligen Dreieck gilt: a² + b² = c²
                c = Hypotenuse (liegt dem rechten Winkel gegenüber, längste Seite)
                a, b = Katheten (bilden den rechten Winkel)
                Merksatz: Die Summe der Kathetenquadrate ist gleich dem Hypotenusenquadrat.
                Beispiel: a = 3 cm, b = 4 cm → c² = 9 + 16 = 25 → c = 5 cm
                Achtung: Welche Seite die Hypotenuse ist, hängt von der Lage des rechten Winkels ab, nicht von den Buchstaben!

                Übung: S. 112 Nr. 1–4 (Hypotenuse berechnen), Nr. 5 (Kathete berechnen, umstellen: b² = c² − a²) → viele Fehler beim Umstellen, z. B. b = c − a. Nächste Stunde Wurzelziehen und Umstellen noch mal üben.
                Taschenrechner: √-Taste erklärt, auf 2 Nachkommastellen runden.

                Notiz: Mia, Ben, Noah brauchen noch Sicherheit bei Flächen (siehe Förderaufgabe). Lena hat schon den Zerlegungsbeweis gesehen → Zusatzaufgabe mitgegeben.
                NOTES,
            'summary' => 'Ausgehend von der Frage, wie man mit einem Seil einen rechten Winkel absteckt, wurde der Satz des Pythagoras entdeckt: In jedem rechtwinkligen Dreieck ist die Summe der Kathetenquadrate gleich dem Hypotenusenquadrat (a² + b² = c²). Die Hypotenuse liegt dem rechten Winkel gegenüber und ist die längste Seite. In Gruppenarbeit wurden Quadrate an Dreiecksseiten verglichen; ein stumpfwinkliges Dreieck diente als Gegenbeispiel dafür, dass der Satz nur für rechtwinklige Dreiecke gilt. Geübt wurde das Berechnen der Hypotenuse; das Umstellen nach einer Kathete wird in der nächsten Stunde vertieft.',
        ], $students, [
            [QuestionType::Comprehension, 'Mathematik', 'Mit symbolischen, formalen und technischen Elementen umgehen',
                'Formuliere den Satz des Pythagoras in Worten und als Formel. Für welche Dreiecke gilt er, und wie erkennst du die Hypotenuse?',
                'In jedem rechtwinkligen Dreieck ist die Summe der Flächeninhalte der Kathetenquadrate gleich dem Flächeninhalt des Hypotenusenquadrats: a² + b² = c², wenn c die Hypotenuse ist. Die Hypotenuse liegt dem rechten Winkel gegenüber und ist die längste Seite; die Katheten bilden den rechten Winkel.'],
            [QuestionType::Application, 'Mathematik', 'Mathematisch modellieren',
                'Eine 5 m lange Leiter lehnt an einer Hauswand. Ihr Fuß steht 1,40 m von der Wand entfernt. In welcher Höhe berührt die Leiter die Wand? Fertige eine Skizze an und runde auf Zentimeter.',
                'Skizze: rechtwinkliges Dreieck aus Wand, Boden und Leiter; die Leiter ist die Hypotenuse. h² = 5² − 1,40² = 25 − 1,96 = 23,04, also h = 4,80 m. Die Leiter berührt die Wand in 4,80 m Höhe.'],
            [QuestionType::Reflection, 'Mathematik', 'Mathematisch argumentieren',
                'Elif war in der Stunde zuerst sicher, dass die Quadrat-Regel bei jedem Dreieck klappt. Wie hat eure Gruppe überprüft, ob das stimmt, und was folgt daraus für den Satz des Pythagoras?',
                'Mit einem Gegenbeispiel: Beim stumpfwinkligen (oder spitzwinkligen) Dreieck wurden die Quadrate an den Seiten verglichen, a² + b² war nicht gleich c². Daraus folgt: Der Satz gilt nur für rechtwinklige Dreiecke; ein einziges Gegenbeispiel genügt, um eine allgemeine Behauptung zu widerlegen.'],
        ], [
            'lena.schulz' => [
                'at' => $this->daysAgo(2, '17:10'),
                'summary' => 'Der Satz des Pythagoras gilt in rechtwinkligen Dreiecken: a² + b² = c², wobei c die Hypotenuse gegenüber dem rechten Winkel ist. Wir haben ihn mit Quadraten auf Karopapier entdeckt und überprüft, dass er bei anderen Dreiecken nicht gilt. Mit dem Satz kann man fehlende Seitenlängen berechnen, manchmal muss man dafür umstellen.',
                'score' => 87,
                'feedback' => 'Kerninhalte vollständig, Herleitung und Einschränkung auf rechtwinklige Dreiecke erfasst.',
                'gaps' => [],
                'answers' => [
                    ['In einem rechtwinkligen Dreieck gilt: a² + b² = c². Die Flächen der Quadrate über den beiden Katheten sind zusammen so groß wie das Quadrat über der Hypotenuse. Die Hypotenuse liegt gegenüber vom rechten Winkel und ist immer die längste Seite.', 95, 'Vollständig und präzise.'],
                    ['Skizze: Wand senkrecht, Boden waagerecht, Leiter schräg = Hypotenuse. h² + 1,4² = 5², also h² = 25 − 1,96 = 23,04 und h = 4,8 m. Die Leiter berührt die Wand in 4,80 m Höhe.', 85, 'Modell, Rechnung und Antwortsatz korrekt. Die Skizze ist beschrieben, aber nicht beigefügt.'],
                    ['Wir hatten das spitzwinklige Dreieck. Da waren die zwei kleinen Quadrate zusammen größer als das große. Also klappt es nicht bei jedem Dreieck, sondern nur beim rechtwinkligen. Ein Gegenbeispiel reicht, um zu zeigen, dass eine Behauptung falsch ist.', 80, 'Die Argumentation mit dem Gegenbeispiel ist klar.'],
                ],
            ],
            'elif.yilmaz' => [
                'at' => $this->daysAgo(1, '18:40'),
                'summary' => 'Satz des Pythagoras: a² + b² = c² im rechtwinkligen Dreieck. c ist die Hypotenuse. Wir haben Quadrate ausgeschnitten und verglichen. Bei anderen Dreiecken klappt es nicht.',
                'score' => 70,
                'feedback' => 'Kerninhalte richtig; das Berechnen einer Kathete (Umstellen) fehlt, und in der Sachaufgabe wurde die Hypotenuse nicht erkannt.',
                'gaps' => ['Formel nach einer Kathete umstellen', 'Hypotenuse im Sachzusammenhang erkennen'],
                'answers' => [
                    ['a² + b² = c². Das gilt bei rechtwinkligen Dreiecken. Die Hypotenuse ist die längste Seite.', 80, 'Formel und Gültigkeit richtig. Die Lage der Hypotenuse (gegenüber dem rechten Winkel) und die Formulierung in Worten fehlen.'],
                    ['5² + 1,4² = c², also 25 + 1,96 = 26,96 und c = 5,19 m. Die Leiter ist in 5,19 m Höhe.', 60, 'Die Situation wurde falsch modelliert: Die Leiter ist die Hypotenuse, gesucht ist eine Kathete. Ein Plausibilitätscheck hilft – die Höhe kann nicht größer als die Leiterlänge sein.'],
                    ['Ich dachte zuerst, das geht immer :) Wir haben dann das stumpfe Dreieck genommen, und da war das große Quadrat viel größer als die zwei kleinen zusammen. Also nur bei rechtwinkligen.', 70, 'Die Überprüfung am Gegenbeispiel ist richtig beschrieben und der eigene Denkfehler offen reflektiert. Warum ein Gegenbeispiel genügt, könnte noch ergänzt werden.'],
                ],
            ],
            'jonas.becker' => [
                'at' => $this->daysAgo(1, '20:55'),
                'summary' => 'Wir haben den Satz des Pythagoras gelernt: a² + b² = c². Mit einem Seil mit Knoten kann man einen rechten Winkel machen (3-4-5). Wir haben Quadrate gezeichnet.',
                'score' => 57,
                'feedback' => 'Einstieg und Formel sind erinnert; wofür man den Satz nutzt und wie man ihn in Sachaufgaben anwendet, fehlt.',
                'gaps' => ['Satz des Pythagoras in Sachaufgaben anwenden', 'Skizze als Lösungsstrategie nutzen'],
                'answers' => [
                    ['Der Satz des Pythagoras heißt a² + b² = c² und gilt im rechtwinkligen Dreieck. c ist die Hypotenuse, die ist gegenüber vom rechten Winkel.', 75, 'Korrekt; die Formulierung in Worten (Flächen der Quadrate) fehlt.'],
                    ['5 − 1,40 = 3,60 m. Die Leiter ist 3,60 m hoch an der Wand.', 40, 'Der Satz des Pythagoras wurde nicht angewendet; es wurden Längen subtrahiert statt Quadrate. Tipp: Skizze anfertigen, rechten Winkel markieren, dann die Formel aufstellen.'],
                    ['Wir haben noch ein anderes Dreieck genommen, und da hat es nicht gepasst. Deshalb gilt es nur bei rechtwinkligen Dreiecken.', 55, 'Das Ergebnis ist richtig; die Begründung, warum ein Gegenbeispiel genügt, fehlt.'],
                ],
            ],
        ]);
    }

    private function seedFrenchRevolution(SchoolClass $schoolClass, User $teacher): void
    {
        $this->lesson($schoolClass, $teacher, [
            'subject' => 'Geschichte',
            'topic' => 'Ursachen der Französischen Revolution',
            'held_on' => $this->daysAgo(1),
            'notes' => <<<'NOTES'
                Geschichte 7b – Ursachen der Französischen Revolution

                Einstieg: Karikatur „Le Tiers État portant la Noblesse et le Clergé“ (Bauer trägt Adligen und Geistlichen auf dem Rücken). Bildbeschreibung nach den 4 Schritten (Beschreiben – Untersuchen – Deuten – Bewerten).
                Schülerbeiträge: „Der Bauer muss alles tragen“, „Die zwei oben lachen“. Noah: „Was steht auf den Steinen?“ → Taille und Corvée = Steuern und Frondienste erklärt.

                Erarbeitung: Ständepyramide im Buch S. 148.
                1. Stand Klerus (ca. 0,5 % der Bevölkerung), 2. Stand Adel (ca. 1,5 %), 3. Stand (ca. 98 %: Bauern, Handwerker, Bürgertum). Nur der 3. Stand zahlt direkte Steuern.
                Gruppenpuzzle Ursachen:
                - Staatsfinanzen: hohe Schulden (Kriege, u. a. Unterstützung der amerikanischen Unabhängigkeit, Hofhaltung in Versailles)
                - Missernte 1788, Brotpreis steigt (ein Brot kostet ca. einen halben Tageslohn)
                - Ideen der Aufklärung (Montesquieu: Gewaltenteilung, Rousseau: Volkssouveränität)
                - Generalstände Mai 1789: Abstimmung nach Ständen (2 : 1 gegen den 3. Stand) → Ballhausschwur 20.6.1789

                Tafelbild: Mindmap Ursachen – wirtschaftlich / sozial / politisch / Ideen der Aufklärung.

                Diskussion am Ende: „War die Revolution unvermeidbar?“ Emma: ja, weil die Menschen hungerten. Luca: Der König hätte einfach alle Stände besteuern können. → Gute Vorlage für die Urteilsbildung nächste Stunde.

                Material: Karikatur (Kopie), Buch S. 148–151, Quelle Sieyès „Was ist der Dritte Stand?“ (Auszug, nicht mehr geschafft → nächste Stunde).
                NOTES,
        ]);
    }

    private function seedExplanationSessions(User $student, Lesson $photosynthesis, Lesson $pythagoras): void
    {
        $this->explanationSession($student, $photosynthesis, $this->daysAgo(5, '16:00'), [
            [MessageAuthor::Student, 'Also, bei der Fotosynthese macht die Pflanze aus Kohlenstoffdioxid und Wasser Traubenzucker. Dafür braucht sie Licht. Und dabei kommt Sauerstoff raus.'],
            [MessageAuthor::Tutor, 'Gut erklärt! Woher weiß man denn, dass die Pflanze wirklich Licht dafür braucht? Kannst du ein Beispiel aus dem Unterricht nennen?'],
            [MessageAuthor::Student, 'Ja, die Kresse im Schrank war ganz gelb und dünn. Die am Fenster war grün. Ohne Licht kann sie keinen Zucker machen.'],
            [MessageAuthor::Tutor, 'Genau. Im Versuch mit der Wasserpest habt ihr den Abstand zur Lampe verändert. Was hättet ihr gleich lassen müssen, damit der Versuch aussagekräftig ist?'],
            [MessageAuthor::Student, 'Hm, die Lampe? Oder die Pflanze. Ich glaube die Pflanze.'],
            [MessageAuthor::Tutor, 'Die Pflanze ist richtig. Die Lampe bleibt auch gleich, nur ihr Abstand ändert sich. Was könnte sich noch verändern, wenn die Lampe ganz nah am Glas steht?'],
            [MessageAuthor::Student, 'Das Wasser wird warm? Daran habe ich nicht gedacht.'],
        ], [
            'understanding_score' => 68,
            'feedback' => 'Ausgangsstoffe und Produkte der Fotosynthese werden sicher erklärt, die Rolle des Lichts wird mit dem Kresse-Versuch begründet. Bei der Frage, welche Bedingungen im Wasserpest-Versuch gleich bleiben müssen, war die Antwort unsicher; die Temperatur wurde erst auf Nachfrage erkannt. Empfehlung: Versuchsplanung (Variablen kontrollieren) wiederholen.',
            'finished_at' => $this->daysAgo(5, '16:25'),
        ]);

        $this->explanationSession($student, $pythagoras, $this->daysAgo(1, '19:00'), [
            [MessageAuthor::Student, 'Der Satz des Pythagoras ist a² + b² = c². Den braucht man für Dreiecke.'],
            [MessageAuthor::Tutor, 'Für welche Dreiecke genau gilt er? Und welche Seite ist c?'],
        ]);
    }

    /**
     * @param  list<array{0: MessageAuthor, 1: string}>  $messages
     * @param  array<string, mixed>  $result  assessment of a finished session
     */
    private function explanationSession(User $student, Lesson $lesson, Carbon $startedAt, array $messages, array $result = []): void
    {
        $session = ExplanationSession::query()->firstOrNew(['student_id' => $student->id, 'topic' => $lesson->topic]);

        if ($session->exists) {
            return;
        }

        $session->forceFill([...$result, 'lesson_id' => $lesson->id, 'created_at' => $startedAt, 'updated_at' => $startedAt])->save();

        foreach ($messages as [$author, $content]) {
            $session->messages()->create(['author' => $author, 'content' => $content]);
        }
    }
}
