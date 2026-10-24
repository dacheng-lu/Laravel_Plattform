@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Elterninformation zur Lernplattform (Art. 13 DSGVO)',
    'lead' => 'Information der Eltern und Erziehungsberechtigten über die Verarbeitung personenbezogener Daten ihrer Kinder bei der Lernplattform, einschließlich des optionalen Aufnahme-Moduls. Der Text ist an Eltern gerichtet und verwendet deshalb die Anrede „Sie“.',
])

<p>
    Sehr geehrte Eltern und Erziehungsberechtigte,
</p>
<p>
    {{ $school['name'] ?? $blank }} setzt im Unterricht die Lernplattform ein. Lehrkräfte halten dort fest, was sie behandelt haben, und verteilen daraus Hausaufgaben. Ihr Kind sieht, was es schon verstanden hat und was es noch üben sollte. Mit diesem Schreiben informieren wir Sie darüber, welche Daten dabei verarbeitet werden und welche Rechte Sie und Ihr Kind haben.
</p>

<h3>1. Wer ist verantwortlich?</h3>
@include('filament.pages.privacy-documents.partials.school')

<p>
    Die Plattform wird von der folgenden Firma technisch betrieben. Sie verarbeitet die Daten nur nach Weisung der Schule und auf Grundlage eines Vertrags nach Art. 28 DSGVO (Auftragsverarbeitung):
</p>
@include('filament.pages.privacy-documents.partials.operator')

<h3>2. Wozu verarbeiten wir Daten, und auf welcher Grundlage?</h3>
<p>
    Die Lernplattform unterstützt den Unterricht und das Lernen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes{{ filled($school['state']) ? ' '.$school['state'] : '' }}, weil die Verarbeitung dem Bildungs- und Erziehungsauftrag der Schule dient. Genaue Vorschrift: {{ $blank }}
</p>

<h3>3. Welche Daten werden verarbeitet?</h3>
<ul>
    <li>Name, Benutzername, Klasse und auf Wunsch eine E-Mail-Adresse. Das Passwort wird nur verschlüsselt (als Hash) gespeichert.</li>
    <li>Was Ihr Kind abgibt: Zusammenfassungen, Antworten auf Hausaufgaben und Gespräche mit der Übungsfigur „Erklär’s mir“.</li>
    <li>Das Ergebnis der Auswertung: ein Verständniswert von 0 bis 100 mit Rückmeldung. Er ist ein Hinweis, wo Unterstützung nötig sein könnte, und keine Note.</li>
    <li>Bei einer Antwort, die noch nicht ganz richtig ist, kann die Plattform einen Hinweis zur Fehlerart speichern, zum Beispiel „Grundidee noch unklar“ oder „Weg/Schritt falsch“, dazu die Quelle des Hinweises (Regeln der Plattform, KI oder Lehrkraft) und, wenn eine KI eingeordnet hat, wie sicher sie sich war. Der Hinweis ist keine Note, ändert keine Punktzahl und erscheint nicht im Bericht für das Elterngespräch.</li>
    <li>Technische Daten, die beim Anmelden anfallen, etwa die Sitzungskennung und die IP-Adresse.</li>
</ul>
<p>Die E-Mail-Adresse ist freiwillig. Ohne Name und Benutzername kann Ihr Kind die Plattform nicht nutzen. Ob die Nutzung im Unterricht verbindlich ist, entscheidet die Schule.</p>

<h3>4. Wer sieht die Daten?</h3>
<ul>
    <li>Ihr Kind sieht die eigenen Daten. Gespräche in „Erklär’s mir“ zu einem frei gewählten Thema sieht nur Ihr Kind.</li>
    <li>Lehrkräfte sehen die Abgaben und Ergebnisse zu ihren Stunden, und für die Klassen, die sie leiten oder unterrichten, den Lernstand der Schüler:innen.</li>
    <li>Die Schuladministration der Schule kann die Daten der Schule einsehen, mit zwei Ausnahmen: Aufnahmen (siehe Abschnitt 7) und Gespräche in „Erklär’s mir“ zu einem frei gewählten Thema.</li>
    <li>Andere Schüler:innen und andere Schulen sehen nie etwas. Die Plattform-Administration des Betreibers sieht keine Unterrichts- und Lerndaten.</li>
</ul>
<p>Der Betreiber hat technisch Zugriff auf die Server, soweit es für Betrieb, Wartung und Fehlersuche nötig ist. Er wertet Unterrichts- und Lerndaten nicht aus. Für den Serverbetrieb (Hosting) setzt er technische Dienstleister als Unterauftragsverarbeiter ein, die nur nach seiner Weisung arbeiten: {{ $blank }} Ist die KI eingeschaltet, ist der KI-Anbieter (Abschnitt 5) ein weiterer Empfänger.</p>

<h3>5. Künstliche Intelligenz</h3>
@if ($aiEnabled)
    <p>
        Die Plattform wertet mit Hilfe von KI aus, um Hausaufgaben zu entwerfen, Antworten einzuschätzen und das Gespräch in „Erklär’s mir“ zu führen. Dafür werden {{ $aiData }} an Anthropic PBC in den USA übermittelt. Name und Benutzerkennung des Kontos werden nicht mitgeschickt. Bei der Auswertung einer Abgabe und beim Hausaufgaben-Entwurf ersetzt die Plattform vorher die Namen der Klasse und der Lehrkraft durch Platzhalter. Das ist eine technische Hilfe und keine Anonymisierung: Namen, die nicht zur Klasse gehören (etwa von Eltern oder anderen Lehrkräften), Spitznamen und Namen auf Arbeitsblättern bleiben im Text. Bitte nennen Sie und Ihr Kind deshalb keine Namen anderer Personen. Für die Fehlerart einer Antwort gehen Frage, Erwartungshorizont und Antworttext mit; hochgeladene Arbeitsblätter gehen unverändert mit und können Namen enthalten. Im Gespräch mit „Erklär’s mir“ ersetzt die Plattform nichts. Für die Übermittlung in die USA sind geeignete Garantien nach Art. 44 ff. DSGVO nötig. Grundlage: {{ $blank }} Eine Kopie erhalten Sie auf Anfrage bei der Schule.
    </p>
@else
    <p>
        Derzeit läuft die Auswertung ohne externe KI mit einer einfachen Regel-Logik auf dem Server der Plattform. Es werden keine Daten an Dritte übermittelt. Sollte die Schule später KI-Dienste einschalten, informiert sie Sie vorher.
    </p>
@endif
<p>
    Es gibt keine automatisierte Entscheidung im Sinne von Art. 22 DSGVO. Die KI kann sich irren. Lehrkräfte entscheiden, was aus den Ergebnissen folgt.
</p>

<h3>6. Wie lange werden die Daten gespeichert?</h3>
<ul>
    <li>Unterrichtsstunden mit Abgaben und Gesprächen löscht die Plattform automatisch nach {{ $schoolYearsKept }} Schuljahren (das laufende eingerechnet).</li>
    <li>Das Konto Ihres Kindes löscht die Schule, wenn es nicht mehr gebraucht wird, zum Beispiel beim Wechsel der Schule.</li>
    <li>Eine Sitzung endet nach {{ $sessionMinutes }} Minuten ohne Aktivität.</li>
    <li>Technische Protokolle des Servers (zum Beispiel mit der IP-Adresse) löscht der Betreiber nach {{ $blank }} Tagen.</li>
</ul>

<h3>7. Aufnahme-Modul: Ton im Unterricht</h3>
<div class="pd-notice" role="note">
    <p>[Nur verwenden, wenn die Schule das Aufnahme-Modul einführt. Sonst diesen Abschnitt streichen. Die Aufnahme setzt eine Einwilligung voraus, siehe Vorlage „Einwilligung“.]</p>
</div>

<p>
    Lehrkräfte können im Unterricht den Ton einer Stunde aufnehmen. Es wird ausschließlich Ton aufgenommen, kein Video und kein Bild. Eine Aufnahme dient allein dazu, der Lehrkraft Notizen, eine Zusammenfassung und einen Tafelbild-Vorschlag für die Stunde zu liefern. Sie dient nicht der Kontrolle von Schüler:innen oder Lehrkräften und nicht der Benotung.
</p>

<h4>Freiwillig und nur mit Einwilligung</h4>
<ul>
    <li>Aufgenommen wird nur, wenn alle Personen im Raum eingewilligt haben, auch die Lehrkraft. Eine einzelne Stimme lässt sich aus einer Aufnahme nicht herausnehmen. {{ $consentGateEnforced ? 'Ob für die anwesenden Schüler:innen eine gültige Einwilligung der Eltern vorliegt, prüft die Plattform vor jeder Aufnahme.' : 'Ob für die anwesenden Schüler:innen eine gültige Einwilligung der Eltern vorliegt, prüft die Lehrkraft vor jeder Aufnahme anhand der Übersicht in der Plattform.' }}</li>
    <li>Sie willigen über den persönlichen Link im Elternbrief oder auf Papier ein. Sie können dabei jedem der drei Zwecke einzeln zustimmen oder nicht.</li>
    <li>Die Einwilligung ist freiwillig. Wer nicht einwilligt, hat keine Nachteile. Dann findet keine Aufnahme statt.</li>
    <li>Sie können die Einwilligung jederzeit ohne Angabe von Gründen widerrufen, mit Wirkung für die Zukunft, über den persönlichen Link aus dem Elternbrief, mit dem Sie eingewilligt haben, oder formlos bei {{ $blank }}. Danach wird nicht mehr aufgenommen, und bestehende Aufnahmen, auf denen Ihr Kind zu hören sein kann, werden auf Wunsch gelöscht. Der Widerruf berührt nicht die Rechtmäßigkeit der bisherigen Verarbeitung.</li>
    <li>Rechtsgrundlage ist Ihre Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen. Dafür holen wir Ihre ausdrückliche Einwilligung ein (Art. 9 Abs. 2 lit. a DSGVO). Je nach Alter und Einsichtsfähigkeit willigt Ihr Kind zusätzlich selbst ein.</li>
</ul>

<h4>Was mit der Aufnahme geschieht</h4>
<ul>
    <li>Vor jeder Aufnahme bestätigt die Lehrkraft in der Plattform, dass die Klasse informiert ist. Während der Aufnahme zeigt die Plattform das deutlich an.</li>
    <li>Nur die aufnehmende Lehrkraft kann die Aufnahme hören. Schulleitung, Schuladministration, andere Lehrkräfte, andere Schüler:innen und die Plattform-Administration des Betreibers können sie in der Plattform nicht hören.</li>
    <li>Die Aufnahme wird nach {{ $recordingDays }} Tagen automatisch gelöscht, die aufnehmende Lehrkraft kann sie früher löschen.</li>
    <li>Text, den die Lehrkraft aus einer Aufnahme in ihre Unterrichtsnotizen übernimmt (Namen der Klasse durch Platzhalter ersetzt), wird nicht nach {{ $recordingDays }} Tagen gelöscht, sondern folgt den Fristen der Notizen ({{ $schoolYearsKept }} Schuljahre). Nutzt die Lehrkraft damit die Hausaufgaben-Funktion mit KI, geht dieser Text auch an den KI-Anbieter. Die Schule weist die Lehrkräfte an, das nur zu tun, wenn die Zustimmung zur KI-Auswertung vorliegt.</li>
    <li>Der Ton wird nie an eine KI gesendet. Die Einwilligung unterscheidet drei Zwecke, denen Sie einzeln zustimmen können: die Tonaufnahme, das Umwandeln in einen Text (Transkript) und die Auswertung des Textes durch KI. Vor der KI-Auswertung einer Aufnahme werden die Namen der Klasse durch Platzhalter ersetzt. Das ist eine technische Hilfe und keine vollständige Anonymisierung.</li>
    <li>Derzeit ist die Texterkennung auf dem Server {{ $transcriptionEnabled ? 'eingeschaltet' : 'ausgeschaltet' }}, und die KI-Anbindung ist {{ $aiEnabled ? 'eingeschaltet' : 'ausgeschaltet' }}.</li>
</ul>

<h3>8. Ihre Rechte und die Rechte Ihres Kindes</h3>
<p>Sie und Ihr Kind haben nach der DSGVO folgende Rechte. Ihr Kind kann sie je nach Alter und Einsichtsfähigkeit selbst ausüben.</p>
<ul>
    <li>Auskunft über die gespeicherten Daten (Art. 15). Jede Person, auch Ihr Kind, kann die eigenen Daten im Profil der Plattform herunterladen.</li>
    <li>Berichtigung unrichtiger Daten (Art. 16).</li>
    <li>Löschung (Art. 17) und Einschränkung der Verarbeitung (Art. 18).</li>
    <li>Datenübertragbarkeit (Art. 20), soweit die Verarbeitung auf Einwilligung beruht.</li>
    <li>Widerspruch gegen die Verarbeitung aus Gründen, die sich aus Ihrer besonderen Situation ergeben (Art. 21), soweit sie auf Art. 6 Abs. 1 lit. e DSGVO beruht.</li>
    <li>Widerruf einer erteilten Einwilligung jederzeit (Art. 7 Abs. 3).</li>
    <li>Beschwerde bei einer Aufsichtsbehörde (Art. 77), in {{ filled($school['state']) ? $school['state'] : 'Ihrem Bundesland' }} bei der oder dem Landesbeauftragten für den Datenschutz: {{ $blank }}</li>
</ul>

<h3>9. Fragen</h3>
<p>
    Fragen beantworten die Schule und die oder der Datenschutzbeauftragte der Schule (Kontaktdaten oben).
</p>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Schulleitung'],
])
