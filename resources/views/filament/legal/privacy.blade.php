@extends('filament.legal.layout')

@php
    use App\Models\User;
    use Filament\AvatarProviders\UiAvatarsProvider;
    use Filament\Facades\Filament;
    use Filament\Models\Contracts\HasAvatar;

    /*
     * One list drives the table of contents. The headings below carry the same number and title;
     * tests/Feature/LegalPagesTest keeps both in sync.
     */
    $sections = [
        'verantwortlicher' => 'Verantwortlicher',
        'ueberblick' => 'Überblick',
        'hosting' => 'Hosting und Server-Logfiles',
        'cookies' => 'Cookies und lokaler Speicher',
        'benutzerkonten' => 'Schulen und Benutzerkonten',
        'lerndaten' => 'Unterrichts- und Lerndaten',
        'ki' => 'Einsatz von KI',
        'schriftarten' => 'Schriftarten und externe Inhalte',
        'kontakt' => 'Kontakt per E-Mail',
        'speicherdauer' => 'Speicherdauer',
        'kinder' => 'Kinder und Jugendliche',
        'rechte' => 'Rechte der betroffenen Personen',
        'beschwerde' => 'Beschwerderecht',
        'aenderungen' => 'Änderungen dieser Erklärung',
    ];

    $aiEnabled = (bool) config('services.anthropic.enabled');
    $sessionMinutes = (int) config('session.lifetime');
    $recordingDays = (int) config('lernplattform.recordings.keep_days');
    $keptYears = (int) config('lernplattform.retention.school_years_kept');
    $keptPhrase = match (true) {
        $keptYears <= 1 => 'das laufende Schuljahr',
        $keptYears === 2 => 'das laufende und das vorherige Schuljahr',
        default => 'das laufende und die '.($keptYears - 1).' vorherigen Schuljahre',
    };

    /*
     * Filament's default avatar provider builds an https://ui-avatars.com URL, so the browser asks that host for
     * the initials image. The text below follows the panel's real setting: it discloses the request while the panel
     * makes it and says nothing once the panel serves the avatar itself.
     */
    $externalAvatars = Filament::getPanel('lernplattform')->getDefaultAvatarProvider() === UiAvatarsProvider::class
        && ! is_subclass_of(User::class, HasAvatar::class);
@endphp

@section('title', 'Datenschutzerklärung')
@section('description', 'Wie die Lernplattform personenbezogene Daten verarbeitet und welche Rechte Sie haben')

@section('content')
    <h1>Datenschutzerklärung</h1>
    <p><strong>Stand: Oktober 2026</strong></p>
    <p>
        Diese Erklärung informiert Sie nach Art. 13 und 14 DSGVO darüber, welche personenbezogenen Daten auf der
        Lernplattform verarbeitet werden, wozu das geschieht und welche Rechte Sie haben. Sie richtet sich an
        Schüler:innen, Eltern, Lehrkräfte und die Verwaltung.
    </p>

    <div class="legal-note">
        <p class="legal-note-title">Demo und Pilot</p>
        <p>
            Die Plattform läuft derzeit als Demo und Pilot und enthält ausschließlich erfundene Daten. Bitte geben Sie
            keine echten personenbezogenen Daten ein.
        </p>
    </div>

    <nav class="legal-toc" aria-labelledby="inhaltsverzeichnis">
        <p class="legal-note-title" id="inhaltsverzeichnis">Inhalt</p>
        <ol>
            @foreach ($sections as $id => $title)
                <li><a href="#{{ $id }}">{{ $title }}</a></li>
            @endforeach
        </ol>
    </nav>

    <h2 id="verantwortlicher">1. Verantwortlicher</h2>
    <p>
        <strong>Demo- und Pilotbetrieb.</strong> Für die Verarbeitung personenbezogener Daten auf dieser Plattform ist
        derzeit verantwortlich im Sinne der DSGVO:
    </p>
    <address>
        Sebastian Bleuel<br>
        Softwareentwicklung Sebastian Bleuel<br>
        Ortesweg 10<br>
        36145 Hofbieber<br>
        Deutschland<br>
        Telefon: <a href="tel:+4915732599053">0157 32599053</a><br>
        E-Mail: <a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a>
    </address>
    <p>Weitere Angaben zum Anbieter finden Sie im <a href="{{ route('legal.imprint') }}">Impressum</a>.</p>
    <p>
        <strong>Regulärer Schulbetrieb.</strong> Setzt eine Schule die Lernplattform im Unterricht ein, ist die Schule
        beziehungsweise der Schulträger verantwortlich, weil sie über Zwecke und Mittel der Verarbeitung entscheiden. Wir
        verarbeiten die Daten dann als Auftragsverarbeiter nach Art. 28 DSGVO, auf Grundlage eines Vertrags zur
        Auftragsverarbeitung und nur nach Weisung der Schule. Erste Ansprechpartnerin für Fragen zum Datenschutz ist dann
        die Schule. Ihre Kontaktdaten und die ihrer Datenschutzbeauftragten stehen in den Datenschutzhinweisen der
        Schule.
    </p>
    <p>
        <strong>Registrierung einer Schule.</strong> Für die Angaben, die eine Schule bei ihrer Registrierung macht (die
        Stammdaten der Schule und der Nachweis, dass sie den Vertrag zur Auftragsverarbeitung angenommen hat), und
        für die Verwaltung der Schulen auf der Plattform (Abschnitt 5) sind wir selbst verantwortlich. Wir schließen damit
        die Nutzung der Plattform mit der Schule ab und halten die Plattform sicher. Das Konto der Schuladministration
        selbst, auch das erste, ist ein Konto der Schule: Dessen Daten verarbeiten wir im Auftrag der Schule.
    </p>

    <h2 id="ueberblick">2. Überblick</h2>
    <p>
        Die Lernplattform unterstützt das Lernen. Lehrkräfte halten fest, was sie im Unterricht behandelt haben. Die
        Plattform entwirft daraus passende Hausaufgaben. Schüler:innen sehen, was sie schon verstanden haben und wo sie
        noch üben sollten.
    </p>
    <ul>
        <li>
            Die Plattform zeichnet keinen Unterricht von sich aus auf, und es gibt kein Video. Lehrkräfte tragen selbst
            Notizen, ein Tafelbild oder einen Transkripttext ein und können Arbeitsblätter als Foto, Scan oder PDF
            hochladen. Nur wenn die Schule das optionale Aufnahme-Modul freischaltet und die nötigen Einwilligungen
            vorliegen, kann eine Lehrkraft den Ton einer Stunde aufnehmen. Eine Aufnahme sieht nur die aufnehmende
            Lehrkraft, und sie wird nach {{ $recordingDays }} Tagen gelöscht. Was dabei verarbeitet wird, steht im
            <a href="{{ route('legal.dpa') }}">Vertrag zur Auftragsverarbeitung</a> (Anlage 1).
        </li>
        <li>
            Es gibt kein Tracking: keine Reichweitenmessung, keine Werbung und keine Schriftarten von fremden Servern.
            @if ($externalAvatars)
                Nur das Platzhalter-Bild neben dem Namen kommt von einem fremden Server (siehe Abschnitt 8).
            @else
                Es werden auch keine Inhalte von Dritten eingebettet.
            @endif
        </li>
        <li>
            Jede Schule registriert sich selbst auf der Plattform. Alles, was zu einer Schule gehört, also Klassen,
            Konten, Unterrichtsstunden, Abgaben und Gespräche, sehen nur Personen dieser Schule. Schulen sehen nichts
            voneinander (siehe Abschnitt 5 und 6).
        </li>
        <li>
            Schüler:innen können sich mit dem Klassencode ihrer Lehrkraft selbst registrieren. Konten für Lehrkräfte und
            weitere Personen legt die Schuladministration der Schule an (siehe Abschnitt 5).
        </li>
        <li>Zum Einsatz von KI und zu den Verständniswerten lesen Sie bitte Abschnitt 7.</li>
    </ul>

    <aside class="legal-note" aria-labelledby="kurz-fuer-schueler">
        <p class="legal-note-title" id="kurz-fuer-schueler">Kurz erklärt für Schüler:innen</p>
        <ul>
            <li>
                Die Plattform kennt deinen Namen, deinen Benutzernamen, deine Klasse und dein Passwort. Dein Passwort wird
                nur in verschlüsselter Form (als Hash) gespeichert, die Plattform kann es nicht nachlesen. Hat die
                Schuladministration deiner Schule dein Konto angelegt, hat sie dein Startpasswort einmal gesehen. Ändere es dann in deinem
                Profil.
            </li>
            <li>
                Eine E-Mail-Adresse musst du nicht angeben. Wir brauchen sie nur, um dir einen Link zu schicken, wenn du
                dein Passwort vergessen hast.
            </li>
            <li>
                Alles, was du abgibst, wird gespeichert: deine Zusammenfassungen, deine Antworten und deine Gespräche mit
                Frieda. Deine Lehrkraft sieht deine Abgaben und den Verlauf der Gespräche, die zu ihren Stunden gehören.
                Lehrkräfte, die deine Klasse leiten oder unterrichten, sehen außerdem deine Punktwerte und zu jedem
                Gespräch Thema, Punktwert und Rückmeldung, auch wenn du dir das Thema selbst ausgesucht hast. Auch die
                Schuladministration deiner Schule kann alles sehen. Die Plattform-Administration, die die Plattform
                betreut, sieht deine Daten nicht. Andere Schüler:innen und andere Schulen sehen es nie.
            </li>
            <li>Frieda ist eine Computerfigur und kein Mensch.</li>
            <li>
                Der Verständniswert von 0 bis 100 ist keine Note. Er zeigt nur, wo du vielleicht noch Hilfe brauchen
                kannst. Deine Lehrkraft entscheidet, was daraus folgt.
            </li>
            <li>
                Schreib bitte keine privaten Dinge hinein, zum Beispiel deine Adresse, deine Telefonnummer oder Fotos von
                dir.
            </li>
            <li>
                Du und deine Eltern dürft jederzeit fragen, welche Daten über dich gespeichert sind, und verlangen, dass
                sie gelöscht werden. Frag dafür deine Lehrkraft oder deine Schule.
            </li>
        </ul>
    </aside>

    <h2 id="hosting">3. Hosting und Server-Logfiles</h2>
    <h3>Hosting</h3>
    <p>
        Die Plattform läuft auf eigenen beziehungsweise angemieteten Servern in Deutschland. Sie wird als Docker-Container
        betrieben und mit Coolify bereitgestellt, die Daten liegen in einer MariaDB-Datenbank. Beim Hosting werden keine
        Daten in Länder außerhalb der EU übermittelt. Soweit ein externer Betreiber die Server bereitstellt, verarbeitet er
        Daten nur nach unserer Weisung und auf Grundlage eines Vertrags nach Art. 28 DSGVO.
    </p>
    <h3>Server-Logfiles und Fehlerprotokoll</h3>
    <p>
        Bei jedem Aufruf der Plattform verarbeitet der Webserver technisch notwendige Daten: die IP-Adresse, Datum und
        Uhrzeit, die aufgerufene Adresse, den Statuscode, die übertragene Datenmenge sowie Angaben zu Browser und
        Betriebssystem. Wir brauchen sie, um die Seiten auszuliefern, den Betrieb stabil zu halten, Fehler zu finden und
        Angriffe abzuwehren.
    </p>
    <p>
        Auch die Plattform selbst schreibt ein Protokoll mit technischen Fehlermeldungen. Es dient der Fehlersuche. Tritt
        ein Fehler auf, können darin Angaben zur betroffenen Anfrage stehen, zum Beispiel die Benutzerkennung.
    </p>
    <p>
        Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse ist ein sicherer und fehlerfreier
        Betrieb. Wir bewahren die Logdaten nur so lange auf, wie wir sie für Betrieb, Fehlersuche und Abwehr von Angriffen
        brauchen, und löschen sie dann. Bei einem Sicherheitsvorfall bewahren wir die betroffenen Einträge so lange auf,
        bis er geklärt ist.
    </p>

    <h2 id="cookies">4. Cookies und lokaler Speicher</h2>
    <p>
        Die Plattform verwendet nur technisch notwendige Cookies sowie Einträge im lokalen Speicher und im Sitzungsspeicher
        Ihres Browsers. Sie sind nötig, damit die Anmeldung funktioniert und Formulare sicher abgeschickt werden können,
        oder sie merken sich Einstellungen zur Darstellung. Cookies für Analyse, Reichweitenmessung oder Werbung gibt es
        nicht. Die Seiten Impressum und Datenschutzerklärung setzen selbst keine Cookies.
    </p>
    <p>
        Das Speichern und Auslesen solcher Informationen ist nach § 25 Abs. 2 Nr. 2 TDDDG ohne Einwilligung erlaubt, weil
        es unbedingt erforderlich ist, um den von Ihnen ausdrücklich gewünschten Dienst bereitzustellen. Da nichts
        Optionales eingesetzt wird, ist kein Cookie-Banner nötig. Die dabei anfallende Verarbeitung personenbezogener
        Daten stützt sich auf Art. 6 Abs. 1 lit. f DSGVO, im Schulbetrieb auf lit. e (siehe Abschnitt 5).
    </p>
    <p>
        Das Sitzungs-Cookie und das XSRF-Cookie setzt die Plattform schon, wenn Sie die Anmeldeseite, die Registrierung
        oder die Seite „Passwort vergessen“ öffnen, also auch ohne Konto. Zur Sitzung speichert der Server in der
        Datenbank die Sitzungskennung, die IP-Adresse, die Browserangabe und den Zeitpunkt der letzten Aktivität, bis die
        Sitzung nach {{ $sessionMinutes }} Minuten ohne Aktivität abläuft. Das dient der Sicherheit und dem Betrieb der
        Anmeldung (Art. 6 Abs. 1 lit. f DSGVO).
    </p>

    <h3>Cookies</h3>
    <div class="legal-table-wrap" role="region" aria-label="Cookies der Plattform" tabindex="0">
        <table class="legal-table">
            <caption>Cookies der Plattform</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Zweck</th>
                    <th scope="col">Gültigkeit</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><code>{{ config('session.cookie') }}</code></th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Wird gesetzt, sobald Sie die Anmeldeseite, die Registrierung oder die Seite „Passwort vergessen“
                        öffnen, und hält nach der Anmeldung Ihre Anmeldung aufrecht. Das Cookie enthält nur eine zufällige
                        Sitzungskennung. Die Sitzung selbst liegt in der Datenbank auf dem Server.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> {{ $sessionMinutes }} Minuten nach der letzten Aktivität, spätestens bis zur Abmeldung</td>
                </tr>
                <tr>
                    <th scope="row"><code>XSRF-TOKEN</code></th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Schützt vor gefälschten Anfragen (Cross-Site-Request-Forgery). Formulare und Aktionen werden nur
                        angenommen, wenn sie von der Plattform selbst ausgelöst wurden.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> wie das Sitzungs-Cookie</td>
                </tr>
                <tr>
                    <th scope="row"><code>remember_web_</code> mit angehängter Kennung</th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Nur wenn Sie bei der Anmeldung „Angemeldet bleiben“ ankreuzen. Speichert eine verschlüsselte
                        Kennung, damit Sie ohne neue Anmeldung wiedererkannt werden.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> bis zu 400 Tage, spätestens bis zur Abmeldung</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>
        Auf gemeinsam genutzten Geräten, zum Beispiel in der Schule, sollten Sie „Angemeldet bleiben“ nicht wählen.
    </p>

    <h3>Lokaler Speicher und Sitzungsspeicher des Browsers</h3>
    <div class="legal-table-wrap" role="region" aria-label="Einträge im Browser-Speicher" tabindex="0">
        <table class="legal-table">
            <caption>Einträge im lokalen Speicher und im Sitzungsspeicher des Browsers</caption>
            <thead>
                <tr>
                    <th scope="col">Schlüssel</th>
                    <th scope="col">Speicher</th>
                    <th scope="col">Zweck</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><code>filament:theme:mode</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Ihre Wahl zwischen hellem, dunklem oder vom System vorgegebenem Erscheinungsbild</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament:sidebar:open</code>, <code>filament:sidebar:desktop_open</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Ob die Seitenleiste geöffnet oder eingeklappt ist</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament:sidebar:collapsed_groups</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Welche Gruppen der Seitenleiste eingeklappt sind</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament.notifications.redirect</code></th>
                    <td><span class="legal-cell-label">Speicher</span> Sitzungsspeicher</td>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Merkt sich kurz eine Benachrichtigung über einen Seitenwechsel hinweg und wird danach wieder
                        entfernt
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>
        Diese Einträge bleiben in Ihrem Browser und werden nicht an unseren Server gesendet. Die Einträge im lokalen
        Speicher bleiben bestehen, bis Sie die Website-Daten Ihres Browsers löschen. Der Eintrag im Sitzungsspeicher
        verschwindet spätestens, wenn Sie den Tab schließen. Impressum und Datenschutzerklärung lesen die Einstellung zum
        Erscheinungsbild nur aus, um hell oder dunkel passend anzuzeigen. Sie schreiben nichts in den Browser-Speicher.
    </p>
    <p>
        Sie können Cookies und Website-Daten jederzeit in Ihrem Browser löschen oder blockieren. Ohne das
        Sitzungs-Cookie ist keine Anmeldung möglich.
    </p>

    <h2 id="benutzerkonten">5. Schulen und Benutzerkonten</h2>
    <p>
        Wer die Plattform nutzen will, braucht ein Konto, und jedes Konto gehört zu einer Schule. Nur die
        Plattform-Administration gehört zu keiner Schule (siehe unten). Ein Konto entsteht auf einem von drei Wegen:
    </p>
    <ul>
        <li>
            <strong>Eine Schule registriert sich.</strong> Dabei entstehen die Schule auf der Plattform und das erste
            Konto ihrer Schuladministration (siehe „Registrierung einer Schule“).
        </li>
        <li>
            <strong>Die Schuladministration legt es an</strong>, einzeln oder durch den Import einer Schülerliste, die
            die Schulsoftware als CSV-Datei exportiert. Auf diesem Weg entstehen die Konten von Lehrkräften und weiteren
            Administrationen der Schule.
        </li>
        <li>
            <strong>Schüler:innen registrieren sich selbst.</strong> Auf der Anmeldeseite geben sie unter „Mit Klassencode
            registrieren“ ihren Namen, einen Benutzernamen, ein Passwort und den Klassencode ihrer Lehrkraft ein, auf
            Wunsch auch eine E-Mail-Adresse. Das Konto wird immer als Schüler:in angelegt und der Klasse und der Schule
            zugeordnet, zu denen der Code gehört. Den Code selbst speichern wir nicht im Konto.
        </li>
    </ul>
    <p>
        Bei der Selbstregistrierung prüft vorab niemand, ob die Person wirklich zur Klasse gehört. Wer den Klassencode
        kennt, kann ein Schülerkonto in dieser Klasse erstellen. Lehrkräfte geben den Code deshalb nur an ihre Klasse
        weiter. Die Schuladministration kann Konten, die nicht dazugehören, löschen.
    </p>
    <h3>Welche Daten verarbeitet werden</h3>
    <ul>
        <li>Name und Benutzername</li>
        <li>
            optional eine E-Mail-Adresse, ausschließlich für den Link zum Zurücksetzen des Passworts (siehe unten)
        </li>
        <li>das Passwort, das nur als Hash gespeichert wird und nicht lesbar ist</li>
        <li>die Rolle: Plattform-Administration, Schuladministration, Lehrkraft oder Schüler:in</li>
        <li>die Schule, zu der das Konto gehört, bei Schüler:innen die Klasse, bei Lehrkräften gegebenenfalls die Klassenleitung</li>
        <li>
            technische Sitzungsdaten: Sitzungskennung, bei angemeldeten Personen die Benutzerkennung, dazu IP-Adresse,
            Browserangabe und Zeitpunkt der letzten Aktivität (siehe Abschnitt 4)
        </li>
    </ul>
    <p>
        Ohne Name, Benutzername, Passwort und Rolle kann kein Konto angelegt werden. Die E-Mail-Adresse ist freiwillig.
    </p>
    <h3>Wozu</h3>
    <p>
        Die Daten dienen der Anmeldung, der Zuordnung von Inhalten zu Personen und Klassen, der Vergabe von Rechten nach
        der Rolle, dem Zurücksetzen vergessener Passwörter und der Sicherheit der Plattform. Die Schuladministration
        sieht alle Konten ihrer Schule. Lehrkräfte sehen die Namen der Schüler:innen aus den Klassen ihrer Stunden. Die
        Plattform-Administration sieht nur die Konten der Schuladministrationen. An Dritte geben wir diese
        Daten nicht weiter{{ $externalAvatars ? ', abgesehen vom Platzhalter-Bild (siehe Abschnitt 8)' : '' }}.
    </p>

    <h3>Registrierung einer Schule</h3>
    <p>
        Eine Schule legt sich selbst auf der Plattform an. Dabei werden diese Angaben verarbeitet:
    </p>
    <ul>
        <li>
            die Daten der Schule: Name, Ort und Bundesland, auf Wunsch auch Schulnummer, Schulform und eine
            Kontakt-E-Mail-Adresse der Schule
        </li>
        <li>
            die Daten des ersten Kontos der Schuladministration: Name, Benutzername und Passwort (nur als Hash
            gespeichert), gegebenenfalls eine E-Mail-Adresse. Dieses Konto gehört danach zur Schule, wir verarbeiten es in
            deren Auftrag.
        </li>
        <li>
            die Annahme des <a href="{{ route('legal.dpa') }}">Vertrags zur Auftragsverarbeitung</a>: Version, Zeitpunkt,
            der Vertragstext, wie die Schule ihn gesehen hat, sowie Name und Benutzername der annehmenden Person. Dieser
            Nachweis bleibt auch bestehen, wenn das Konto später gelöscht wird.
        </li>
        <li>der Zeitpunkt der Registrierung und, falls die Schule gesperrt wird, der Zeitpunkt der Sperrung</li>
    </ul>
    <p>
        <strong>Wozu:</strong> Die Angaben dienen dazu, die Schule und ihre erste Schuladministration anzulegen, die
        Daten jeder Schule von denen anderer Schulen zu trennen, die Schule bei Fragen zum Betrieb zu erreichen und eine
        Schule sperren zu können, zum Beispiel bei Missbrauch oder auf Wunsch der Schule. Gesperrt kann sich niemand der
        Schule mehr anmelden. Die Daten bleiben dabei gespeichert, bis sie gelöscht werden oder die Sperre aufgehoben
        wird.
    </p>
    <p>
        <strong>Rechtsgrundlage:</strong> Art. 6 Abs. 1 lit. b DSGVO, soweit die Schule mit uns die Nutzung der
        Plattform vereinbart, und Art. 6 Abs. 1 lit. f DSGVO für den sicheren Betrieb der Plattform, die Trennung der
        Schulen voneinander und den Nachweis des Vertrags. Die Stammdaten der Schule sehen die Plattform-Administration (alle Schulen) und die
        Schuladministration der Schule (nur die eigene Schule).
    </p>

    <h3>Plattform-Administration</h3>
    <p>
        Die Plattform-Administration ist die Rolle, mit der wir als Betreiber die Plattform betreuen. Ihre Konten
        gehören zu keiner Schule.
    </p>
    <ul>
        <li>
            <strong>Das kann sie:</strong> registrierte Schulen und ihre Stammdaten ansehen und bearbeiten, Schulen
            sperren und wieder freigeben, Zugänge von Schuladministrationen anlegen, ändern, zurücksetzen und löschen
            und das gemeinsame Kompetenzraster pflegen. Auf ihrer Startseite sieht sie nur Zahlen, nämlich wie viele
            Schulen, Konten je Rolle und Klassen es gibt.
        </li>
        <li>
            <strong>Das sieht sie in der Plattform nicht:</strong> Unterrichtsstunden, Notizen, Arbeitsblätter,
            Hausaufgaben, Abgaben, Verständniswerte, „Erklär’s mir“-Gespräche, Klassen sowie die Konten von Lehrkräften
            und Schüler:innen.
        </li>
    </ul>
    <p>
        Technisch betreiben wir die Server und die Datenbank und haben dort Zugriff, soweit es für Betrieb, Wartung und
        Fehlersuche nötig ist (siehe Abschnitt 3). Diesen Zugriff nutzen wir nur dafür und nicht, um Unterrichts- oder
        Lerndaten auszuwerten.
    </p>

    <h3>Import einer Schülerliste</h3>
    <p>
        Beim Import legt die Plattform aus der hochgeladenen Liste Klassen und Konten an und vergibt für neue Konten
        zufällige Startpasswörter. Die Administration sieht diese Startpasswörter danach einmalig im Klartext, um sie
        weiterzugeben, und kann sie als CSV-Liste herunterladen. In der Datenbank liegt nur der Hash. Die hochgeladene
        Datei wird nur zum Einlesen gebraucht. Sie liegt bis zum Abschluss des Imports im temporären Ordner des Servers
        und wird nicht dauerhaft aufbewahrt.
    </p>

    <h3>Passwort zurücksetzen</h3>
    <p>
        Wer eine E-Mail-Adresse hinterlegt hat, kann auf der Anmeldeseite unter „Passwort vergessen?“ den Benutzernamen
        oder die Adresse eingeben. Gehört dazu ein Konto mit hinterlegter Adresse, schicken wir an diese Adresse eine
        E-Mail mit einem Link zum Zurücksetzen. Die Antwort auf der Seite ist immer gleich, damit niemand erfährt, ob es
        ein Konto gibt.
    </p>
    <p>
        Für den Link speichert die Plattform in der Datenbank (Tabelle <code>password_reset_tokens</code>) die
        E-Mail-Adresse, einen verschlüsselten Code und den Zeitpunkt der Anfrage. Der Link gilt 60 Minuten. Der Eintrag
        wird nach erfolgreichem Zurücksetzen gelöscht und bei einer neuen Anfrage ersetzt. Nicht eingelöste Einträge sind
        nach Ablauf unbrauchbar und werden bei der Datenbankpflege entfernt. Wer keine Adresse hinterlegt hat, wendet sich
        an die Administration, die das Passwort neu setzen kann.
    </p>
    <p>
        Den Versand übernimmt der E-Mail-Dienst, den der Betreiber für die Plattform eingerichtet hat. Soweit das ein
        externer Dienstleister ist, erhält er dafür die Empfängeradresse und den Inhalt der E-Mail. Er verarbeitet sie nur
        nach unserer Weisung auf Grundlage eines Vertrags nach Art. 28 DSGVO. Eine normale E-Mail ist auf dem Weg nicht
        immer verschlüsselt.
    </p>

    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb ist es Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem jeweiligen Landesschulgesetz und den
        Datenschutzvorschriften des Landes für Schulen, in Hessen etwa dem Hessischen Schulgesetz. Die Verarbeitung dient
        dem schulischen Bildungs- und Erziehungsauftrag.
    </p>
    <p>
        Im Demo- und Pilotbetrieb ist es Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse ist es, die Plattform
        mit Demokonten vorzuführen und zu erproben. Die Demokonten gehören zu erfundenen Personen, ihre Zugangsdaten
        stehen auf der Anmeldeseite.
    </p>

    <h2 id="lerndaten">6. Unterrichts- und Lerndaten</h2>
    <h3>Was gespeichert wird</h3>
    <ul>
        <li>
            <strong>Unterrichtsstunden (Lehrkräfte):</strong> Fach, Thema, Datum, Notizen, Tafelbild oder Transkripttext
            sowie hochgeladene Arbeitsblätter als Foto, Scan oder PDF (bis 10 MB je Datei). Die Dateien liegen auf dem
            Server und sind nur über die Plattform abrufbar.
        </li>
        <li>
            <strong>Hausaufgaben:</strong> eine Zusammenfassung und Fragen. Sie entstehen zunächst als Entwurf (siehe
            Abschnitt 7), die Lehrkraft prüft und ändert sie und weist sie dann den Schüler:innen zu.
        </li>
        <li>
            <strong>Abgaben (Schüler:innen):</strong> die eigene Zusammenfassung und die Antworten auf die Fragen. Dazu
            das Ergebnis der Auswertung: ein Verständniswert von 0 bis 100 für die ganze Abgabe und für jede Antwort, ein
            Feedback-Text, Hinweise auf Lücken und der Zeitpunkt der Auswertung.
        </li>
        <li>
            <strong>„Erklär’s mir“:</strong> Schüler:innen erklären einer Computerfigur namens Frieda ein Thema und
            bekommen Rückfragen. Gespeichert werden das Thema, der gesamte Gesprächsverlauf, der Verständniswert und das
            Feedback.
        </li>
    </ul>
    <p>
        <strong>Fehlerart:</strong> Bei einer Antwort, die nicht ganz richtig ist, kann die Plattform zusätzlich die
        Fehlerart speichern: Grundidee noch unklar, Weg/Schritt falsch, unvollständig, am Thema vorbei oder kein
        Versuch. Das geschieht nur bei Antworten unter der eingestellten Punktgrenze und nur, wenn die Einordnung
        sicher genug ist; eine unsichere Einordnung bleibt leer. Zur Fehlerart hält die Plattform die Quelle der
        Einordnung fest (Demo-Regeln, KI oder Lehrkraft) und, wenn eine KI eingeordnet hat, deren Sicherheit von 0 bis
        100. Die Fehlerart ist nur ein Hinweis zur Antwort, nicht zur Person:
        Sie ändert keine Punktzahl und erscheint nicht im Elternbericht.
        Die Lehrkraft kann sie korrigieren oder zurücknehmen; ihre Einordnung hat Vorrang. Die Schülerin oder der
        Schüler sieht die eigenen Hinweise in der Rückmeldung zur Hausaufgabe. Die Lehrkraft sieht für die Klassen, die
        sie leiten oder unterrichten, die Verteilung je Kompetenz und den Einzelhinweis neben der Antwort,
        gekennzeichnet als „KI-Hinweis, kann falsch sein“. Andere Schüler:innen und andere Schulen sehen nichts. Für
        eine Einordnung durch die KI gehen Frage, Erwartungshorizont und Antworttext an den KI-Anbieter (Abschnitt 7);
        die Namen der Klasse und der Lehrkraft ersetzt die Plattform vorher durch Platzhalter, hochgeladene
        Arbeitsblätter gehen unverändert mit. Die Fehlerart wird mit der Antwort gelöscht, die Datenauskunft im Profil
        nennt sie samt Quelle.
    </p>
    <p>
        Aus den gespeicherten Ergebnissen berechnet die Plattform eine Übersicht zum Lernstand und möglichem
        Förderbedarf je Klasse sowie einen druckbaren Bericht für Elterngespräche. Beides enthält nur, was bereits
        gespeichert ist.
    </p>

    <div class="legal-note">
        <p class="legal-note-title">Hinweis für Lehrkräfte</p>
        <p>
            Bitte tragen Sie in Notizen, Transkripten und Arbeitsblättern keine personenbezogenen Daten von Schüler:innen
            ein, zum Beispiel Namen, Fotos oder Leistungsstände. Tonaufnahmen des Unterrichts sind nur mit dem
            optionalen Aufnahme-Modul Ihrer Schule und nur mit den nötigen Einwilligungen erlaubt. Bitte laden Sie
            Arbeitsblätter nur hoch, wenn Sie dazu berechtigt sind.
        </p>
    </div>

    <h3>Wer sieht was</h3>
    <div class="legal-table-wrap" role="region" aria-label="Wer welche Daten sehen kann" tabindex="0">
        <table class="legal-table">
            <caption>Wer welche Daten sehen kann</caption>
            <thead>
                <tr>
                    <th scope="col">Daten</th>
                    <th scope="col">Schüler:in</th>
                    <th scope="col">Lehrkraft</th>
                    <th scope="col">Schuladministration (nur die eigene Schule)</th>
                    <th scope="col">Plattform-Administration</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">Abgaben, Verständniswerte und Feedback</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur die eigenen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die Abgaben zu den eigenen Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">„Erklär’s mir“-Gespräche mit Verlauf</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur die eigenen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> nur Gespräche, die zu einer eigenen Stunde gehören</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Unterrichtsnotizen und Arbeitsblätter</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die eigenen Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Hausaufgaben mit Fragen und Zusammenfassung</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur veröffentlichte und ihnen zugewiesene, die Zusammenfassung der Lehrkraft erst nach der Abgabe</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die eigenen Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Lernstand-Übersicht</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td>
                        <span class="legal-cell-label">Lehrkraft</span>
                        für die Klassen, die sie leiten oder in denen sie Stunden halten: Kompetenzwerte und
                        Förderbedarf aller Schüler:innen der Klasse, berechnet aus allen ausgewerteten Antworten, auch zu
                        Stunden anderer Lehrkräfte
                    </td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Elterngesprächs-Bericht</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td>
                        <span class="legal-cell-label">Lehrkraft</span>
                        für Schüler:innen dieser Klassen: die Punktwerte aller veröffentlichten Hausaufgaben, auch zu
                        Stunden anderer Lehrkräfte, sowie Thema, Punktwert und Feedback aller „Erklär’s mir“-Sitzungen,
                        auch zu frei gewählten Themen (ohne Gesprächsverlauf)
                    </td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Konten und Klassen</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> nur die Namen der Schüler:innen aus den Klassen ihrer Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle Konten und Klassen der Schule</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> nur die Konten der Schuladministrationen, keine Klassen und keine Konten von Lehrkräften und Schüler:innen</td>
                </tr>
                <tr>
                    <th scope="row">Stammdaten der Schule und Zugänge der Schuladministration</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> die der eigenen Schule</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> die aller Schulen</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>Andere Schüler:innen sehen die Abgaben und Gespräche einer Schülerin oder eines Schülers nie.</p>
    <p>
        Die Daten verschiedener Schulen sind voneinander getrennt. Lehrkräfte und Schuladministration einer Schule sehen
        nie Klassen, Konten, Unterrichtsstunden, Abgaben oder Gespräche einer anderen Schule.
    </p>

    <h3>Verständniswert und Förderbedarf</h3>
    <p>
        Der Verständniswert ist ein Hinweis darauf, wo Unterstützung nötig sein könnte. Er ist keine Note und keine
        Leistungsbewertung im schulrechtlichen Sinn. Lehrkräfte entscheiden selbst, ob und wie sie ihn nutzen. Über
        Förderung, Noten oder Versetzung entscheidet die Schule, nie die Plattform (siehe Abschnitt 7).
    </p>
    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem jeweiligen Landesschulgesetz, im Demo- und
        Pilotbetrieb Art. 6 Abs. 1 lit. f DSGVO, wie in Abschnitt 5 beschrieben.
    </p>

    <h2 id="ki">7. Einsatz von KI</h2>
    <p>
        Die Plattform nutzt eine KI-Komponente für vier Aufgaben. Sie entwirft aus den Unterrichtsnotizen eine
        Zusammenfassung und Hausaufgaben. Sie wertet die Texte der Schüler:innen aus. Als Figur Frieda stellt sie im
        „Erklär’s mir“-Chat Rückfragen. Am Ende schätzt sie ein, wie gut ein Thema erklärt wurde. Frieda ist ein
        Computerprogramm und keine reale Person.
    </p>

    @if ($aiEnabled)
        <div class="legal-note">
            <p class="legal-note-title">Aktueller Stand: Die KI-Anbindung ist aktiviert.</p>
            <p>
                Für die genannten Aufgaben werden Inhalte an Claude von Anthropic PBC in den USA übermittelt.
            </p>
        </div>
        <h3>Was übermittelt wird</h3>
        <ul>
            <li>Texte der Unterrichtsstunden, also Notizen, Tafelbild oder Transkripttext</li>
            <li>hochgeladene Arbeitsblätter als Bild oder PDF</li>
            <li>Texte der Schüler:innen: Zusammenfassungen, Antworten und Beiträge im „Erklär’s mir“-Chat</li>
        </ul>
        <p>
            Namen, Benutzernamen und Benutzerkennungen werden nicht übermittelt. Bei der Auswertung einer Abgabe, beim
            Hausaufgaben-Entwurf und beim Entwurf einer Förderaufgabe ersetzt die Plattform außerdem vorher die Namen
            der Klasse und der Lehrkraft durch Platzhalter („Schüler:in A“, „Lehrkraft“). Das ist eine technische
            Hilfe und keine Anonymisierung: Unbekannte Namen, Spitznamen und Fehler der Spracherkennung bleiben im
            Text, und hochgeladene Arbeitsblätter gehen als Bild oder PDF unverändert mit. Im „Erklär’s mir“-Chat
            ersetzt die Plattform nichts. Deshalb bitten wir alle, keine persönlichen Angaben in Texte zu schreiben.
        </p>
        <h3>Übermittlung in die USA</h3>
        <p>
            Die USA sind ein Drittland. Wir übermitteln Inhalte dorthin nur mit geeigneten Garantien nach Art. 44 ff.
            DSGVO, also auf Grundlage von EU-Standardvertragsklauseln oder eines Angemessenheitsbeschlusses der
            EU-Kommission, und nur, wenn mit dem Anbieter ein Vertrag zur Auftragsverarbeitung besteht. Eine Kopie der
            Garantien erhalten Sie auf Anfrage (siehe Abschnitt 12). Wie lange der Anbieter übermittelte Inhalte
            speichert, regelt dieser Vertrag. Auch dazu geben wir auf Anfrage Auskunft.
        </p>
        <p>
            Vor dem Einsatz im Unterricht prüft die verantwortliche Stelle, ob eine Datenschutz-Folgenabschätzung nach
            Art. 35 DSGVO nötig ist. Die Schule entscheidet, ob die KI-Anbindung genutzt wird, und informiert
            Schüler:innen und Eltern darüber.
        </p>
    @else
        <div class="legal-note">
            <p class="legal-note-title">Aktueller Stand: Die KI-Anbindung ist abgeschaltet.</p>
            <p>
                Alle Auswertungen laufen auf unserem Server mit einer regelbasierten Offline-Logik. Für die Auswertungen
                werden keine Daten an Dritte übermittelt.
            </p>
        </div>
        <p>
            Die Offline-Logik arbeitet mit festen Textregeln. Sie ermittelt die wichtigsten Begriffe aus den
            Unterrichtsnotizen und misst, wie viele davon ein Text der Schülerin oder des Schülers abdeckt. Sie ruft
            keinen externen Dienst auf.
        </p>
        <h3>Vorbereitete Anbindung</h3>
        <p>
            Die Plattform ist technisch darauf vorbereitet, später Claude von Anthropic PBC in den USA anzubinden. Das
            ist derzeit nicht aktiv. Würde die Anbindung eingeschaltet, würden Texte aus Unterrichtsstunden,
            hochgeladene Arbeitsblätter und Texte der Schüler:innen an Anthropic übermittelt. Die Namen der Klasse und
            der Lehrkraft würde die Plattform dabei vorher durch Platzhalter ersetzen, hochgeladene Arbeitsblätter
            würden unverändert mitgehen.
        </p>
        <p>
            Weil die USA ein Drittland sind, würde das nur mit geeigneten Garantien nach Art. 44 ff. DSGVO geschehen, also
            auf Grundlage von EU-Standardvertragsklauseln oder eines Angemessenheitsbeschlusses der EU-Kommission.
            Eingeschaltet wird die Anbindung erst, nachdem ein Vertrag zur Auftragsverarbeitung geschlossen und eine
            Datenschutz-Folgenabschätzung durchgeführt wurde und Schüler:innen und Eltern informiert wurden. Diese
            Datenschutzerklärung wird vor der Aktivierung aktualisiert.
        </p>
    @endif

    <h3>Keine automatisierten Entscheidungen</h3>
    <p>
        Es gibt keine ausschließlich automatisierte Entscheidung im Sinne des Art. 22 DSGVO. Die Auswertungen, Verständniswerte
        und Rückmeldungen sind Hinweise. Menschen entscheiden, was daraus folgt. Auch die KI kann sich irren.
    </p>

    <h2 id="schriftarten">8. Schriftarten und externe Inhalte</h2>
    <p>
        Die Plattform verwendet die Schriftart Lexend. Sie wird von unserem eigenen Server ausgeliefert. Es besteht keine
        Verbindung zu Servern von Schriftanbietern, deshalb werden dabei keine Daten, insbesondere keine IP-Adressen, an
        Dritte übermittelt.
    </p>
    @if ($externalAvatars)
        <h3>Platzhalter-Bild im Menü</h3>
        <p>
            Im angemeldeten Bereich zeigt die Plattform neben dem Namen ein Platzhalter-Bild mit den Initialen der
            angemeldeten Person. Dieses Bild lädt der Browser derzeit vom Dienst ui-avatars.com. Dorthin gelangen die
            IP-Adresse, Angaben zum Browser und die Initialen des Namens, die in der Bildadresse stehen. Den Dienst
            betreiben wir nicht, sein Sitz und sein Serverstandort sind uns nicht bekannt, eine Übermittlung in ein
            Drittland ist deshalb nicht ausgeschlossen. Mit dem Anbieter haben wir keinen Vertrag geschlossen.
            Rechtsgrundlage ist im Demo- und Pilotbetrieb Art. 6 Abs. 1 lit. f DSGVO, im Schulbetrieb lit. e. Das Bild
            lässt sich durch eines vom eigenen Server ersetzen, diese Erklärung wird dann angepasst.
        </p>
        <p>
            Außer diesem Bild werden keine Inhalte von fremden Servern eingebunden, etwa Videos, Karten oder
            Reichweitenmessung.
        </p>
    @else
        <p>
            Auch sonst werden keine Inhalte von fremden Servern eingebunden, etwa Videos, Karten, Profilbilder oder
            Reichweitenmessung.
        </p>
    @endif

    <h2 id="kontakt">9. Kontakt per E-Mail</h2>
    <p>
        Wenn Sie uns per E-Mail schreiben, verarbeiten wir Ihre E-Mail-Adresse und den Inhalt Ihrer Nachricht, um Ihre
        Anfrage zu beantworten. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, wenn es um einen Vertrag oder dessen
        Anbahnung geht, sonst Art. 6 Abs. 1 lit. f DSGVO. Wir löschen die Nachricht, sobald die Anfrage erledigt ist und
        keine gesetzliche Aufbewahrungspflicht besteht. Ein Kontaktformular gibt es nicht.
    </p>
    <p>
        Eine normale E-Mail ist nicht verschlüsselt und kann auf dem Weg mitgelesen werden. Bitte schicken Sie uns darin
        keine Daten von Schüler:innen oder andere sensible Angaben.
    </p>
    <p>E-Mails, die die Plattform selbst verschickt, beschreibt Abschnitt 5 unter „Passwort zurücksetzen“.</p>

    <h2 id="speicherdauer">10. Speicherdauer</h2>
    <p>Wir speichern personenbezogene Daten nur so lange, wie es für den jeweiligen Zweck nötig ist.</p>
    <ul>
        <li>
            <strong>Sitzungen:</strong> Eine Sitzung läuft nach {{ $sessionMinutes }} Minuten ohne Aktivität ab.
            Abgelaufene Sitzungen werden regelmäßig aus der Datenbank entfernt.
        </li>
        <li>
            <strong>Server-Logfiles und Fehlerprotokoll:</strong> nur so lange, wie wir sie brauchen, siehe Abschnitt 3.
        </li>
        <li>
            <strong>Links zum Zurücksetzen des Passworts:</strong> 60 Minuten gültig, siehe Abschnitt 5.
        </li>
        <li>
            <strong>Konten:</strong> Die Plattform löscht Konten nicht automatisch. Sie werden gelöscht, wenn sie nicht
            mehr gebraucht werden, auf Anfrage oder durch die Schule nach deren eigenen Aufbewahrungs- und Löschfristen.
            Die Schuladministration kann Konten ihrer Schule löschen, dabei verschwinden auch die zugehörigen Abgaben und
            Gespräche der Schüler:innen.
        </li>
        <li>
            <strong>Unterrichts- und Lerndaten:</strong> Unterrichtsstunden mit ihren Fragen, Abgaben und Antworten sowie
            „Erklär’s mir“-Gespräche löscht die Plattform täglich automatisch, wenn sie älter sind als die
            Aufbewahrungsfrist. Aufbewahrt werden {{ $keptPhrase }}. Lehrkräfte können Abgaben schon vorher löschen, etwa
            damit eine Hausaufgabe neu bearbeitet werden kann. Einzelne „Erklär’s mir“-Gespräche löschen wir auf Anfrage.
            Hochgeladene Arbeitsblätter entfernen wir auf Wunsch auch vom Server.
        </li>
        <li>
            <strong>Tonaufnahmen (nur mit dem optionalen Aufnahme-Modul):</strong> {{ $recordingDays }} Tage nach
            Aufnahmebeginn, dann löscht die Plattform sie automatisch. Einwilligungsnachweise der Erziehungsberechtigten
            bleiben bis zur Löschung des Schülerkontos gespeichert.
        </li>
        <li>
            <strong>Daten der Schule bei der Registrierung:</strong> solange die Schule die Plattform nutzt, der Nachweis
            über die Annahme des Vertrags danach nur so lange, wie wir ihn zum Nachweis brauchen. Auch eine
            gesperrte Schule bleibt gespeichert. Auf Wunsch der Schule löschen wir sie samt Konten sowie Unterrichts- und
            Lerndaten.
        </li>
        <li><strong>E-Mails:</strong> bis die Anfrage erledigt ist, siehe Abschnitt 9.</li>
    </ul>
    <div class="legal-note">
        <p class="legal-note-title">Demodaten</p>
        <p>
            Die Daten in der Demo sind erfunden. Bitte geben Sie keine echten personenbezogenen Daten ein. Demodaten
            können jederzeit ohne Vorankündigung zurückgesetzt oder gelöscht werden.
        </p>
    </div>

    <h2 id="kinder">11. Kinder und Jugendliche</h2>
    <p>
        Die Plattform ist für Schüler:innen ab etwa 10 Jahren gedacht. Die Schule stellt sie bereit, genutzt wird sie im
        Unterricht und für Hausaufgaben. Konten entstehen durch die Schuladministration oder durch die Registrierung mit dem
        Klassencode der Lehrkraft (siehe Abschnitt 5). Kinder und Jugendliche brauchen besonderen Schutz. Deshalb erheben
        wir nur, was die Plattform braucht, nutzen die Daten nicht für Werbung oder für eigene geschäftliche Zwecke und
        haben mit der Kurzfassung in Abschnitt 2 für eine verständliche Sprache gesorgt.
    </p>
    <p>
        Eltern und Sorgeberechtigte können die Rechte ihrer Kinder ausüben, solange diese deren Tragweite noch nicht
        selbst überblicken. Mit zunehmender Reife können Kinder und Jugendliche ihre Rechte auch selbst wahrnehmen. Holt
        die Schule für einzelne Funktionen eine Einwilligung ein, zum Beispiel vor einer Aktivierung der KI-Anbindung,
        informiert sie darüber selbst.
    </p>

    <h2 id="rechte">12. Rechte der betroffenen Personen</h2>
    <p>Sie haben nach der DSGVO folgende Rechte:</p>
    <ul>
        <li><strong>Auskunft</strong> über die gespeicherten Daten (Art. 15 DSGVO)</li>
        <li><strong>Berichtigung</strong> falscher Daten (Art. 16 DSGVO)</li>
        <li><strong>Löschung</strong> der Daten, wenn die gesetzlichen Voraussetzungen vorliegen (Art. 17 DSGVO)</li>
        <li><strong>Einschränkung</strong> der Verarbeitung (Art. 18 DSGVO)</li>
        <li><strong>Datenübertragbarkeit</strong> in einem gängigen, maschinenlesbaren Format (Art. 20 DSGVO)</li>
        <li>
            <strong>Widerspruch</strong> gegen eine Verarbeitung, die auf Art. 6 Abs. 1 lit. e oder f DSGVO beruht. Sie
            können ihn aus Gründen einlegen, die sich aus Ihrer besonderen Situation ergeben (Art. 21 DSGVO).
        </li>
        <li>
            <strong>Widerruf einer Einwilligung</strong> mit Wirkung für die Zukunft (Art. 7 Abs. 3 DSGVO). Die
            Nur das optionale Aufnahme-Modul stützt sich auf Einwilligungen (Art. 6 Abs. 1 lit. a DSGVO), die die Schule einholt.
            Eltern widerrufen eine Einwilligung für Tonaufnahmen über den Link in ihrem Einwilligungsschreiben oder bei der
            Schule.
        </li>
        <li><strong>Beschwerde</strong> bei einer Aufsichtsbehörde (siehe Abschnitt 13)</li>
    </ul>
    <p>
        Schreiben Sie dazu an <a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a>. Im Schulbetrieb
        wenden Sie sich bitte zuerst an Ihre Schule, wir unterstützen sie als Auftragsverarbeiter. Wir beantworten
        Anfragen innerhalb eines Monats. Um Missbrauch zu verhindern, prüfen wir vorher, ob die Anfrage von der
        betroffenen Person oder ihren Sorgeberechtigten kommt.
    </p>

    <h2 id="beschwerde">13. Beschwerderecht</h2>
    <p>
        Sie haben das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, wenn Sie meinen, dass Ihre Daten
        rechtswidrig verarbeitet werden (Art. 77 DSGVO). Für uns als Betreiber ist zuständig:
    </p>
    <address>
        Der Hessische Beauftragte für Datenschutz und Informationsfreiheit<br>
        Gustav-Stresemann-Ring 1<br>
        65189 Wiesbaden<br>
        <a href="https://datenschutz.hessen.de" rel="noopener noreferrer">datenschutz.hessen.de</a>
    </address>
    <p>
        Im Schulbetrieb können Sie sich außerdem an die Datenschutz-Aufsichtsbehörde des Landes wenden, in dem die
        Schule liegt.
    </p>

    <h2 id="aenderungen">14. Änderungen dieser Erklärung</h2>
    <p>
        Wir passen diese Datenschutzerklärung an, wenn sich die Plattform, die Verarbeitung oder die Rechtslage ändern,
        zum Beispiel bevor die KI-Anbindung aktiviert wird. Es gilt die Fassung, die hier veröffentlicht ist.
    </p>
    <p>Stand: Oktober 2026</p>
@endsection
