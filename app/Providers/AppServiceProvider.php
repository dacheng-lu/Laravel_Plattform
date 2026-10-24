<?php

namespace App\Providers;

use App\Ai\ClaudeLessonAssistant;
use App\Ai\ClaudeMistakeClassifier;
use App\Ai\ClaudeRecordingAnalyst;
use App\Ai\DemoLessonAssistant;
use App\Ai\DemoMistakeClassifier;
use App\Ai\DemoRecordingAnalyst;
use App\Ai\DemoTranscriber;
use App\Ai\LessonAssistant;
use App\Ai\MistakeClassifier;
use App\Ai\RecordingAnalyst;
use App\Ai\SpeechToTextTranscriber;
use App\Ai\Transcriber;
use App\Support\SchoolDay;
use Filament\Forms\Components\Select;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LessonAssistant::class, fn (): LessonAssistant => config('services.anthropic.enabled')
            ? $this->app->make(ClaudeLessonAssistant::class)
            : $this->app->make(DemoLessonAssistant::class));

        // Recordings: audio goes to a speech-to-text provider only with TRANSCRIPTION_ENABLED, the transcript text to Claude only with ANTHROPIC_ENABLED.
        $this->app->bind(Transcriber::class, fn (): Transcriber => config('services.transcription.enabled')
            ? $this->app->make(SpeechToTextTranscriber::class)
            : $this->app->make(DemoTranscriber::class));
        $this->app->bind(RecordingAnalyst::class, fn (): RecordingAnalyst => config('services.anthropic.enabled')
            ? $this->app->make(ClaudeRecordingAnalyst::class)
            : $this->app->make(DemoRecordingAnalyst::class));

        // Mistake types: Claude names them in-band while it evaluates a submission, the offline rules stand in otherwise.
        $this->app->bind(MistakeClassifier::class, fn (): MistakeClassifier => config('services.anthropic.enabled')
            ? $this->app->make(ClaudeMistakeClassifier::class)
            : $this->app->make(DemoMistakeClassifier::class));

        // The school day ("Heute wiederholen") is a calendar day in the school's time zone. Scoped, so the zone is
        // resolved once per request or job: a broken setting is reported once, and a worker never carries the zone of
        // an earlier job into the next one. See App\Support\SchoolDay.
        $this->app->scoped(SchoolDay::BINDING, fn (): string => SchoolDay::resolveTimezone());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No table scrolls sideways on phones: below md every row becomes a card with labelled values (see "Tables stacked below md" in theme.css).
        Table::configureUsing(fn (Table $table): Table => $table->stackedOnMobile());

        // One select style everywhere: Filament's searchable JavaScript select instead of the browser's native one.
        Select::configureUsing(fn (Select $select): Select => $select->native(false));
        SelectFilter::configureUsing(fn (SelectFilter $filter): SelectFilter => $filter->native(false));
        SelectColumn::configureUsing(fn (SelectColumn $column): SelectColumn => $column->native(false));

        // The parents' consent page: a small limit per link and IP, and a generous ceiling per IP only against abuse, because a whole
        // parents' evening can sit behind one school Wi-Fi address and revoking has to stay as easy as consenting.
        RateLimiter::for('consent', fn (Request $request): array => [
            Limit::perMinute(30)->by('link:'.sha1((string) $request->route('token')).'|'.$request->ip()),
            Limit::perMinute(240)->by('ip:'.$request->ip()),
        ]);

        // Read aloud and dictation run in the pupil's browser only; teachers, administration and operators never get the script.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): View|string => auth()->user()?->isStudent() ? view('filament.lernplattform.speech') : '',
        );
    }
}
