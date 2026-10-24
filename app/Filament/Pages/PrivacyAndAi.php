<?php

namespace App\Filament\Pages;

use App\Role;
use App\UnderstandingLevel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Plain-language transparency page for every role: what is stored, who sees it (within the own school only) and what the AI does.
 * The "who sees what" cards describe app/Policies; tests/Feature/Privacy/PrivacyAndAiTest keeps the AI status in sync with the config.
 */
class PrivacyAndAi extends Page
{
    protected static ?string $slug = 'datenschutz';

    protected static ?string $title = 'Datenschutz & KI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 100;

    protected ?string $subheading = 'Was die Lernplattform speichert, wer es sieht und was die KI damit macht.';

    protected string $view = 'filament.pages.privacy-and-ai';

    public static function isAiEnabled(): bool
    {
        return (bool) config('services.anthropic.enabled');
    }

    /**
     * The AI status as a callout above the page text, so it is the first thing anyone reads.
     */
    public function content(Schema $schema): Schema
    {
        $callout = Callout::make(self::isAiEnabled() ? 'KI-Anbindung aktiv' : 'KI-Anbindung vorbereitet, aber nicht aktiv');

        return $schema->components([
            self::isAiEnabled()
                ? $callout
                    ->description('Zur Auswertung werden Texte und Arbeitsblatt-Dateien an Anthropic (Claude, Modell '.$this->model().') gesendet. Namen und Benutzer-IDs gehören nicht dazu.')
                    ->warning()
                    ->icon(Heroicon::OutlinedCloudArrowUp)
                : $callout
                    ->description('Alle Auswertungen laufen offline mit einer regelbasierten Demo-Logik. Es verlassen keine Daten die Plattform.')
                    ->success()
                    ->icon(Heroicon::OutlinedShieldCheck),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'aiEnabled' => self::isAiEnabled(),
            'model' => $this->model(),
            'isAdmin' => $user?->isAdmin() ?? false,
            'isOperator' => $user?->isOperator() ?? false,
            'isStudent' => $user?->isStudent() ?? false,
            'roles' => Role::cases(),
            'dataCategories' => self::dataCategories(),
            'levels' => self::levels(),
        ];
    }

    /**
     * Mirrors the fallback of ClaudeLessonAssistant, so the page names the model that is really used.
     */
    private function model(): string
    {
        return config('services.anthropic.model') ?: 'claude-opus-5-5';
    }

    /**
     * What is stored per area and which roles see it, in line with the policies in app/Policies.
     *
     * @return list<array{title: string, icon: Heroicon, stored: string, viewers: list<array{role: Role, label: string, icon: Heroicon}>, notes: list<string>}>
     */
    private static function dataCategories(): array
    {
        $teacher = ['role' => Role::Teacher, 'label' => 'Lehrkraft der Stunde', 'icon' => Heroicon::OutlinedAcademicCap];
        $admin = ['role' => Role::Admin, 'label' => 'Schuladministration (eigene Schule)', 'icon' => Heroicon::OutlinedKey];
        $operator = ['role' => Role::Operator, 'icon' => Heroicon::OutlinedBuildingLibrary];
        $student = ['role' => Role::Student, 'icon' => Heroicon::OutlinedBookOpen];

        return [
            [
                'title' => 'Unterrichtsnotizen und Arbeitsblätter',
                'icon' => Heroicon::OutlinedDocumentText,
                'stored' => 'Fach, Thema, Datum, Notizen oder Transkript, hochgeladene Arbeitsblätter und die Zusammenfassung der Lehrkraft. Das alles trägt die Lehrkraft selbst ein.',
                'viewers' => [$teacher, $admin],
                'notes' => ['Schüler:innen sehen weder Notizen noch Arbeitsblätter, sondern nur die fertige Zusammenfassung der Lehrkraft, und die erst nach ihrer Abgabe.'],
            ],
            [
                'title' => 'Hausaufgaben',
                'icon' => Heroicon::OutlinedClipboardDocumentList,
                'stored' => 'Die Aufgaben mit der zugeordneten Kompetenz. Die KI macht einen Entwurf, die Lehrkraft prüft und verteilt ihn an die Klasse oder an ausgewählte Schüler:innen.',
                'viewers' => [$teacher, $admin, [...$student, 'label' => 'Zugewiesene Schüler:innen']],
                'notes' => [
                    'Schüler:innen sehen eine Hausaufgabe erst, wenn sie veröffentlicht und ihnen zugewiesen ist.',
                    'Der Erwartungshorizont, also was eine gute Antwort enthält, ist nur für Lehrkraft und Schuladministration sichtbar.',
                ],
            ],
            [
                'title' => 'Abgaben und KI-Einschätzung',
                'icon' => Heroicon::OutlinedChartBar,
                'stored' => 'Die eigene Zusammenfassung, die Antworten, der Verständniswert von 0 bis 100, das Feedback und Hinweise, was noch wiederholt werden sollte. Dazu die Fehlerart einer nicht ganz richtigen Antwort und ihre Quelle (Demo-Regeln, KI oder Lehrkraft).',
                'viewers' => [[...$student, 'label' => 'Schüler:in selbst'], $teacher, $admin],
                'notes' => [
                    'Die Fehlerart ist nur ein Hinweis zur Antwort, nicht zur Person: Sie ändert keine Punktzahl und erscheint nicht im Elternbericht.',
                    'Andere Schüler:innen sehen eine Abgabe nie.',
                ],
            ],
            [
                'title' => '„Erklär’s mir“-Gespräche',
                'icon' => Heroicon::OutlinedChatBubbleLeftRight,
                'stored' => 'Das gewählte Thema, der Gesprächsverlauf mit der KI sowie Verständniswert und Feedback.',
                'viewers' => [[...$student, 'label' => 'Schüler:in selbst'], $teacher, $admin],
                'notes' => ['Lehrkräfte sehen nur Gespräche, die zu einer ihrer Stunden gehören. Gespräche zu einem frei gewählten Thema sieht keine Lehrkraft.'],
            ],
            [
                'title' => 'Kontodaten',
                'icon' => Heroicon::OutlinedUserCircle,
                'stored' => 'Name, Benutzername, E-Mail-Adresse, Rolle, Schule und Klasse. Das Passwort wird nur verschlüsselt (als Hash) gespeichert.',
                'viewers' => [$admin, [...$operator, 'label' => 'Plattform-Administration (nur Schuladministrationen)']],
                'notes' => [
                    'Lehrkräfte sehen die Namen der Schüler:innen aus der Klasse ihrer Stunde, um Hausaufgaben zu verteilen und Abgaben zuzuordnen.',
                    'Wird ein Schülerkonto gelöscht, werden auch seine Abgaben und Gespräche gelöscht.',
                ],
            ],
            [
                'title' => 'Schule und Registrierung',
                'icon' => Heroicon::OutlinedBuildingLibrary,
                'stored' => 'Name, Ort und Bundesland der Schule, auf Wunsch Schulnummer, Schulform und Kontakt-E-Mail-Adresse, dazu der Zeitpunkt der Registrierung und einer möglichen Sperrung.',
                'viewers' => [$admin, [...$operator, 'label' => 'Plattform-Administration (alle Schulen)']],
                'notes' => ['Eine gesperrte Schule kann die Plattform nicht mehr nutzen. Ihre Daten bleiben gespeichert, bis sie gelöscht werden oder die Sperre aufgehoben wird.'],
            ],
        ];
    }

    /**
     * Where each understanding level starts. Keep in sync with UnderstandingLevel::fromScore(), which the test checks.
     *
     * @return list<array{level: UnderstandingLevel, range: string}>
     */
    private static function levels(): array
    {
        return [
            ['level' => UnderstandingLevel::Understood, 'range' => 'ab 75 von 100'],
            ['level' => UnderstandingLevel::Partly, 'range' => '50 bis 74 von 100'],
            ['level' => UnderstandingLevel::NeedsSupport, 'range' => 'unter 50 von 100'],
        ];
    }
}
