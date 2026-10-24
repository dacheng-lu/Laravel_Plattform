<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legalPages(): array
    {
        return [
            'imprint' => ['legal.imprint'],
            'privacy' => ['legal.privacy'],
        ];
    }

    #[DataProvider('legalPages')]
    public function test_guests_can_open_the_legal_pages(string $routeName): void
    {
        $this->get(route($routeName))->assertOk();
    }

    #[DataProvider('legalPages')]
    public function test_logged_in_users_can_open_the_legal_pages(string $routeName): void
    {
        foreach (['operator', 'admin', 'teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_the_legal_pages_live_next_to_the_panel_without_taking_its_routes(): void
    {
        $this->assertSame('/impressum', route('legal.imprint', absolute: false));
        $this->assertSame('/datenschutzerklaerung', route('legal.privacy', absolute: false));
        $this->get('/login')->assertOk();
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_set_no_cookies(string $routeName): void
    {
        $this->get(route($routeName))
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_imprint_names_the_operator_and_the_legal_basics(): void
    {
        $this->get(route('legal.imprint'))
            ->assertOk()
            ->assertSee('<h1>Impressum</h1>', false)
            ->assertSee('Angaben gemäß § 5 DDG')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('0157 32599053')
            ->assertSee('info@sebastian-bleuel.de')
            ->assertSee('DE370290830')
            ->assertSee('§ 18 Abs. 2 MStV')
            ->assertSee('weder bereit noch verpflichtet')
            ->assertSee('Haftung für Inhalte')
            ->assertSee('Wir übernehmen keine Gewähr dafür')
            ->assertSee('Haftung für Links')
            ->assertSee('Urheberrecht')
            ->assertSee('Verordnung (EU) 2022/2065')
            ->assertDontSee('§§ 8 bis 10 DDG')
            ->assertDontSee('farmmanager');
    }

    public function test_the_privacy_policy_names_the_controller_and_the_authority(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('<h1>Datenschutzerklärung</h1>', false)
            ->assertSee('Stand: Oktober 2026')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('info@sebastian-bleuel.de')
            ->assertSee('Art. 28 DSGVO')
            ->assertSee('§ 25 Abs. 2 Nr. 2 TDDDG')
            ->assertSee('Hessische Beauftragte für Datenschutz und Informationsfreiheit')
            ->assertDontSee('farmmanager');
    }

    public function test_the_privacy_policy_lists_every_cookie_and_storage_key_the_panel_uses(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('<code>'.config('session.cookie').'</code>', false)
            ->assertSee('<code>XSRF-TOKEN</code>', false)
            ->assertSee('remember_web_')
            ->assertSee('filament:theme:mode')
            ->assertSee('filament:sidebar:open')
            ->assertSee('filament:sidebar:desktop_open')
            ->assertSee('filament:sidebar:collapsed_groups')
            ->assertSee('filament.notifications.redirect')
            ->assertSee('Sitzungsspeicher')
            ->assertSee(config('session.lifetime').' Minuten');
    }

    public function test_the_privacy_policy_says_that_the_login_page_already_starts_a_session(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('schon, wenn Sie die Anmeldeseite')
            ->assertSee('auch ohne Konto')
            ->assertSee('IP-Adresse, die Browserangabe und den Zeitpunkt der letzten Aktivität');
    }

    public function test_the_privacy_policy_makes_no_claim_about_log_retention_the_code_cannot_back(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Fehlerprotokoll')
            ->assertDontSee('wenige Tage');
    }

    public function test_the_privacy_policy_covers_the_password_reset_mail(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Passwort zurücksetzen')
            ->assertSee('password_reset_tokens')
            ->assertSee('Der Link gilt 60 Minuten.')
            ->assertSee('Vertrags nach Art. 28 DSGVO');
    }

    public function test_the_privacy_policy_covers_the_student_import_and_the_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Import einer Schülerliste')
            ->assertSee('einmalig im Klartext')
            ->assertSee('die Schule, zu der das Konto gehört');
    }

    public function test_the_privacy_policy_covers_the_registration_of_a_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Registrierung einer Schule')
            ->assertSee('Eine Schule registriert sich.')
            ->assertSee('die Daten der Schule: Name, Ort und Bundesland')
            ->assertSee('Kontakt-E-Mail-Adresse der Schule')
            ->assertSee('die Daten des ersten Kontos der Schuladministration')
            ->assertSee('Schulnummer')
            ->assertSee('Gesperrt kann sich niemand der')
            ->assertSee('Art. 6 Abs. 1 lit. b DSGVO, soweit die Schule mit uns die Nutzung der')
            ->assertSee('Für die Angaben, die eine Schule bei ihrer Registrierung macht')
            ->assertSee('Daten der Schule bei der Registrierung:');
    }

    public function test_the_privacy_policy_describes_what_the_operator_can_and_cannot_see(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('<h3>Plattform-Administration</h3>', false)
            ->assertSee('Zugänge von Schuladministrationen anlegen')
            ->assertSee('das gemeinsame Kompetenzraster pflegen')
            ->assertSee('Das sieht sie in der Plattform nicht:')
            ->assertSee('Die Plattform-Administration, die die Plattform')
            ->assertSee('Diesen Zugriff nutzen wir nur dafür und nicht, um Unterrichts- oder')
            ->assertSee('Rolle: Plattform-Administration, Schuladministration, Lehrkraft oder Schüler:in');
    }

    public function test_the_privacy_policy_scopes_what_the_administration_sees_to_its_own_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Schuladministration (nur die eigene Schule)')
            ->assertSee('alle Konten und Klassen der Schule')
            ->assertSee('nur die Konten der Schuladministrationen')
            ->assertSee('Die Daten verschiedener Schulen sind voneinander getrennt.')
            ->assertSee('Schulen sehen nichts')
            ->assertDontSee('Administration der Plattform')
            ->assertDontSee('Die Administration sieht alle');
    }

    public function test_the_who_sees_what_table_has_a_cell_for_every_role_in_every_row(): void
    {
        $html = $this->get(route('legal.privacy'))->getContent();

        preg_match('/<caption>Wer welche Daten sehen kann<\/caption>(.+?)<\/table>/s', $html, $table);
        preg_match_all('/<th scope="col">(.+?)<\/th>/', $table[1], $headers);
        preg_match('/<tbody>(.+?)<\/tbody>/s', $table[1], $body);
        $rows = array_filter(explode('</tr>', $body[1]), fn (string $row): bool => str_contains($row, '<th scope="row">'));

        $this->assertSame(['Daten', 'Schüler:in', 'Lehrkraft', 'Schuladministration (nur die eigene Schule)', 'Plattform-Administration'], $headers[1]);
        $this->assertCount(8, $rows);

        foreach ($rows as $row) {
            $this->assertSame(4, substr_count($row, '<td>'), $row);
        }
    }

    public function test_the_privacy_policy_and_the_panel_agree_on_self_registration(): void
    {
        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

        $this->assertSame(
            Filament::getPanel('lernplattform')->hasRegistration(),
            str_contains($html, 'Schüler:innen registrieren sich selbst.'),
            'The privacy policy must describe self-registration exactly when the panel offers it.',
        );
        $this->assertStringNotContainsString('Eine Selbstregistrierung gibt es nicht', $html);
        $this->assertStringNotContainsString('Es gibt keine Selbstregistrierung', $html);
    }

    public function test_the_privacy_policy_and_the_panel_agree_on_external_requests(): void
    {
        $panelHtml = $this->actingAs(User::factory()->student()->create())->get('/')->assertOk()->getContent();
        $ownHost = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('/<(?:link|script|img|iframe|source)\b[^>]*\b(?:src|href)="(?:https?:)?\/\/([^\/":]+)/', $panelHtml, $matches);
        $externalHosts = array_values(array_unique(array_filter($matches[1], fn (string $host): bool => $host !== $ownHost)));

        $policy = $this->get(route('legal.privacy'))->getContent();

        $this->assertSame(
            in_array('ui-avatars.com', $externalHosts, true),
            str_contains($policy, 'ui-avatars.com'),
            'The privacy policy must name ui-avatars.com exactly when the panel loads the avatar from there.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($externalHosts, ['ui-avatars.com'])),
            'The privacy policy knows no external host other than the avatar service.',
        );
    }

    public function test_the_privacy_table_of_contents_matches_the_numbered_headings(): void
    {
        $html = $this->get(route('legal.privacy'))->getContent();

        preg_match_all('/<li><a href="#([a-z-]+)">(.+?)<\/a><\/li>/u', $html, $contents, PREG_SET_ORDER);
        preg_match_all('/<h2 id="([a-z-]+)">(\d+)\. (.+?)<\/h2>/u', $html, $headings, PREG_SET_ORDER);

        $this->assertCount(14, $contents);
        $this->assertCount(14, $headings);

        foreach ($contents as $index => [, $id, $title]) {
            $this->assertSame($id, $headings[$index][1]);
            $this->assertSame((string) ($index + 1), $headings[$index][2]);
            $this->assertSame($title, $headings[$index][3]);
        }
    }

    public function test_the_privacy_policy_explains_the_mistake_type_of_an_answer(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('Fehlerart')
            ->assertSee('Grundidee noch unklar')
            ->assertSee('Weg/Schritt falsch')
            ->assertSee('Demo-Regeln, KI oder Lehrkraft')
            ->assertSee('nicht zur Person')
            ->assertSee('erscheint nicht im Elternbericht');
    }

    public function test_the_privacy_policy_says_the_ai_connection_is_off_while_it_is_disabled(): void
    {
        config(['services.anthropic.enabled' => false]);

        $this->get(route('legal.privacy'))
            ->assertSee('Die KI-Anbindung ist abgeschaltet.')
            ->assertSee('werden keine Daten an Dritte übermittelt')
            ->assertSee('Anthropic PBC')
            ->assertDontSee('Die KI-Anbindung ist aktiviert.');
    }

    public function test_the_privacy_policy_says_the_ai_connection_is_on_while_it_is_enabled(): void
    {
        config(['services.anthropic.enabled' => true]);

        $this->get(route('legal.privacy'))
            ->assertSee('Die KI-Anbindung ist aktiviert.')
            ->assertSee('Anthropic PBC')
            ->assertSee('Namen, Benutzernamen und Benutzerkennungen werden nicht übermittelt.')
            ->assertSee('Eine Kopie der')
            ->assertDontSee('wurde erst eingeschaltet')
            ->assertDontSee('Die KI-Anbindung ist abgeschaltet.');
    }

    public function test_the_legal_pages_link_to_each_other_and_to_the_login(): void
    {
        $imprintLink = 'href="'.route('legal.imprint').'"';
        $privacyLink = 'href="'.route('legal.privacy').'"';
        $loginLink = 'href="'.url('/').'"';

        $this->get(route('legal.imprint'))
            ->assertSee($privacyLink, false)
            ->assertSee($imprintLink, false)
            ->assertSee($loginLink, false);

        $this->get(route('legal.privacy'))
            ->assertSee($imprintLink, false)
            ->assertSee($privacyLink, false)
            ->assertSee($loginLink, false);
    }

    #[DataProvider('legalPages')]
    public function test_the_footer_marks_the_current_page(string $routeName): void
    {
        $html = $this->get(route($routeName))->getContent();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route($routeName), '/').'"\s+aria-current="page"/',
            $html,
        );
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_load_nothing_from_other_hosts(string $routeName): void
    {
        $html = $this->get(route($routeName))->getContent();
        $ownHost = preg_quote(parse_url(url('/'), PHP_URL_HOST), '/');

        $this->assertStringContainsString('<html lang="de"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:link|script|img|iframe|source)\b[^>]*\b(?:src|href)="(?:https?:)?\/\/(?!'.$ownHost.'[:\/"])/',
            $html,
        );
    }
}
