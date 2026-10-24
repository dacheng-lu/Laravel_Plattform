# Lernplattform

Webbasierte Lernplattform für die SloppifAI-Ausbildung. Learner melden sich mit
Benutzername und Passwort an und arbeiten danach in ihrem persönlichen
Lernbereich weiter.

## Stack

- PHP 8.3+ / Laravel 13
- Filament 5 (Panel `lernplattform`, Login unter `/login`)
- Vite 8, Tailwind CSS 4
- PHPUnit 12 (kein Pest), Laravel Pint für den Code-Stil
- Datenbank: SQLite lokal, MySQL/PostgreSQL im Betrieb

## Lokales Setup

```bash
composer run setup
composer run dev
```

`composer run setup` installiert die PHP- und Node-Abhängigkeiten, legt `.env`
aus `.env.example` an, erzeugt den App-Key, migriert die Datenbank und baut die
Frontend-Assets. `composer run dev` startet den Entwicklungsserver inklusive
Asset-Watcher.

Der Login erwartet einen Benutzernamen, keine E-Mail-Adresse. Ein Benutzer kann
über Filament oder per Seeder angelegt werden (siehe Demo-Login).

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
| `DEMO_MODE` | Demo-Login aktivieren, Standard `false` | `false` |
| `DEMO_USERNAME` | Benutzername des Demo-Zugangs | `hackathon` |
| `DEMO_PASSWORD` | Passwort des Demo-Zugangs, ohne Wert kein Demo-Login | – |

### Demo-Login

Nur wenn `DEMO_MODE=true` gesetzt ist **und** `DEMO_PASSWORD` einen Wert hat,
belegt das Login-Formular die Zugangsdaten vor und der Seeder legt den
Demo-Benutzer an. Fehlt eines von beidem, bleibt das Formular leer und es wird
kein Benutzer erzeugt (fail closed).

> Warnung: Demo-Login niemals in einer Produktionsumgebung mit echten Learnern
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
`DEMO_MODE` sowie `DEMO_PASSWORD` per `php artisan db:seed --force` anlegen.

## Continuous Integration

`.github/workflows/tests.yml` läuft bei jedem Push und jedem Pull Request:
PHP 8.4, Node 22, `composer install`, Asset-Build, `vendor/bin/pint --test` und
`php artisan test`. Ein Pull Request gilt erst als grün, wenn Stilprüfung und
Tests durchlaufen.
