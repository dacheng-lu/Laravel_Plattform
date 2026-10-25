<?php

namespace Tests\Feature\PrivacyDocuments;

use App\Filament\Pages\PrivacyDocuments;
use App\Filament\Pages\RecordingSettings;
use App\Models\ConsentForm;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Consents\CreatesConsentFixtures;
use Tests\TestCase;

class PrivacyDocumentsTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    /**
     * Every template with a heading and phrases that must stay in it.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'Checkliste' => ['checkliste', 'Checkliste für die Einführung', ['Teil A: Einsatz der Lernplattform', 'Teil B: Zusätzlich vor dem Aufnahme-Modul', 'Auftragsverarbeitungsvertrag abgeschlossen', 'Personalrat beteiligt', 'Datenschutz-Folgenabschätzung durchgeführt', 'Elterninformation', 'Einwilligungen aller aufgenommenen Personen', 'Löschfristen festgelegt', 'Ansprechpartner']],
            'Folgenabschätzung' => ['dsfa', 'Datenschutz-Folgenabschätzung (Art. 35 DSGVO)', ['Systematische Beschreibung der Verarbeitung', 'Notwendigkeit und Verhältnismäßigkeit', 'Verhaltensanpassung durch Überwachungsdruck', 'Missbrauch und Weitergabe von Aufnahmen', 'Wiedererkennung trotz Pseudonymisierung', 'Fehler und Verzerrungen der KI', 'Maßnahmen der Plattform', 'Was die Plattform nicht leistet', 'Trennung der Schulen', 'Stellungnahme der oder des Datenschutzbeauftragten', 'Konsultation der Aufsichtsbehörde']],
            'Verzeichnis' => ['verzeichnis', 'Eintrag für das Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)', ['A: Nutzung der Lernplattform im Unterricht', 'B: Tonaufnahme von Unterricht', 'Zwecke', 'Betroffene Personen', 'Datenkategorien', 'Empfänger', 'Übermittlung in Drittländer', 'Löschfristen', 'Technische und organisatorische Maßnahmen']],
            'Elterninformation' => ['elterninformation', 'Elterninformation zur Lernplattform (Art. 13 DSGVO)', ['Wer ist verantwortlich?', 'Welche Daten werden verarbeitet?', 'Künstliche Intelligenz', 'Aufnahme-Modul: Ton im Unterricht', 'Freiwillig und nur mit Einwilligung', 'Ihre Rechte und die Rechte Ihres Kindes', 'Widerruf einer erteilten Einwilligung', 'Beschwerde bei einer Aufsichtsbehörde']],
            'Einwilligung' => ['einwilligung', 'Einwilligung in Tonaufnahmen im Unterricht (Muster)', [ConsentForm::PURPOSES['audio'], ConsentForm::PURPOSES['transcript'], ConsentForm::PURPOSES['ai_analysis'], 'Die Einwilligung ist freiwillig', 'jederzeit ohne Angabe von Gründen widerrufen', 'Variante für Lehrkräfte und weitere anwesende Personen']],
        ];
    }

    private function schoolAdmin(?School $school = null): User
    {
        return User::factory()->for($school ?? School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'city' => 'Kassel', 'contact_email' => 'sekretariat@goethe.example.test']))->admin()->create();
    }

    /**
     * The template itself: the page chrome around it (menu, avatar) is not part of what a school prints.
     */
    private function articleOf(string $html): string
    {
        return Str::before(Str::after($html, '<article'), '</article>');
    }

    /**
     * Only the key of every template, for tests that do not need the heading and phrases.
     *
     * @return array<string, array{0: string}>
     */
    public static function documentKeys(): array
    {
        return array_map(fn (array $document): array => [$document[0]], self::documents());
    }

    #[DataProvider('documents')]
    public function test_the_administration_gets_every_template_with_the_data_of_its_school(string $key, string $heading, array $phrases): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'city' => 'Kassel', 'state' => 'Hessen', 'contact_email' => 'sekretariat@goethe.example.test']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $this->actingAs($this->schoolAdmin($school));

        $response = $this->get(PrivacyDocuments::getUrl(['document' => $key]))
            ->assertOk()
            ->assertSee('Datenschutz-Unterlagen')
            ->assertSee($heading)
            ->assertSee('Goethe-Gymnasium')
            ->assertDontSee($otherSchool->name);

        foreach ($phrases as $phrase) {
            $response->assertSee($phrase);
        }
    }

    #[DataProvider('documentKeys')]
    public function test_every_template_says_it_is_only_a_template_and_can_be_printed(string $key): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => $key]))
            ->assertOk()
            ->assertSee('Vorlage – an die Gegebenheiten der Schule anpassen, keine Rechtsberatung')
            ->assertSee('Vorlage drucken')
            ->assertSee('window.print()', false)
            ->assertSee('@media print', false);
    }

    public function test_the_master_data_of_the_school_and_the_operator_are_prefilled(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSeeInOrder(['Schule', 'Goethe-Gymnasium', 'Schulnummer', '111111', 'Ort, Bundesland', 'Kassel', 'Kontakt-E-Mail', 'sekretariat@goethe.example.test'])
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('info@sebastian-bleuel.de');
    }

    public function test_missing_master_data_becomes_a_line_to_fill_in(): void
    {
        $school = School::factory()->create(['school_number' => null, 'school_type' => null]);

        $this->actingAs($this->schoolAdmin($school));

        $html = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="pd-blank"><span class="sr-only">bitte ausfüllen</span></span>', $this->articleOf($html));
    }

    public function test_the_open_template_is_chosen_by_the_url_and_unknown_values_show_the_checklist(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl())->assertOk()->assertSee('Checkliste für die Einführung');
        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()->assertSee('Datenschutz-Folgenabschätzung (Art. 35 DSGVO)')->assertDontSee('Teil A: Einsatz der Lernplattform');
        $this->get(PrivacyDocuments::getUrl(['document' => '../../.env']))->assertOk()->assertSee('Checkliste für die Einführung');
    }

    public function test_the_menu_links_to_every_template_and_marks_the_open_one(): void
    {
        $this->actingAs($this->schoolAdmin());

        $response = $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk();

        foreach (array_keys(self::documents()) as $label) {
            $response->assertSee($label);
        }

        foreach (['checkliste', 'dsfa', 'verzeichnis', 'elterninformation', 'einwilligung'] as $key) {
            $response->assertSee('href="'.PrivacyDocuments::getUrl(['document' => $key]).'"', false);
        }

        $response->assertSee('aria-current="page"', false);
    }

    public function test_the_texts_follow_the_real_settings_of_the_platform(): void
    {
        config([
            'lernplattform.recordings.keep_days' => 21,
            'lernplattform.retention.school_years_kept' => 3,
            'services.anthropic.enabled' => false,
            'services.transcription.enabled' => false,
        ]);
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))
            ->assertOk()
            ->assertSee('21 Tage')
            ->assertSee('nach 3 Schuljahren')
            ->assertSee('Ausgeschaltet: keine Daten verlassen die Plattform');

        config(['services.anthropic.enabled' => true, 'services.transcription.enabled' => true]);

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))
            ->assertOk()
            ->assertSee('Eingeschaltet: '.PrivacyDocuments::AI_DATA.' gehen an Anthropic PBC (USA)')
            ->assertSee('Eingeschaltet: Audio geht zur Texterkennung an den eingerichteten Dienst');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Dafür werden '.PrivacyDocuments::AI_DATA.' an Anthropic PBC in den USA übermittelt')
            ->assertSee('21 Tage');
    }

    public function test_the_consent_names_the_three_purposes_and_the_parent_information_uses_sie(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))
            ->assertOk()
            ->assertSeeInOrder(['1. '.ConsentForm::PURPOSES['audio'], '2. '.ConsentForm::PURPOSES['transcript'], '3. '.ConsentForm::PURPOSES['ai_analysis'], 'Ja, ich willige ein', 'Nein, ich willige nicht ein'])
            ->assertSee('Wer nicht einwilligt, hat keine Nachteile')
            ->assertSee('Unterschrift Erziehungsberechtigte(r)');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Sehr geehrte Eltern und Erziehungsberechtigte')
            ->assertSee('Sie können die Einwilligung jederzeit')
            ->assertDontSee('Du darfst');
    }

    #[DataProvider('documentKeys')]
    public function test_nothing_in_a_template_loads_from_an_external_host(string $key): void
    {
        $this->actingAs($this->schoolAdmin());

        $html = $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertOk()->getContent();
        // The inline icons carry the SVG namespace, which names no host to load from.
        // Links to the platform's own pages (the agreement, the settings) are fine and come as absolute URLs.
        $article = str_replace(url('/'), '', preg_replace('~xmlns="[^"]*"~', '', $this->articleOf($html)));

        $this->assertDoesNotMatchRegularExpression('~https?://~i', $article, 'The template must not link or load anything from outside.');
        $this->assertDoesNotMatchRegularExpression('~<(img|script|iframe|link|object|embed|video|audio|source)\b~i', $article);
        $this->assertDoesNotMatchRegularExpression('~url\(|@import~i', $article);

        $style = Str::before(Str::after($html, '/* The templates: one calm reading column'), '</style>');
        $this->assertDoesNotMatchRegularExpression('~https?://|url\(|@import~i', $style, 'The print and layout styles of the templates are self-contained.');
    }

    public function test_the_checklist_shows_the_agreement_and_recording_status_of_the_school_with_links(): void
    {
        $school = School::factory()->create();
        $admin = $this->schoolAdmin($school);
        $this->actingAs($admin);

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))
            ->assertOk()
            ->assertSee('In der Plattform noch nicht akzeptiert.')
            ->assertSee('Aufnahmen sind für diese Schule ausgeschaltet.')
            ->assertSee('Aufnahmen & Einwilligungen')
            ->assertSee('href="'.route('legal.dpa').'"', false)
            ->assertSee('href="'.RecordingSettings::getUrl().'"', false);

        $school->acceptDpa($admin);
        $school->forceFill(['recordings_enabled_at' => now(), 'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true)])->save();
        $this->actingAs($admin->fresh());

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))
            ->assertOk()
            ->assertSee('In der Plattform akzeptiert am '.now()->format('d.m.Y').'.')
            ->assertSee('Aufnahmen sind für diese Schule eingeschaltet.');
    }

    /**
     * What the templates say about names follows the code: the evaluation of a submission, the homework draft and the
     * practice-task draft replace the names of the class and its teacher before the request (ClaudeLessonAssistant),
     * worksheet files and the "Erklär's mir" chat go as they stand. No template may still claim that answers leave the
     * platform unfiltered.
     */
    public function test_the_ai_statements_say_which_function_replaces_names(): void
    {
        $this->actingAs($this->schoolAdmin());

        foreach ([false, true] as $aiEnabled) {
            config(['services.anthropic.enabled' => $aiEnabled]);

            $dsfa = $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
                ->assertSee('Arbeitsblatt-Dateien')
                ->assertSee('ohne Ersetzung von Namen')
                ->assertSee('können Namen enthalten')
                ->assertSee('Bei der Auswertung einer Aufnahme ersetzt die Plattform')
                ->assertSee('bei der Auswertung einer Abgabe')
                // D1/Entscheidung A: die Auswertung einer Abgabe schickt nur Frage, Erwartungshorizont und Antworttext.
                ->assertSee('Bei der Auswertung einer Abgabe gehen nur Frage, Erwartungshorizont und Antworttext an den Anbieter')
                ->assertSee('keine Notizen der Lehrkraft, keine Zusammenfassungen, keine Arbeitsblatt-Dateien')
                ->assertDontSee('Vor jeder KI-Auswertung')
                ->assertDontSee('ohne Namen und Benutzerkennungen')
                ->assertDontSee('Für die KI gehen nur Texte, nie Audio, und nur pseudonymisiert')
                // Die frühere Aussage, Antworten gingen ungefiltert hinaus, ist widerlegt.
                ->assertDontSee('Notizen, Arbeitsblatt-Dateien, Antworten und Gespräche');

            $this->assertStringContainsString('Erklär’s mir', $dsfa->getContent());

            $verzeichnis = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()
                ->assertSee('Arbeitsblatt-Dateien')
                ->assertSee('Gespräche in „Erklär’s mir“');

            // The replacement of names is described in the third-country entry, which only exists while the AI is on.
            if ($aiEnabled) {
                $verzeichnis->assertSee('ersetzt die Plattform vorher die Namen der Klasse');
            } else {
                $verzeichnis->assertSee('Die KI-Anbindung ist ausgeschaltet');
            }
        }

        config(['services.anthropic.enabled' => true]);

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('Arbeitsblatt-Dateien')
            ->assertSee('ersetzt die Plattform vorher die Namen der Klasse')
            ->assertSee('keine Anonymisierung')
            ->assertDontSee('werden aber nicht entfernt')
            ->assertDontSee('ohne Namen und Benutzerkennungen')
            ->assertDontSee('nur mit geeigneten Garantien');
    }

    /**
     * What the templates claim about the consent check follows what starting a recording really does. The constant is
     * the lever and this test the guard: whoever wires RecordingConsentGate into the start of a recording flips it.
     */
    public function test_the_templates_claim_the_consent_check_only_while_starting_a_recording_enforces_it(): void
    {
        Storage::fake('local');
        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);
        [$schoolClass, , $teacher] = $this->classWithStudents($school);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();

        $startedWithoutAnyConsent = $this->actingAs($teacher)
            ->postJson(route('recordings.store', $lesson), ['consent' => true, 'mime_type' => 'audio/webm'])
            ->status() === 201;

        $this->assertSame(! $startedWithoutAnyConsent, PrivacyDocuments::CONSENT_GATE_ENFORCED, 'PrivacyDocuments::CONSENT_GATE_ENFORCED must say whether the start of a recording is refused for students without consent.');

        $this->actingAs($this->schoolAdmin());

        foreach (['dsfa', 'elterninformation', 'checkliste'] as $key) {
            $response = $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertOk();

            if (PrivacyDocuments::CONSENT_GATE_ENFORCED) {
                $response->assertDontSee('sperrt den Start einer Aufnahme');
            } else {
                $response
                    ->assertDontSee('Die Plattform lässt eine Aufnahme nur zu, wenn für alle anwesenden')
                    ->assertDontSee('prüft die Plattform vor jeder Aufnahme')
                    ->assertDontSee('Die Plattform prüft vor einer Aufnahme')
                    ->assertDontSee('Die Plattform prüft die Einwilligungen der anwesenden Schüler:innen');
            }
        }

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertSee('Sie führt keine Anwesenheitsliste');
    }

    public function test_recordings_stay_blocked_in_a_school_that_has_not_released_them(): void
    {
        Storage::fake('local');
        $school = School::factory()->create();
        $teacher = User::factory()->for($school)->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->getKey(), 'teacher_id' => $teacher->getKey()]);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();

        $this->actingAs($teacher)->postJson(route('recordings.store', $lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertForbidden();

        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertSee('vorher lässt die Plattform keine Aufnahme starten');
    }

    /**
     * The templates do not credit the platform with a reading step it does not enforce.
     */
    public function test_reading_the_transcript_is_an_instruction_and_not_a_platform_control(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertDontSee('Die Lehrkraft liest das Transkript vor der Auswertung und korrigiert es')
            ->assertDontSee('Transkripte werden vor der Auswertung gelesen')
            ->assertDontSee('Die Lehrkraft liest das Transkript, bevor es ausgewertet wird')
            ->assertSee('Die Plattform erzwingt das nicht; verbindlich macht es die Dienstanweisung der Schule')
            ->assertSee('Sie erzwingt nicht, dass die Lehrkraft das Transkript vor der Auswertung liest');

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))->assertOk()->assertSee('Transkripte vor der Auswertung lesen');
    }

    /**
     * Text carried from a recording into the notes follows the notes' deadline and can reach the AI provider
     * through the homework draft, whatever the parents agreed to for the AI evaluation.
     */
    public function test_the_templates_name_what_happens_to_text_carried_into_the_notes(): void
    {
        config(['lernplattform.retention.school_years_kept' => 2, 'lernplattform.recordings.keep_days' => 14]);
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()
            ->assertSee('folgt den Fristen der Notizen (2 Schuljahre)')
            ->assertSee('Lehne ich diesen Zweck ab, verwendet die Schule Text aus der Aufnahme auch nicht für die Hausaufgaben-Funktion mit KI.');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('nicht nach 14 Tagen gelöscht, sondern folgt den Fristen der Notizen (2 Schuljahre)')
            ->assertSee('geht dieser Text auch an den KI-Anbieter');

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('bindet das Übernehmen in die Notizen und den Hausaufgaben-Entwurf mit KI nicht an die Zustimmung zur KI-Auswertung');
    }

    public function test_the_special_categories_of_article_9_have_their_own_basis_and_explicit_consent(): void
    {
        $this->actingAs($this->schoolAdmin());

        $consent = $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($consent, 'darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO)'), 'Both variants, for parents and for staff, carry the explicit consent.');

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()->assertSee('ausdrückliche Einwilligung nach Art. 9 Abs. 2 lit. a DSGVO');
        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()->assertSee('Art. 9 Abs. 2 lit. a DSGVO');
        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Besondere Kategorien personenbezogener Daten möglich')
            ->assertSee('Art. 9 Abs. 2 lit. a');
    }

    public function test_the_parent_information_names_the_hosting_recipients_and_a_period_for_server_logs(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('technische Dienstleister als Unterauftragsverarbeiter')
            ->assertSee('Technische Protokolle des Servers')
            ->assertSee('löscht der Betreiber nach');

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()
            ->assertSee('Server-Logfiles und Fehlerprotokoll: Frist des Betreibers');
    }

    /**
     * The access and deletion sentences of the templates describe the policies as they are.
     */
    public function test_the_access_and_deletion_statements_match_the_policies(): void
    {
        Storage::fake('local');
        $school = $this->schoolWithRecordings();
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school);
        $admin = $this->schoolAdmin($school);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();
        $recording = LessonRecording::factory()->for($lesson)->create();

        // Only the recording teacher deletes through the platform; the administration does not.
        $this->assertTrue($teacher->can('delete', $recording));
        $this->assertFalse($admin->can('delete', $recording));

        // The lesson is reassigned: neither the former nor the new teacher can delete the recording.
        $successor = User::factory()->for($school)->teacher()->create();
        $lesson->teacher()->associate($successor)->save();
        $recording = $recording->fresh();
        $this->assertFalse($teacher->can('delete', $recording));
        $this->assertFalse($successor->can('delete', $recording));

        // The way out the templates name: the administration deletes the lesson, the recording goes with it.
        $this->assertTrue($admin->can('delete', $lesson));
        $lesson->delete();
        $this->assertDatabaseMissing('lesson_recordings', ['id' => $recording->getKey()]);

        // "Erklär's mir" on a freely chosen topic is private to the student, also towards teacher and administration.
        $session = ExplanationSession::factory()->create(['student_id' => $students->first()->getKey(), 'lesson_id' => null]);
        $this->assertTrue($students->first()->can('view', $session));
        $this->assertFalse($teacher->can('view', $session));
        $this->assertFalse($admin->can('view', $session));

        $this->actingAs($admin);

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertDontSee('kann alle Daten der Schule einsehen')
            ->assertSee('mit zwei Ausnahmen: Aufnahmen')
            ->assertSee('zu einem frei gewählten Thema sieht nur Ihr Kind');

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Nur die aufnehmende Lehrkraft kann eine Aufnahme über die Plattform löschen, solange sie die Stunde leitet')
            ->assertSee('wurde die Stunde einer anderen Lehrkraft zugewiesen')
            ->assertSee('Gespräche zu einem frei gewählten Thema: nur die Schüler:in selbst');

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))->assertOk()->assertSee('Löschen bei Abwesenheit der Lehrkraft geregelt');
    }

    /**
     * Browsers leave backgrounds out of a printout by default, so a ticked box must be drawn with borders.
     */
    public function test_a_ticked_box_does_not_depend_on_a_background_when_printed(): void
    {
        $this->actingAs($this->schoolAdmin());

        $html = $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()->getContent();
        $style = Str::before(Str::after($html, '/* The templates: one calm reading column'), '</style>');

        $this->assertStringContainsString('.pd-check.pd-on::after', $style);
        $this->assertStringNotContainsString('linear-gradient', $style);
        $this->assertStringContainsString('trifft zu', $html);
    }

    public function test_the_operator_sees_the_templates_without_data_of_any_school(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->actingAs(User::factory()->operator()->create());

        $response = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSee('Du siehst die Vorlagen ohne Angaben zu einer Schule')
            ->assertSee('Name der Schule')
            ->assertDontSee('Stand in der Plattform')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertDontSee($school->name);

        $this->assertStringContainsString('pd-blank', $this->articleOf($response->getContent()));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_teachers_and_students_get_no_access(Role $role): void
    {
        $user = User::factory()->{$role->value}()->create();
        $this->actingAs($user);

        $this->assertFalse(PrivacyDocuments::canAccess());

        foreach (['checkliste', 'dsfa', 'verzeichnis', 'elterninformation', 'einwilligung'] as $key) {
            $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(PrivacyDocuments::getUrl())->assertRedirect();
    }

    public function test_the_page_sits_in_the_menu_group_of_the_role(): void
    {
        $this->actingAs($this->schoolAdmin());
        $this->assertSame('Verwaltung', PrivacyDocuments::getNavigationGroup());
        $this->assertTrue(PrivacyDocuments::canAccess());

        $this->actingAs(User::factory()->operator()->create());
        $this->assertSame('Plattform', PrivacyDocuments::getNavigationGroup());
        $this->assertTrue(PrivacyDocuments::canAccess());
    }
}
