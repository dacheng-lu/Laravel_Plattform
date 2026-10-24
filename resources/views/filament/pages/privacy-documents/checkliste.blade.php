@php
    $hasSchool = $school['name'] !== null;

    // One task: what to do, why, and optionally what the platform knows about it and a link to the place in the platform.
    $entry = fn (string $title, string $text, ?string $status = null, ?array $link = null): array => compact('title', 'text', 'status', 'link');

    $dpaStatus = match (true) {
        ! $hasSchool => null,
        $dpa['acceptedAt'] !== null => 'In der Plattform akzeptiert am '.$dpa['acceptedAt']->format('d.m.Y').'.',
        default => 'In der Plattform noch nicht akzeptiert.',
    };

    $recordingStatus = match (true) {
        $recordings['enabled'] === null => null,
        $recordings['enabled'] => 'Aufnahmen sind für diese Schule eingeschaltet.',
        default => 'Aufnahmen sind für diese Schule ausgeschaltet.',
    };

    $general = [
        $entry('Entscheidung zum Einsatz der Lernplattform', 'Schulleitung beziehungsweise Schulträger entscheiden über den Einsatz und sind nach Landesrecht die verantwortliche Stelle. Zuständigkeiten, zum Beispiel Freigabe digitaler Lernmittel durch Schulträger oder Schulaufsicht, sind geklärt.'),
        $entry('Datenschutzbeauftragte(r) beteiligt', 'Die oder der schulische Datenschutzbeauftragte kennt die Plattform und hat Stellung genommen (Art. 37 bis 39 DSGVO). Name und Kontakt stehen in der Datenschutzerklärung der Schule.'),
        $entry('Auftragsverarbeitungsvertrag abgeschlossen', 'Mit dem Betreiber der Plattform besteht ein Vertrag nach Art. 28 DSGVO. Die Plattform bietet dafür einen Vertragsentwurf an, der bei der Registrierung der Schule akzeptiert wird. Alle Unterauftragsverarbeiter sind bekannt, und der Betreiber hat die Löschfrist für Server-Logfiles und Fehlerprotokoll genannt.', $dpaStatus, $dpa['url'] ? ['Vertragsentwurf ansehen', $dpa['url']] : null),
        $entry('Verzeichnis von Verarbeitungstätigkeiten ergänzt', 'Die Lernplattform steht im Verzeichnis der Schule (Art. 30 DSGVO). Dafür gibt es die Vorlage „Verzeichnis“.'),
        $entry('Notwendigkeit einer Datenschutz-Folgenabschätzung geprüft', 'Für die Grundfunktionen ist die Prüfung dokumentiert, ob eine Folgenabschätzung nach Art. 35 DSGVO nötig ist. Beim Einsatz von KI und für das Aufnahme-Modul ist sie in aller Regel nötig, siehe Teil B.'),
        $entry('Eltern und Schüler:innen informiert', 'Die Elterninformation nach Art. 13 DSGVO ist angepasst und verteilt, die Datenschutzerklärung der Schule verweist auf die Plattform. Dafür gibt es die Vorlage „Elterninformation“.'),
        $entry('Beschäftigte unterwiesen und auf Vertraulichkeit verpflichtet', 'Lehrkräfte und Schuladministration kennen die Regeln: keine privaten Angaben in Notizen, Passwörter nicht weitergeben, Klassencodes nur an die Klasse geben, Konten sorgfältig verwalten.'),
        $entry('Löschfristen festgelegt', 'Die Plattform löscht Unterrichtsstunden samt Abgaben und Gesprächen nach '.$schoolYearsKept.' Schuljahren (das laufende eingerechnet), Aufnahmen nach '.$recordingDays.' Tagen. Konten löscht die Schuladministration, etwa beim Abgang von der Schule. Für Server-Logfiles und Fehlerprotokoll gilt die Frist des Betreibers aus dem Auftragsverarbeitungsvertrag. Die Schule hat geprüft, ob diese Fristen zu ihrem Löschkonzept passen.'),
        $entry('Ansprechpartner und Meldeweg für Datenpannen benannt', 'Es ist klar, wer Anfragen betroffener Personen beantwortet und wer bei einer Datenpanne innerhalb von 72 Stunden die Aufsichtsbehörde informiert (Art. 33 DSGVO). Der Betreiber meldet Vorfälle der Schule unverzüglich.'),
        $entry('Betroffenenrechte vorbereitet', 'Auskunft: Jede Person kann ihre Daten im eigenen Profil unter „Meine Daten herunterladen“ abrufen. Berichtigung und Löschung laufen über die Schuladministration. Die Schule weiß, wie sie Anfragen beantwortet.'),
    ];

    $recording = [
        $entry('Entscheidung der Schule über das Aufnahme-Modul', 'Die Schule hat entschieden, ob und unter welchen Bedingungen Lehrkräfte Unterricht aufnehmen dürfen, zum Beispiel nur zu bestimmten Zwecken und mit Ankündigung. Die Entscheidung ist schriftlich festgehalten, etwa in einer Dienstanweisung. Aufnahmen sind für jede Schule zunächst ausgeschaltet; die Schuladministration schaltet sie unter „Aufnahmen & Einwilligungen“ ein.', $recordingStatus, $recordings['settingsUrl'] ? ['Zu „Aufnahmen & Einwilligungen“', $recordings['settingsUrl']] : null),
        $entry('Datenschutz-Folgenabschätzung durchgeführt', 'Die Vorlage „Folgenabschätzung“ ist von der Schule bewertet, mit der oder dem Datenschutzbeauftragten besprochen und von der Schulleitung freigegeben (Art. 35 DSGVO). Bleibt ein hohes Restrisiko, wurde die Aufsichtsbehörde konsultiert (Art. 36 DSGVO). Die Schuladministration bestätigt das in der Plattform; prüfen kann die Plattform es nicht.'),
        $entry('Personalrat beteiligt, Dienstvereinbarung geprüft', 'Aufnahmen können Verhalten und Leistung von Lehrkräften erfassen. Je nach Personalvertretungsrecht des Landes ist der Personalrat zu beteiligen, gegebenenfalls ist eine Dienstvereinbarung nötig. Der Zweck „keine Kontrolle von Lehrkräften“ ist dort festgeschrieben. Auch das bestätigt die Schuladministration in der Plattform.'),
        $entry('Elterninformation zum Aufnahme-Modul verteilt', 'Der Abschnitt zum Aufnahme-Modul in der Elterninformation wurde nicht gestrichen und ist vor der ersten Aufnahme bei allen Eltern angekommen.'),
        $entry('Einwilligungstext angepasst und veröffentlicht', 'Die Vorlage „Einwilligung“ ist an die Schule und an das Landesrecht angepasst, auch zum Alter, ab dem Schüler:innen selbst unterschreiben. Für die digitale Einwilligung der Eltern wird der Text in der Plattform veröffentlicht; jede Änderung ist eine neue Fassung, und Einwilligungen zu einer älteren Fassung gelten dann als veraltet.'),
        $entry('Einwilligungen aller aufgenommenen Personen liegen vor', 'Die Aufnahme erfasst alle, die im Raum sprechen: die Klasse, die Lehrkraft und eventuell Vertretungen, Gäste oder Begleitpersonen. Das nicht öffentlich gesprochene Wort ohne Einwilligung aufzunehmen kann nach § 201 StGB strafbar sein. '.($consentGateEnforced
        ? 'Die Plattform prüft vor einer Aufnahme die Einwilligungen der Schüler:innen, die die Lehrkraft als anwesend angibt. Einwilligungen von Lehrkräften und weiteren Personen im Raum prüft sie nicht.'
        : 'Die Plattform zeigt je Klasse in der Übersicht „Einwilligungen“, wer gültig eingewilligt hat, sperrt den Start einer Aufnahme aber nicht. Die Lehrkraft prüft die Übersicht vor jeder Aufnahme. Einwilligungen von Lehrkräften und weiteren Personen im Raum kennt die Plattform nicht.')),
        $entry('Ablehnung und Widerruf geregelt', 'Wer nicht einwilligt oder widerruft, hat keine Nachteile. Weil sich einzelne Stimmen nicht aussparen lassen, findet die Aufnahme dann nicht statt. Eltern können über den Link aus dem Elternbrief widerrufen. Die Schule hat festgelegt, wie andere Widerrufe entgegengenommen, dokumentiert und an die Lehrkräfte weitergegeben werden.'),
        $entry('Einwilligungen dokumentiert und aufbewahrt', 'Die Plattform speichert die Einwilligungen der Eltern je Schüler:in mit Fassung, Zwecken, Zeitpunkt, Name der erklärenden Person und Widerruf, digital über den Link im Elternbrief oder von der Schule auf Papier erfasst. Papier-Originale und Einwilligungen von Lehrkräften und weiteren Personen verwaltet die Schule (Nachweis nach Art. 7 Abs. 1 DSGVO).'),
        $entry('Lehrkräfte unterwiesen', 'Die Lehrkräfte kennen die Regeln: vor jeder Aufnahme die Klasse hörbar informieren, nur mit vorliegenden Einwilligungen aufnehmen (Übersicht „Einwilligungen“ der Klasse prüfen), nicht weitergeben, nicht auf private Geräte oder in private Clouds kopieren, Aufnahmen nach der Auswertung selbst löschen, Transkripte vor der Auswertung lesen und Text aus Aufnahmen nur in die Notizen übernehmen oder für die Hausaufgaben-Funktion mit KI verwenden, wenn die Zustimmung zur KI-Auswertung vorliegt. Die Plattform erzwingt diese Punkte nicht, sie hängen an der Unterweisung.'),
        $entry('Löschen bei Abwesenheit der Lehrkraft geregelt', 'Nur die aufnehmende Lehrkraft kann eine Aufnahme über die Plattform löschen, solange sie die Stunde leitet. Es ist festgelegt, wer löscht, wenn sie nicht erreichbar ist oder die Stunde einer anderen Lehrkraft zugewiesen wurde: die Schuladministration löscht die Stunde oder das Konto (dabei entfallen alle zugehörigen Aufnahmen) oder beauftragt den Betreiber auf Weisung der Schule. Spätestens löscht die Plattform nach '.$recordingDays.' Tagen.'),
        $entry('Umgang mit heruntergeladenen Dateien geregelt', 'Die Lehrkraft kann die Audiodatei herunterladen. Was danach mit der Kopie geschieht, kann die Plattform nicht steuern. Die Dienstanweisung sagt, wo sie liegen darf und wann sie zu löschen ist.'),
        $entry('Transkription und KI bewusst entschieden', 'Die Server-Transkription ('.($transcriptionEnabled ? 'derzeit eingeschaltet' : 'derzeit ausgeschaltet').') und die KI-Anbindung ('.($aiEnabled ? 'derzeit eingeschaltet' : 'derzeit ausgeschaltet').') sind nur dann zu nutzen, wenn für den jeweiligen Anbieter ein Vertrag nach Art. 28 DSGVO und, bei Anbietern außerhalb der EU, geeignete Garantien nach Art. 44 ff. DSGVO vorliegen. Die Plattform prüft das nicht. Die KI-Anbindung ersetzt vor dem Senden bei der Auswertung einer Aufnahme, bei der Auswertung von Abgaben und bei den Entwürfen von Hausaufgaben und Förderaufgaben die Namen der Klasse und der Lehrkraft durch Platzhalter; das ist eine technische Hilfe und keine Anonymisierung. Bei der Auswertung einer Abgabe gehen nur Texte an die KI, keine Arbeitsblatt-Dateien (Fotos, PDF); beim Hausaufgaben-Entwurf gehen sie unverändert mit. Im „Erklär’s mir“-Chat wird nichts ersetzt. Die Datenschutzerklärung und die Elterninformation nennen den Anbieter und das.'),
        $entry('Zugriff und Aufbewahrungsfrist geprüft', 'Die Schule weiß, dass nur die Lehrkraft der Stunde die Aufnahme hört, auch nicht die Schuladministration, und dass Aufnahmen nach '.$recordingDays.' Tagen gelöscht werden. Das passt zu ihrer Dienstanweisung, zur Einwilligung und zum Löschkonzept. Die Schuladministration bestätigt die Aufbewahrungsfrist in der Plattform.'),
        $entry('Überprüfung terminiert', 'Folgenabschätzung, Einwilligungstext und Regeln werden regelmäßig überprüft, mindestens einmal im Jahr und bei jeder Änderung der Plattform (Art. 35 Abs. 11 DSGVO). Der nächste Termin steht im Kalender.'),
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Checkliste für die Einführung',
    'lead' => 'Was eine Schule klären sollte, bevor sie die Lernplattform einsetzt, und zusätzlich, bevor Lehrkräfte Unterricht aufnehmen dürfen. Erledigte Punkte werden abgehakt, mit Datum und Namen.',
])

<dl class="pd-facts">
    <dt>Schule</dt>
    <dd>{{ $school['name'] ?? $blank }}</dd>

    <dt>Stand der Checkliste</dt>
    <dd>{{ $printedOn }}</dd>
</dl>

<h3>Teil A: Einsatz der Lernplattform</h3>
<p>Diese Punkte gelten für die Plattform insgesamt, auch ohne Aufnahme-Modul.</p>

@include('filament.pages.privacy-documents.partials.tasks', ['tasks' => $general])

<h3>Teil B: Zusätzlich vor dem Aufnahme-Modul</h3>
<p>
    Das Aufnahme-Modul nimmt nur Ton auf. Aufnahmen betreffen aber Kinder, sie halten gesprochene Worte fest, und sie können zu Transkripten und KI-Auswertungen führen. Deshalb gelten besondere Anforderungen. Solange nicht alle Punkte erledigt sind, sollte keine Lehrkraft aufnehmen.
</p>

@include('filament.pages.privacy-documents.partials.tasks', ['tasks' => $recording])

<h3>Ansprechpartner</h3>
<dl class="pd-facts">
    <dt>Datenschutz an der Schule</dt>
    <dd>{{ $blank }}</dd>

    <dt>Plattform-Betrieb</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>

    <dt>Aufsichtsbehörde</dt>
    <dd>Die oder der Landesbeauftragte für den Datenschutz des Landes{{ filled($school['state']) ? ' '.$school['state'] : '' }}: {{ $blank }}</dd>
</dl>
