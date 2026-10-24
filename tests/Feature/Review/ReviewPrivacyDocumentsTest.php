<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\PrivacyDocuments;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Heute wiederholen" belongs in every data protection text: the privacy policy (Art. 13/14), the record of processing
 * activities (Art. 30), the parents' information and the impact assessment. Each text is checked for the repeat data
 * and, as a positive control in the same response, for content that was already there – so a missing sentence can never
 * hide behind an empty or broken page.
 */
class ReviewPrivacyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Every template that has to mention the repeat cards, with a heading that already existed in it.
     *
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'Verzeichnis' => ['verzeichnis', 'A: Nutzung der Lernplattform im Unterricht'],
            'Elterninformation' => ['elterninformation', 'Welche Daten werden verarbeitet?'],
            'Folgenabschätzung' => ['dsfa', 'Systematische Beschreibung der Verarbeitung'],
        ];
    }

    public function test_the_privacy_policy_explains_the_repeat_cards(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            // Positivkontrolle: der bestehende Abschnitt steht in derselben Antwort.
            ->assertSee('Erklär’s mir')
            ->assertSee('Was gespeichert wird')
            // Der neue Abschnitt: was gespeichert wird und was gerade nicht.
            ->assertSee('Heute wiederholen')
            ->assertSee('Wiederholungsstufe')
            ->assertSee('nicht gewusst')
            ->assertSee('Nicht gespeichert werden die Antwort, die beim Wiederholen')
            ->assertSee('Für das Wiederholen setzt die Plattform keine KI ein.')
            // Wer sieht was: eine eigene Zeile ohne Einsicht für Lehrkraft und Schulleitung.
            ->assertSee('Wiederholungskarten mit Selbsteinschätzung')
            ->assertSee('keine Einzelwerte')
            // Übung bleibt Übung: kein Weg in Punkte, Lernstand, Note oder Elternbericht.
            ->assertSee('Sie fließt nicht in den')
            ->assertSee('nicht im Bericht für Elterngespräche')
            // Löschung mit Stunde und Konto.
            ->assertSee('Sie hängen an der Frage und verschwinden mit');
    }

    #[DataProvider('documents')]
    public function test_every_template_names_the_repeat_cards(string $key, string $existingHeading): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => $key]))
            ->assertOk()
            // Positivkontrolle: ein bestehendes Kapitel und ein bestehender Begriff stehen in derselben Antwort.
            ->assertSee($existingHeading)
            ->assertSee('Erklär’s mir')
            // Und die Wiederholung ist mit den Daten benannt, die der Code wirklich speichert.
            ->assertSee('Heute wiederholen')
            ->assertSee('Wiederholungsstufe');
    }

    public function test_the_record_of_processing_names_the_repeat_data_its_recipients_and_its_deletion(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSee('Wiederholungsstufe je Frage (Box 1 bis 5)')
            ->assertSee('nicht die beim Wiederholen eingetippte Antwort')
            ->assertSee('Die Wiederholungskarten mit Selbsteinschätzung sieht nur die Schülerin bzw. der Schüler selbst')
            ->assertSee('Unterrichtsstunden mit Abgaben, Gesprächen und Wiederholungskarten');
    }

    public function test_the_parent_information_says_what_the_practice_stores_and_who_sees_it(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Beim Üben („Heute wiederholen“)')
            ->assertSee('Wiederholungsstufe 1 bis 5')
            ->assertSee('Was Ihr Kind beim Wiederholen eintippt, wird nicht gespeichert')
            ->assertSee('Die Einschätzung beim Wiederholen („Heute wiederholen“) sieht nur Ihr Kind.')
            ->assertSee('Wiederholungskarten verschwinden mit der Unterrichtsstunde');
    }

    public function test_the_impact_assessment_weighs_the_repeat_cards(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))
            ->assertOk()
            ->assertSee('Wiederholungskarten („Heute wiederholen“)')
            ->assertSee('Keine KI beteiligt.')
            ->assertSee('Nur die Schüler:in selbst.')
            // Datenminimierung und Zweckbindung als Maßnahmen.
            ->assertSee('Beim Wiederholen wird die eingetippte Antwort nicht gespeichert.')
            ->assertSee('keine einzelnen Versuche und keine Antwortzeiten')
            ->assertSee('Die Selbsteinschätzung beim Wiederholen sieht nur die Schüler:in selbst');
    }

    private function schoolAdmin(): User
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'city' => 'Kassel']);

        return User::factory()->for($school)->admin()->create();
    }
}
