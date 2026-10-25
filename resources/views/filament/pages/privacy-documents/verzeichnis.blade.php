@php
    $thirdCountries = $aiEnabled
        ? "Ja. Die KI-Anbindung ist eingeschaltet: {$aiData} gehen an Anthropic PBC (USA); bei der Auswertung einer Aufnahme nur pseudonymisierter Text, bei der Auswertung einer Abgabe nur Frage, Erwartungshorizont und Antworttext ohne Notizen, Zusammenfassungen oder Arbeitsblatt-Dateien. Name und Benutzerkennung des Kontos werden nicht mitgesendet. Bei der Auswertung von Abgaben und bei den Entwürfen von Hausaufgaben und Förderaufgaben ersetzt die Plattform vorher die Namen der Klasse und der Lehrkraft durch Platzhalter; das ist keine Anonymisierung, Namen im Freitext oder auf Arbeitsblättern bleiben möglich. Garantien nach Art. 44 ff. DSGVO (Standardvertragsklauseln oder Angemessenheitsbeschluss) und Vertrag nach Art. 28 DSGVO sind zu dokumentieren."
        : "Nein. Die KI-Anbindung ist ausgeschaltet, es verlassen keine Daten die Plattform. Wird sie eingeschaltet, gehen {$aiData} an Anthropic PBC (USA); dann sind Garantien nach Art. 44 ff. DSGVO und ein Vertrag nach Art. 28 DSGVO zu dokumentieren.";

    $measures = "Siehe Anlage „Technische und organisatorische Maßnahmen“ zum Auftragsverarbeitungsvertrag. Kurz: Trennung der Schulen, Rollen- und Rechtekonzept mit serverseitiger Prüfung, Passwörter nur als Hash, Sitzungsende nach {$sessionMinutes} Minuten ohne Aktivität, Schutz gegen gefälschte Anfragen, automatische Löschfristen, kein Tracking, Schriftarten vom eigenen Server.";

    $entries = [
        [
            'title' => 'A: Nutzung der Lernplattform im Unterricht',
            'rows' => [
                'Zwecke' => 'Unterstützung von Unterricht und Lernen: Unterrichtsnotizen und Arbeitsblätter, Hausaufgaben, Abgaben, „Erklär’s mir“-Gespräche, Auswertung des Verständnisses, Übersicht über Förderbedarf, Bericht für Elterngespräche, Verwaltung von Konten und Klassen.',
                'Betroffene Personen' => 'Schüler:innen, Lehrkräfte, Schuladministration der Schule.',
                'Datenkategorien' => 'Name, Benutzername, E-Mail-Adresse (optional), Rolle, Klasse, Passwort-Hash; Notizen und Arbeitsblätter der Lehrkräfte; Abgaben, Antworten, Gesprächsverläufe, Verständniswerte und Rückmeldungen; Sitzungs- und Protokolldaten.',
                'Fehlerart der Antworten' => 'Je Antwort einer Abgabe kann die Fehlerart gespeichert werden (Grundidee noch unklar, Weg/Schritt falsch, unvollständig, am Thema vorbei, kein Versuch), dazu die Sicherheit der Einordnung von 0 bis 100 (nur bei einer Einordnung durch KI) und die Quelle (Demo-Regeln, KI oder Lehrkraft). Gespeichert wird nur bei Antworten unter der eingestellten Punktgrenze und nur, wenn die Einordnung sicher genug ist; unsichere Einordnungen bleiben leer. Die Fehlerart ist ein Hinweis für Rückmeldung und Förderung, keine Bewertung: Sie ändert keine Punktzahl und erscheint nicht im Bericht für das Elterngespräch. Die Lehrkraft kann sie korrigieren oder zurücknehmen; ihre Einordnung hat Vorrang und wird als „von der Lehrkraft eingeordnet“ gezeigt. Sichtbar ist sie für die Schüler:in als eigener Hinweis und für die Lehrkraft der Klasse als Verteilung je Kompetenz und als Einzelhinweis, gekennzeichnet als „KI-Hinweis, kann falsch sein“ oder, bei der Einordnung durch die Regeln der Plattform, als „Regel-Hinweis, kann falsch sein“. Andere Schulen sehen nichts. Ordnet die KI ein, gehen nur Frage, Erwartungshorizont und Antworttext an den KI-Anbieter; die Namen der Klasse und der Lehrkraft ersetzt die Plattform vorher durch Platzhalter. Notizen der Lehrkraft, Zusammenfassungen und Arbeitsblatt-Dateien gehen bei der Auswertung einer Abgabe nicht mit. Die Angaben werden mit der Antwort gelöscht; die Datenauskunft nennt Fehlerart und Quelle.',
                'Empfänger' => 'Intern: Lehrkräfte (zu ihren Stunden und Klassen), Schuladministration (eigene Schule). Extern: Betreiber der Plattform als Auftragsverarbeiter, dessen Unterauftragsverarbeiter (Hosting) nach Auftragsverarbeitungsvertrag. Die Plattform-Administration sieht keine Unterrichts- und Lerndaten. Nur nach Einschalten: KI-Anbieter (siehe Übermittlung in Drittländer).',
                'Übermittlung in Drittländer' => $thirdCountries,
                'Löschfristen' => "Unterrichtsstunden mit Abgaben und Gesprächen: automatisch nach {$schoolYearsKept} Schuljahren (das laufende eingerechnet). Die Fehlerart einer Antwort wird mit der Antwort gelöscht. Konten: Löschung durch die Schuladministration, etwa beim Abgang von der Schule. Sitzungen: {$sessionMinutes} Minuten ohne Aktivität. Server-Logfiles und Fehlerprotokoll: Frist des Betreibers laut Anlage 1 des Auftragsverarbeitungsvertrags, hier eintragen: ______ Tage.",
                'Technische und organisatorische Maßnahmen' => $measures,
                'Rechtsgrundlage' => 'Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes. Die Schule ergänzt die genaue Norm.',
                'Datenschutz-Folgenabschätzung' => 'Schwellwertprüfung nötig, mit der Vorlage „Folgenabschätzung“. Beim Einschalten der KI ist sie zu empfehlen.',
            ],
        ],
        [
            'title' => 'B: Tonaufnahme von Unterricht (Aufnahme-Modul, optional)',
            'rows' => [
                'Zwecke' => 'Eine Lehrkraft nimmt den Ton einer Stunde auf, um Transkript, Zusammenfassung und Tafelbild-Vorschlag für ihre Unterrichtsnotizen zu erhalten (Vor- und Nachbereitung). Keine Kontrolle von Schüler:innen oder Lehrkräften.',
                'Betroffene Personen' => 'Alle, die im Raum sprechen: Schüler:innen (überwiegend minderjährig), die aufnehmende Lehrkraft, gegebenenfalls Vertretungen, Gäste, Praktikant:innen, Schulbegleitungen. Für die Einwilligungen zusätzlich die Erziehungsberechtigten.',
                'Datenkategorien' => 'Tonaufnahme (Stimmen und Äußerungen), Beginn, Dauer, aufnehmende Lehrkraft, Zeitpunkt der Bestätigung vor der Aufnahme; Transkript und Auswertung. Einwilligungen je Schüler:in: Name der erklärenden Person, Zwecke, Fassung des Textes, Zeitpunkt, Widerruf. Gesprochenes kann zufällig besondere Kategorien nach Art. 9 DSGVO berühren.',
                'Empfänger' => 'Aufnahme, Transkript und Auswertung: nur die aufnehmende Lehrkraft. Einwilligungen: Schuladministration und Lehrkräfte der Klasse. Auf Aufnahmen haben Schuladministration, andere Lehrkräfte, Schüler:innen und Plattform-Administration keinen Zugriff. Betreiber als Auftragsverarbeiter (technisch nur Betrieb). Nur nach Einschalten: Anbieter für Spracherkennung und KI-Anbieter, jeweils nur Text beziehungsweise bei der Spracherkennung Audio, mit Vertrag nach Art. 28 DSGVO.',
                'Übermittlung in Drittländer' => $thirdCountries,
                'Löschfristen' => "Aufnahme, Transkript und Auswertung: automatisch {$recordingDays} Tage nach Aufnahmebeginn, jederzeit früher durch die Lehrkraft. In Unterrichtsnotizen übernommene Texte (Namen der Klasse vorher durch Platzhalter ersetzt) folgen den Fristen der Notizen ({$schoolYearsKept} Schuljahre) und gehen bei der Hausaufgaben-Funktion mit KI an den KI-Anbieter. Löschung der Aufnahme über die Plattform nur durch die aufnehmende Lehrkraft; sonst über die Löschung von Stunde oder Konto oder auf Weisung durch den Betreiber.",
                'Technische und organisatorische Maßnahmen' => $measures.' Zusätzlich: nur Ton; Dialog mit Bestätigung der Lehrkraft vor jeder Aufnahme; feste Leiste während der Aufnahme; Zugriff nur für die aufnehmende Lehrkraft; Dateien nicht öffentlich; Live-Texterkennung nur lokal im Browser; Pseudonymisierung der Namen von Klasse und Lehrkraft bei der Auswertung einer Aufnahme und einer Abgabe sowie bei den Entwürfen; Audio nie an KI.',
                'Rechtsgrundlage' => 'Einwilligung nach Art. 6 Abs. 1 lit. a, Art. 7 DSGVO aller aufgenommenen Personen, bei Minderjährigen der Sorgeberechtigten und je nach Land und Alter der Schüler:innen; bei Beschäftigten Landesrecht beziehungsweise § 26 BDSG beachten. Zusätzlich § 201 StGB. Für zufällig mitgesprochene besondere Kategorien (Gesundheit, Herkunft, Religion, Familie) ausdrückliche Einwilligung nach Art. 9 Abs. 2 lit. a DSGVO.',
                'Datenschutz-Folgenabschätzung' => 'Ja, vor der ersten Aufnahme (Vorlage „Folgenabschätzung“). Personalrat beteiligen, soweit nach Landesrecht vorgesehen.',
            ],
        ],
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Eintrag für das Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)',
    'lead' => 'Vorlage für zwei Einträge im Verzeichnis der Schule: die Lernplattform insgesamt und das optionale Aufnahme-Modul, das einen eigenen Zweck, eine eigene Rechtsgrundlage und eigene Fristen hat.',
])

<h3>Angaben zum Verantwortlichen (Art. 30 Abs. 1 lit. a)</h3>
@include('filament.pages.privacy-documents.partials.school')

<dl class="pd-facts">
    <dt>Gemeinsam Verantwortliche</dt>
    <dd>
        <ul class="pd-options">
            <li><span class="pd-check" aria-hidden="true"></span> keine</li>
            <li><span class="pd-check" aria-hidden="true"></span> ja, zum Beispiel der Schulträger: {{ $blank }}</li>
        </ul>
    </dd>

    <dt>Auftragsverarbeiter</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>
</dl>

@foreach ($entries as $entry)
    <h3>Eintrag {{ $entry['title'] }}</h3>

    <dl class="pd-facts">
        @foreach ($entry['rows'] as $label => $value)
            <dt>{{ $label }}</dt>
            <dd>{{ $value }}</dd>
        @endforeach

        <dt>Eingeführt am</dt>
        <dd>{{ $blank }}</dd>

        <dt>Letzte Änderung am</dt>
        <dd>{{ $blank }}</dd>

        <dt>Eingetragen von</dt>
        <dd>{{ $blank }}</dd>
    </dl>
@endforeach

<p>
    Der Betreiber der Plattform führt als Auftragsverarbeiter ein eigenes Verzeichnis nach Art. 30 Abs. 2 DSGVO. Dieser Eintrag ersetzt es nicht.
</p>
