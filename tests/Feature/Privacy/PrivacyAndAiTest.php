<?php

namespace Tests\Feature\Privacy;

use App\Filament\Pages\PrivacyAndAi;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivacyAndAiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return [
            'Plattform-Administration' => [Role::Operator],
            'Schuladministration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAdministration(): array
    {
        return [
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    private function userWith(Role $role): User
    {
        return User::factory()->{$role->value}()->create();
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_the_page(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Datenschutz & KI')
            ->assertSee('Keine Mitschnitte')
            ->assertSee('Wer sieht das?')
            ->assertSee('keine Note')
            ->assertSee('Schulverwaltung');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/datenschutz')->assertRedirect();
    }

    public function test_the_page_has_no_navigation_group_and_sits_at_the_end(): void
    {
        $this->assertNull(PrivacyAndAi::getNavigationGroup());
        $this->assertGreaterThanOrEqual(100, PrivacyAndAi::getNavigationSort());
    }

    #[DataProvider('roles')]
    public function test_every_role_sees_the_role_list_and_that_schools_are_separate(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Jede Schule für sich')
            ->assertSee('Schulen sehen nichts voneinander')
            ->assertSee(Role::Operator->getLabel())
            ->assertSee(Role::Admin->getLabel())
            ->assertSee(Role::Teacher->getLabel())
            ->assertSee(Role::Student->getLabel())
            ->assertSee('Sieht keine Unterrichts- und Lerndaten')
            ->assertSee('Schuladministration (eigene Schule)')
            ->assertSee('Plattform-Administration (nur Schuladministrationen)')
            ->assertSee('Plattform-Administration (alle Schulen)')
            ->assertSee('Schule und Registrierung')
            ->assertSee('Ergebnisse ihrer Schule');
    }

    #[DataProvider('roles')]
    public function test_every_role_learns_that_a_mistake_type_is_stored_and_what_it_is_not(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Abgaben und KI-Einschätzung')
            ->assertSee('Fehlerart einer nicht ganz richtigen Antwort und ihre Quelle (Demo-Regeln, KI oder Lehrkraft)')
            ->assertSee('Die Fehlerart ist nur ein Hinweis zur Antwort, nicht zur Person')
            ->assertSee('Sie ändert keine Punktzahl und erscheint nicht im Elternbericht');
    }

    #[DataProvider('roles')]
    public function test_the_ai_is_described_as_not_active_while_it_is_disabled(Role $role): void
    {
        config(['services.anthropic.enabled' => false, 'services.anthropic.model' => 'claude-test-modell']);

        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('nicht aktiv')
            ->assertSee('Demo-Logik')
            ->assertDontSee('Anthropic (Claude)')
            ->assertDontSee('claude-test-modell');
    }

    public function test_the_enabled_ai_names_provider_and_model_without_sending_a_request(): void
    {
        Http::fake();
        config(['services.anthropic.enabled' => true, 'services.anthropic.model' => 'claude-test-modell']);

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('KI-Anbindung aktiv')
            ->assertSee('Anthropic')
            ->assertSee('claude-test-modell')
            ->assertSee('Namen, Benutzernamen oder Benutzer-IDs')
            ->assertDontSee('nicht aktiv');

        Http::assertNothingSent();
    }

    public function test_the_operator_gets_the_setup_and_the_checklist_for_the_whole_platform(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Für die Plattform-Administration')
            ->assertSee('für alle Schulen')
            ->assertSee('ANTHROPIC_ENABLED=true')
            ->assertSee('ANTHROPIC_API_KEY')
            ->assertSee('ANTHROPIC_MODEL')
            ->assertSee('Auftragsverarbeitungsvertrag')
            ->assertSee('Datenschutz-Folgenabschätzung')
            ->assertSee('Eltern und Schüler:innen informiert')
            ->assertSee('Personalrat')
            ->assertSee('keine Rechtsberatung')
            ->assertDontSee('Für die Schuladministration');
    }

    public function test_the_school_administration_gets_the_checklist_but_no_server_setup(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Für die Schuladministration')
            ->assertSee('von der Plattform-Administration geschaltet, nicht von einzelnen Schulen')
            ->assertSee('Auftragsverarbeitungsvertrag')
            ->assertSee('Datenschutz-Folgenabschätzung')
            ->assertSee('Eltern und Schüler:innen informiert')
            ->assertSee('Personalrat')
            ->assertSee('keine Rechtsberatung')
            ->assertDontSee('Für die Plattform-Administration')
            ->assertDontSee('ANTHROPIC_ENABLED=true')
            ->assertDontSee('ANTHROPIC_API_KEY');
    }

    #[DataProvider('rolesWithoutAdministration')]
    public function test_the_administration_sections_are_hidden_from_other_roles(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertDontSee('Für die Schuladministration')
            ->assertDontSee('Für die Plattform-Administration')
            ->assertDontSee('ANTHROPIC_API_KEY')
            ->assertDontSee('Auftragsverarbeitungsvertrag')
            ->assertDontSee('Personalrat');
    }

    public function test_only_students_get_the_tips_for_their_own_data(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get('/datenschutz')
            ->assertSee('Tipps für dich')
            ->assertSee('Du darfst deine Lehrkraft jederzeit fragen');

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertDontSee('Tipps für dich');
    }

    public function test_students_are_warned_that_texts_reach_the_ai_once_it_is_enabled(): void
    {
        $student = User::factory()->student()->create();

        config(['services.anthropic.enabled' => false]);
        $this->actingAs($student)->get('/datenschutz')->assertDontSee('zur Auswertung an die KI gesendet');

        config(['services.anthropic.enabled' => true]);
        $this->actingAs($student)->get('/datenschutz')->assertSee('zur Auswertung an die KI gesendet');
    }

    public function test_the_stated_score_ranges_match_the_understanding_levels(): void
    {
        $this->assertSame(UnderstandingLevel::Understood, UnderstandingLevel::fromScore(75));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(74));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(50));
        $this->assertSame(UnderstandingLevel::NeedsSupport, UnderstandingLevel::fromScore(49));

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertSee('ab 75 von 100')
            ->assertSee('50 bis 74 von 100')
            ->assertSee('unter 50 von 100')
            ->assertSee(UnderstandingLevel::NeedsSupport->getLabel());
    }
}
