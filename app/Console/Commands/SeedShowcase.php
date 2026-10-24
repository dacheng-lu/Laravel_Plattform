<?php

namespace App\Console\Commands;

use App\Support\Showcase\InvalidShowcase;
use App\Support\Showcase\ShowcaseImporter;
use Illuminate\Console\Command;

/**
 * Fills the demo school with showcase data from a JSON file (schema version 1, see
 * database/seeders/demo/showcase.json).
 *
 * The command only runs where a demo may be written to: local, testing, staging and demo. Anywhere else it stops
 * before reading the file, so a deploy that runs it by mistake cannot touch real school data. A file that cannot be
 * imported as a whole is reported with the path of the offending field, and nothing is written.
 */
final class SeedShowcase extends Command
{
    protected $signature = 'lernplattform:seed-showcase {--path= : JSON-Datei, Standard database/seeders/demo/showcase.json}';

    protected $description = 'Füllt die Demo-Schule mit Vorführdaten (nur local/testing/staging/demo).';

    /**
     * @var array<string, string> the label of every area in the result of the importer
     */
    private const array AREAS = [
        'teachers' => 'Lehrkräfte',
        'classes' => 'Klassen',
        'students' => 'Schüler:innen',
        'lessons' => 'Unterrichtsstunden',
        'questions' => 'Fragen',
        'submissions' => 'Abgaben',
        'answers' => 'Antworten',
        'review_items' => 'Wiederholungen',
        'explanation_sessions' => 'Erklär\'s-mir-Gespräche',
        'consents' => 'Einwilligungen',
    ];

    public function handle(ShowcaseImporter $importer): int
    {
        if (! app()->environment(['local', 'testing', 'staging', 'demo'])) {
            $this->error('Der Showcase-Import läuft nur in local, testing, staging oder demo.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: database_path('seeders/demo/showcase.json'));

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Die Datei {$path} fehlt oder ist nicht lesbar.");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            $this->error("Die Datei {$path} enthält kein gültiges JSON-Objekt.");

            return self::FAILURE;
        }

        try {
            $counts = $importer->import($data, today());
        } catch (InvalidShowcase $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach (self::AREAS as $area => $label) {
            $rows[] = [$label, $counts[$area] ?? 0];
        }

        $this->table(['Bereich', 'Neu angelegt'], $rows);

        return self::SUCCESS;
    }
}
