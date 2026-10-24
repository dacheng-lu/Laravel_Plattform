<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_forged_forwarded_host_does_not_end_up_in_generated_urls(): void
    {
        $this->withHeaders(['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertOk()
            ->assertDontSee('evil.test');
    }

    public function test_asset_urls_use_https_behind_a_tls_terminating_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertOk()
            ->assertSee('src="https://', false)
            ->assertDontSee('src="http://', false);
    }

    public function test_the_seeded_default_user_can_log_in_with_username(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'hackathon2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('Mein Lernbereich')->assertSee('Neueste Registrierungen');
    }

    public function test_the_login_form_is_prefilled_with_the_demo_credentials(): void
    {
        $this->enableDemoMode();

        Livewire::test(Login::class)
            ->assertFormSet(['username' => 'hackathon', 'password' => 'hackathon2026']);
    }

    public function test_the_login_form_is_empty_when_demo_mode_is_off(): void
    {
        config(['lernplattform.demo.enabled' => false, 'lernplattform.demo.password' => 'hackathon2026']);

        Livewire::test(Login::class)
            ->assertFormSet(['username' => null, 'password' => null]);
    }

    public function test_demo_mode_without_a_password_stays_off(): void
    {
        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => '']);

        Livewire::test(Login::class)
            ->assertFormSet(['username' => null, 'password' => null]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Demo-Zugänge');
    }

    public function test_the_login_page_introduces_the_platform_and_lists_the_demo_accounts(): void
    {
        $this->enableDemoMode();

        $this->get('/login')
            ->assertOk()
            ->assertSee('Vom Unterricht zur Hausaufgabe zum Verstehen.')
            ->assertSee('Demo-Zugänge')
            ->assertSeeInOrder(['Plattform-Administration', 'hackathon', 'Schuladministration', 'schulleitung', 'Lehrkraft', 'lehrer', 'Schüler:in', 'schueler'])
            ->assertSee('hackathon2026');
    }

    public function test_the_demo_accounts_are_hidden_when_demo_mode_is_off(): void
    {
        config(['lernplattform.demo.enabled' => false, 'lernplattform.demo.password' => 'hackathon2026']);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Demo-Zugänge')
            ->assertDontSee('hackathon2026');
    }

    public function test_a_demo_account_fills_the_login_form(): void
    {
        $this->enableDemoMode();

        Livewire::test(Login::class)
            ->call('fillDemoAccount', 'lehrer')
            ->assertFormSet(['username' => 'lehrer', 'password' => 'hackathon2026'])
            ->call('fillDemoAccount', 'jemand-anderes')
            ->assertFormSet(['username' => 'lehrer'])
            ->call('fillDemoAccount', 'schulleitung')
            ->assertFormSet(['username' => 'schulleitung', 'password' => 'hackathon2026']);
    }

    public function test_fill_demo_account_does_nothing_when_demo_mode_is_off(): void
    {
        config(['lernplattform.demo.enabled' => false, 'lernplattform.demo.password' => 'hackathon2026']);

        Livewire::test(Login::class)
            ->call('fillDemoAccount', 'lehrer')
            ->assertFormSet(['username' => null]);
    }

    public function test_the_demo_accounts_see_the_start_page_of_their_role(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        $startPages = [
            'hackathon' => ['Neueste Registrierungen', 'Schuladministrationen'],
            'schulleitung' => ['Abgaben diese Woche', 'Unterrichtsstunden'],
            'lehrer' => ['Deine letzten Unterrichtsstunden'],
            'schueler' => ['Hallo Mia!'],
        ];

        foreach ($startPages as $username => $expectedTexts) {
            // A new session per account: AuthenticateSession would log out a second user in the same session.
            $this->flushSession();
            $this->actingAs(User::query()->where('username', $username)->sole())
                ->get('/')
                ->assertOk()
                ->assertSee($expectedTexts);
        }
    }

    public function test_the_font_is_served_locally_instead_of_from_a_font_cdn(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('fonts/lexend/lexend.css')
            ->assertDontSee('fonts.bunny.net');

        $this->assertFileExists(public_path('fonts/lexend/lexend-latin-wght-normal.woff2'));
    }

    public function test_the_login_page_links_to_imprint_and_privacy_policy(): void
    {
        Route::get('/impressum', fn (): string => 'Impressum')->name('legal.imprint');
        Route::get('/datenschutzerklaerung', fn (): string => 'Datenschutz')->name('legal.privacy');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/login')
            ->assertOk()
            ->assertSee(route('legal.imprint'))
            ->assertSee('Impressum')
            ->assertSee(route('legal.privacy'))
            ->assertSee('Datenschutzerklärung');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->enableDemoMode();

        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'falsch'])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest();
    }

    /**
     * Demo logins are off unless the instance switches them on; every test that expects the prefilled form or the
     * demo account list has to turn them on explicitly.
     */
    private function enableDemoMode(): void
    {
        config(['lernplattform.demo.enabled' => true, 'lernplattform.demo.password' => 'hackathon2026']);
    }
}
