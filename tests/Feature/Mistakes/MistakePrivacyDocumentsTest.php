<?php

namespace Tests\Feature\Mistakes;

use App\Filament\Pages\PrivacyDocuments;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four privacy texts (Datenschutzerklärung, Verzeichnis, Folgenabschätzung, Elterninformation) name the mistake
 * type the way the code keeps it: what is stored per answer, what reaches an AI provider, who sees it and when it
 * disappears. Every page carries a positive control, so an empty render cannot pass as a hit.
 */
class MistakePrivacyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Every page with the phrases that prove it is really rendered and with the statements it must carry about the
     * mistake type.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function pages(): array
    {
        return [
            'Datenschutzerklärung' => [
                'legal.privacy',
                ['Datenschutzerklärung', 'Unterrichts- und Lerndaten', 'Erklär’s mir'],
                [
                    'Fehlerart',
                    'Grundidee noch unklar',
                    'Weg/Schritt falsch',
                    'Demo-Regeln, KI oder Lehrkraft',
                    'KI-Hinweis, kann falsch sein',
                    'nicht zur Person',
                    'erscheint nicht im Elternbericht',
                    'Die Fehlerart wird mit der Antwort gelöscht',
                ],
            ],
            'Verzeichnis' => [
                'privacy-documents.verzeichnis',
                ['Eintrag für das Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)', 'Erklär’s mir'],
                [
                    'Fehlerart der Antworten',
                    'Weg/Schritt falsch',
                    'Demo-Regeln, KI oder Lehrkraft',
                    'KI-Hinweis, kann falsch sein',
                    'nicht im Bericht für das Elterngespräch',
                    'Die Fehlerart einer Antwort wird mit der Antwort gelöscht',
                    'Datenauskunft nennt Fehlerart und Quelle',
                ],
            ],
            'Folgenabschätzung' => [
                'privacy-documents.dsfa',
                ['Datenschutz-Folgenabschätzung (Art. 35 DSGVO)', 'Erklär’s mir'],
                [
                    'Fehlerart einer Antwort (Hinweis)',
                    'Weg/Schritt falsch',
                    'Mindestsicherheit',
                    'KI-Hinweis, kann falsch sein',
                    'nicht im Elternbericht',
                    'Lehrkraft kann sie korrigieren',
                ],
            ],
            'Elterninformation' => [
                'privacy-documents.elterninformation',
                ['Elterninformation zur Lernplattform (Art. 13 DSGVO)', 'Erklär’s mir'],
                [
                    'Fehlerart',
                    'Grundidee noch unklar',
                    'Weg/Schritt falsch',
                    'nicht im Bericht für das Elterngespräch',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $rendered
     * @param  list<string>  $statements
     */
    #[DataProvider('pages')]
    public function test_every_privacy_text_names_the_mistake_type(string $page, array $rendered, array $statements): void
    {
        $response = $this->open($page)->assertOk();

        // Positivkontrolle: die Seite steht wirklich da, bevor nach der Fehlerart gesucht wird.
        foreach ($rendered as $phrase) {
            $response->assertSee($phrase);
        }

        foreach ($statements as $statement) {
            $response->assertSee($statement);
        }
    }

    public function test_the_texts_say_what_of_the_mistake_type_reaches_the_ai_provider(): void
    {
        config(['services.anthropic.enabled' => true]);

        // Die Einordnung geht mit Frage, Erwartungshorizont und Antworttext hinaus, die Namen der Klasse und der
        // Lehrkraft ersetzt die Plattform vorher; Arbeitsblätter gehen unverändert mit.
        foreach (['privacy-documents.dsfa', 'privacy-documents.verzeichnis'] as $page) {
            $this->open($page)
                ->assertOk()
                ->assertSee('Fehlerart')
                ->assertSee('Platzhalter')
                ->assertSee('Arbeitsblatt');
        }

        $this->open('privacy-documents.elterninformation')
            ->assertOk()
            ->assertSee('Fehlerart')
            ->assertSee('Platzhalter')
            ->assertSee('Arbeitsblätter')
            ->assertSee('können Namen enthalten');
    }

    public function test_the_privacy_policy_names_the_ai_provider_only_while_the_connection_is_on(): void
    {
        config(['services.anthropic.enabled' => false]);

        $this->open('legal.privacy')
            ->assertOk()
            ->assertSee('Fehlerart')
            ->assertSee('Die KI-Anbindung ist abgeschaltet.')
            ->assertDontSee('Die KI-Anbindung ist aktiviert.');

        config(['services.anthropic.enabled' => true]);

        $this->open('legal.privacy')
            ->assertOk()
            ->assertSee('Die KI-Anbindung ist aktiviert.')
            ->assertSee('Platzhalter');
    }

    /**
     * Opens one of the pages: the public privacy policy as a guest, the templates as the administration of a school.
     */
    private function open(string $page): TestResponse
    {
        if ($page === 'legal.privacy') {
            return $this->get(route('legal.privacy'));
        }

        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        return $this->get(PrivacyDocuments::getUrl(['document' => Str::after($page, 'privacy-documents.')]));
    }
}
