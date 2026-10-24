<?php

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\EnsureSchoolIsActive;
use App\Role;
use App\Support\DemoMode;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Login with a username instead of Filament's default email field.
 *
 * In demo mode (see App\Support\DemoMode) the form is prefilled with the seeded demo user's credentials (see
 * DatabaseSeeder) and the "Demo-Zugänge" below the form switch it to one of the other demo roles. On every other
 * instance the form stays empty and the list is not rendered at all.
 */
class Login extends BaseLogin
{
    /**
     * The seeded demo accounts, one per role.
     *
     * @var array<string, Role>
     */
    public const array DEMO_ACCOUNTS = [
        'hackathon' => Role::Operator,
        'schulleitung' => Role::Admin,
        'lehrer' => Role::Teacher,
        'schueler' => Role::Student,
    ];

    public const string BLOCKED_SCHOOL_MESSAGE = 'Deine Schule ist derzeit gesperrt. Bitte wende dich an die Plattform-Administration.';

    /**
     * Set once the password was right but the user's school is blocked, so only they learn why the login fails.
     */
    protected bool $schoolIsBlocked = false;

    /**
     * Whoever is still signed in after their school was blocked gets signed out here, because Filament's own
     * sign-out needs a valid session; the panel's middleware already does so and flashes the reason.
     */
    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
            session()->flash(EnsureSchoolIsActive::BLOCKED_SCHOOL_FLASH_KEY, true);
        }

        if (session()->has(EnsureSchoolIsActive::BLOCKED_SCHOOL_FLASH_KEY)) {
            $this->addError('data.username', self::BLOCKED_SCHOOL_MESSAGE);
        }

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->label('Benutzername')
                    ->default(fn (): ?string => DemoMode::isActive() ? 'hackathon' : null)
                    ->required()
                    ->autocomplete('username')
                    ->autofocus(),
                $this->getPasswordFormComponent()->default(fn (): ?string => DemoMode::isActive() ? DemoMode::password() : null),
                $this->getRememberFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        // Read the password only while demo mode is on: DemoMode::password() throws otherwise, and the view stays hidden.
        $demoPassword = DemoMode::isActive() ? DemoMode::password() : '';

        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
                View::make('filament.pages.auth.demo-accounts')
                    ->viewData(['accounts' => self::DEMO_ACCOUNTS, 'password' => $demoPassword])
                    ->visible(fn (): bool => DemoMode::isActive() && blank($this->userUndertakingMultiFactorAuthentication)),
            ]);
    }

    /**
     * Filament's own "forgot password" hint is skipped by the Tab key (tabindex -1), so it is rebuilt without that.
     */
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Passwort')
            ->hint(new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()">Passwort vergessen?</x-filament::link>')));
    }

    /**
     * Fills the form with one of the demo accounts; unknown usernames are ignored and so is every call while
     * demo mode is off.
     */
    public function fillDemoAccount(string $username): void
    {
        if (! DemoMode::isActive()) {
            return;
        }

        if (! array_key_exists($username, self::DEMO_ACCOUNTS)) {
            return;
        }

        $this->form->fill(['username' => $username, 'password' => DemoMode::password()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{username: string, password: string}
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        $isAllowed = parent::isUserAllowedToAccessPanel($user);

        $this->schoolIsBlocked = ! $isAllowed && $user->school?->isBlocked();

        return $isAllowed;
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => $this->schoolIsBlocked ? self::BLOCKED_SCHOOL_MESSAGE : __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    /**
     * The brand sits on the exercise-book page next to the card (see login-intro view), not inside it.
     */
    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Willkommen zurück!';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('Melde dich an und mach dort weiter, wo du aufgehört hast. Noch kein Konto? '.$this->registerAction->toHtml().'<br>Neue Schule? '.$this->registerSchoolAction->toHtml());
    }

    public function registerSchoolAction(): Action
    {
        return Action::make('registerSchool')
            ->link()
            ->label('Schule registrieren')
            ->url(route('filament.lernplattform.auth.register-school'));
    }

    public function registerAction(): Action
    {
        return parent::registerAction()->label('Mit Klassencode registrieren');
    }
}
