# Lernplattform

Webbasierte Lernplattform für Schulen: Lehrkräfte stellen Inhalte aus dem
Unterricht bereit, Schülerinnen und Schüler bearbeiten sie in ihrem persönlichen
Lernbereich.

## Stack

- PHP 8.3+ / Laravel 13
- Filament 5 (Panel `lernplattform`, Login unter `/login`)
- Vite 8, Tailwind CSS 4
- PHPUnit 12 (kein Pest), Laravel Pint für den Code-Stil
- Datenbank: SQLite lokal, MySQL/MariaDB im Betrieb (das Image installiert pdo_mysql)

## Lokales Setup

```bash
composer run setup
composer run dev
```

`composer run setup` installiert die PHP- und Node-Abhängigkeiten, legt `.env`
aus `.env.example` an, erzeugt den App-Key, migriert die Datenbank und baut die
Frontend-Assets. `composer run dev` startet den Entwicklungsserver inklusive
Asset-Watcher.

Der Login erwartet einen Benutzernamen, keine E-Mail-Adresse. Eine
Benutzerverwaltung gibt es noch nicht; Benutzer werden derzeit per
`php artisan tinker` oder – nur für Demos – per Seeder angelegt.

## Tests und Code-Stil

```bash
composer test
vendor/bin/pint
vendor/bin/pint --test
```

Die Tests laufen mit einer In-Memory-SQLite-Datenbank. `vendor/bin/pint --test`
prüft den Stil ohne zu schreiben, `vendor/bin/pint` korrigiert ihn.

## Umgebungsvariablen

| Variable | Zweck | Beispiel |
| --- | --- | --- |
| `APP_KEY` | Verschlüsselungsschlüssel, Pflicht | `php artisan key:generate` |
| `APP_ENV` | Laufzeitumgebung | `production` |
| `APP_DEBUG` | Debug-Ausgaben, im Betrieb `false` | `false` |
| `APP_URL` | Basis-URL der Anwendung | `https://lernplattform.example` |
| `APP_LOCALE` | UI-Sprache, Standard `de` | `de` |
| `APP_FAKER_LOCALE` | Faker-Locale für Testdaten | `de_DE` |
| `DB_CONNECTION` | Datenbanktreiber | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Zugang zur Datenbank | `db`, `3306`, `lernplattform`, … |
| `DEMO_MODE` | Demo-Login aktivieren, Standard `false`; nur bei `APP_ENV` = `local`, `testing`, `staging` oder `demo` möglich | `false` |
| `DEMO_USERNAME` | Benutzername des Demo-Zugangs | `hackathon` |
| `DEMO_PASSWORD` | Passwort des Demo-Zugangs, ohne Wert kein Demo-Login | – |

### Demo-Login

Nur wenn `DEMO_MODE` aktiv ist **und** `DEMO_USERNAME` sowie `DEMO_PASSWORD`
einen Wert haben, belegt das Login-Formular die Zugangsdaten vor und der Seeder
legt den Demo-Benutzer an. Fehlt eines davon, bleibt das Formular leer und es
wird kein Benutzer erzeugt (fail closed). `DEMO_MODE` akzeptiert `true`, `1`,
`on` und `yes`; jeder andere Wert bedeutet aus.

Der Demo-Modus ist nur bei `APP_ENV` = `local`, `testing`, `staging` oder `demo`
möglich; bei jedem anderen Wert (auch `production`) bleibt er aus.
Demo-Instanzen laufen mit `APP_ENV=staging` oder `APP_ENV=demo`.

Ein bereits vorhandener Benutzer mit diesem Namen wird nicht verändert.

> Warnung: Demo-Login niemals in einer Produktionsumgebung mit echten Lernenden
> aktivieren. Die Zugangsdaten stehen im Klartext in der Umgebung und der
> Zugang ist öffentlich erreichbar.

Demo-Benutzer anlegen:

```bash
php artisan db:seed --force
```

## Deployment (Coolify)

Das Repository enthält ein `Dockerfile` auf Basis von
`serversideup/php:8.4-fpm-nginx`; die Assets werden in einem Node-Build-Stage
erzeugt und ins finale Image kopiert. In Coolify genügt ein Dockerfile-Build
mit den Umgebungsvariablen aus der Tabelle oben.

Das Image setzt `AUTORUN_ENABLED=true`, dadurch führen die Laravel-Automations
von serversideup bei jedem Container-Start Migrationen und den
Konfigurations-Cache aus. Den Demo-Benutzer nur bewusst und nur mit gesetztem
`DEMO_MODE`, `DEMO_USERNAME` und `DEMO_PASSWORD` per `php artisan db:seed --force`
anlegen.

Migrationen laufen bei jedem Start automatisch. Destruktive Migrationen vor dem
Merge prüfen; bei mehreren Replikas Migrationen nur in einer Instanz ausführen.

## Continuous Integration

`.github/workflows/tests.yml` läuft bei jedem Push und jedem Pull Request:
PHP 8.4, Node 22, `composer install`, Asset-Build, `vendor/bin/pint --test` und
`php artisan test`. Der Status erscheint am Pull Request; als Pflicht-Check muss
er per Branch-Protection aktiviert werden.
