@php
    $aiStatus = $aiEnabled
        ? "eingeschaltet: {$aiData} gehen an Anthropic PBC (USA). Name und Benutzerkennung des Kontos werden nicht mitgesendet, Namen im Freitext oder auf Arbeitsblättern dagegen schon"
        : 'ausgeschaltet: keine Daten verlassen die Plattform';
    $transcriptionStatus = $transcriptionEnabled
        ? 'eingeschaltet: Audio geht zur Texterkennung an den eingerichteten Dienst'
        : 'ausgeschaltet: kein Audio verlässt die Plattform';

    $consentCheckOfPlatform = $consentGateEnforced
        ? 'Die Plattform lässt eine Aufnahme nur zu, wenn für alle anwesenden Schüler:innen eine gültige Einwilligung der Eltern zur aktuellen Fassung vorliegt, die die benötigten Zwecke abdeckt. Widerruft jemand, sperrt das künftige Aufnahmen.'
        : 'Die Plattform erfasst die Einwilligungen je Schüler:in und zeigt der Lehrkraft in der Übersicht „Einwilligungen“ der Klasse, wer gültig eingewilligt hat. Grenze: Sie sperrt den Start einer Aufnahme in dieser Version nicht, wenn Einwilligungen fehlen oder widerrufen sind. Das Prüfen vor jeder Aufnahme ist Pflicht der Lehrkraft und gehört in die Dienstanweisung.';

    $criteria = [
        ['Daten von Kindern', 'Schüler:innen sind überwiegend minderjährig und besonders schutzbedürftig.', true],
        ['Systematische Aufzeichnung gesprochener Sprache', 'Das Aufnahme-Modul hält Unterricht als Ton fest, sobald eine Lehrkraft es nutzt.', true],
        ['Besondere Kategorien personenbezogener Daten möglich', 'Im aufgenommenen Ton können zufällig Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen (Art. 9 DSGVO). Die Plattform kann sie weder erkennen noch ausfiltern.', true],
        ['Bewertung persönlicher Aspekte', 'Der Verständniswert (0 bis 100) ist eine KI-gestützte Einschätzung zu einzelnen Schüler:innen. Er ist keine Note.', true],
        ['Neue Technologien', 'KI-Auswertung und Spracherkennung. Beides ist ab Werk ausgeschaltet; die Schule bewertet es für den Fall der Aktivierung mit.', true],
        ['Automatisierte Einzelentscheidung mit Rechtswirkung', 'Gibt es nicht. Menschen entscheiden, was aus den Ergebnissen folgt.', false],
    ];

    $dataCategories = [
        [
            'title' => 'Kontodaten',
            'data' => 'Name, Benutzername, auf Wunsch E-Mail-Adresse, Rolle, Schule, Klasse. Das Passwort nur als Hash.',
            'people' => 'Schüler:innen, Lehrkräfte, Schuladministration',
            'access' => 'Schuladministration der Schule. Lehrkräfte sehen die Namen der Schüler:innen ihrer Klassen. Die Plattform-Administration sieht nur Administrationskonten.',
            'retention' => 'Bis die Schule das Konto löscht.',
        ],
        [
            'title' => 'Unterrichtsnotizen, Arbeitsblätter, Hausaufgaben',
            'data' => 'Fach, Thema, Datum, Notizen, hochgeladene Arbeitsblätter, Zusammenfassung, Fragen. Das trägt die Lehrkraft selbst ein.',
            'people' => 'Lehrkräfte; nur zufällig genannte Dritte',
            'access' => 'Lehrkraft der Stunde und Schuladministration. Schüler:innen sehen veröffentlichte Hausaufgaben.',
            'retention' => "Automatische Löschung nach {$schoolYearsKept} Schuljahren (das laufende eingerechnet).",
        ],
        [
            'title' => 'Abgaben, Verständniswerte, „Erklär’s mir“-Gespräche',
            'data' => 'Antworten, Zusammenfassungen, Gesprächsverlauf, Verständniswert, Rückmeldung.',
            'people' => 'Schüler:innen',
            'access' => 'Abgaben und Gespräche zu einer Stunde: die Schüler:in selbst, die Lehrkraft der Stunde und die Schuladministration. Gespräche zu einem frei gewählten Thema: nur die Schüler:in selbst. Nie andere Schüler:innen.',
            'retention' => "Automatische Löschung nach {$schoolYearsKept} Schuljahren (das laufende eingerechnet).",
        ],
        [
            'title' => 'Fehlerart einer Antwort (Hinweis)',
            'data' => 'Fehlerart (Grundidee noch unklar, Weg/Schritt falsch, unvollständig, am Thema vorbei, kein Versuch), Sicherheit der Einordnung von 0 bis 100 (nur bei einer Einordnung durch KI) und Quelle (Demo-Regeln, KI oder Lehrkraft). Gespeichert wird nur bei Antworten unter der eingestellten Punktgrenze und nur oberhalb der Mindestsicherheit; unsichere Einordnungen werden nicht gespeichert. Ordnet die KI ein, gehen Frage, Erwartungshorizont und Antworttext an den KI-Anbieter, mit den Namen der Klasse und der Lehrkraft vorher durch Platzhalter ersetzt; hochgeladene Arbeitsblatt-Dateien gehen unverändert mit.',
            'people' => 'Schüler:innen',
            'access' => 'Die Schüler:in sieht die eigenen Hinweise in der Rückmeldung zur Hausaufgabe. Die Lehrkraft der Klasse sieht die Verteilung je Kompetenz und den Einzelhinweis neben der Antwort, gekennzeichnet als „KI-Hinweis, kann falsch sein“, sowie die von einer Lehrkraft eingeordneten Fälle. Nie andere Schüler:innen, nie eine andere Schule.',
            'retention' => "Mit der Antwort, also automatisch nach {$schoolYearsKept} Schuljahren (das laufende eingerechnet). Die Lehrkraft kann eine Fehlerart jederzeit korrigieren oder zurücknehmen.",
        ],
        [
            'title' => 'Tonaufnahme einer Stunde (Aufnahme-Modul, optional)',
            'data' => 'Stimmen und Äußerungen aller, die im Raum sprechen. Dazu Beginn, Dauer, aufnehmende Lehrkraft und der Zeitpunkt der Bestätigung vor der Aufnahme. Gesprochenes kann zufällig auch besondere Kategorien nach Art. 9 DSGVO berühren (Gesundheit, Herkunft, Religion, Familie).',
            'people' => 'Schüler:innen (minderjährig), Lehrkraft, weitere anwesende Personen',
            'access' => 'Nur die aufnehmende Lehrkraft. Nicht die Schuladministration, nicht andere Lehrkräfte, nicht Schüler:innen, nicht die Plattform-Administration.',
            'retention' => "{$recordingDays} Tage ab Aufnahmebeginn, dann automatische Löschung von Datei und Eintrag. Über die Plattform löschen kann nur die aufnehmende Lehrkraft, solange sie die Stunde leitet (siehe R10).",
        ],
        [
            'title' => 'Transkript und Auswertung der Aufnahme (optional)',
            'data' => 'Text des Gesprochenen, Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag, Fragen aus der Klasse. Der Text kann von der Lehrkraft eingegeben, live im Browser erkannt oder, falls eingeschaltet, durch einen Dienst erzeugt sein.',
            'people' => 'wie die Aufnahme',
            'access' => 'Nur die aufnehmende Lehrkraft.',
            'retention' => "Zusammen mit der Aufnahme. Teile, die die Lehrkraft in ihre Unterrichtsnotizen übernimmt (Namen der Klasse vorher durch Platzhalter ersetzt), folgen den Fristen der Notizen ({$schoolYearsKept} Schuljahre) und gehen bei „Hausaufgabe mit KI erstellen“ an den KI-Anbieter, auch wenn der Auswertung der Aufnahme nicht zugestimmt wurde.",
        ],
        [
            'title' => 'Einwilligungen für Aufnahmen (optional)',
            'data' => 'Pro Schüler:in: Name der erklärenden Person, Zwecke (Tonaufnahme, Transkript, KI-Auswertung), Fassung des Einwilligungstextes, Zeitpunkt, Art (digital oder Papier), Widerruf, auf Wunsch die eigene Bestätigung der Schüler:in. Der Link zur Einwilligung wird nur als Hash gespeichert.',
            'people' => 'Erziehungsberechtigte, Schüler:innen',
            'access' => 'Schuladministration und die Lehrkräfte der jeweiligen Klasse. Schüler:innen nur die eigene Bestätigung. Die Plattform-Administration sieht keine Einwilligungen.',
            'retention' => 'Solange das Konto der Schüler:in besteht; mit dem Konto werden auch die Einwilligungen gelöscht. Die Schule bewahrt Papier-Originale nach ihren eigenen Fristen auf.',
        ],
        [
            'title' => 'Technische Daten',
            'data' => 'Sitzungskennung, IP-Adresse, Browserangabe, Zeitpunkt der letzten Aktivität, Server-Logfiles, Fehlerprotokoll.',
            'people' => 'alle Nutzenden',
            'access' => 'Betreiber, nur für Betrieb, Sicherheit und Fehlersuche.',
            'retention' => "Sitzung {$sessionMinutes} Minuten ohne Aktivität; Logfiles nach der Datenschutzerklärung der Plattform.",
        ],
    ];

    $risks = [
        [
            'title' => 'Verhaltensanpassung durch Überwachungsdruck',
            'impact' => 'Schüler:innen und Lehrkräfte sprechen nicht mehr frei, stellen weniger Fragen oder äußern sich vorsichtiger, weil sie sich beobachtet fühlen (Chilling Effect). Das beeinträchtigt Unterricht und die freie Entfaltung der Persönlichkeit, bei Kindern besonders.',
            'measures' => [
                'Aufnahme ist die bewusste Ausnahme einer Lehrkraft für eine Stunde. Es gibt keine Dauer- oder Hintergrundaufnahme.',
                'Nur Ton, kein Video und kein Bild.',
                'Eine feste Leiste am oberen Bildschirmrand zeigt auf dem Gerät der Lehrkraft die ganze Zeit an, dass aufgenommen wird.',
                'Schuladministration und andere Lehrkräfte haben keinen Zugriff. Die Ergebnisse sind für die Lehrkraft gedacht, nicht zur Kontrolle.',
            ],
        ],
        [
            'title' => 'Missbrauch und Weitergabe von Aufnahmen',
            'impact' => 'Stimmen von Kindern werden veröffentlicht, in Messengern geteilt, für Mobbing oder zur Bloßstellung genutzt. Aufnahmen dienen der Leistungs- oder Verhaltenskontrolle von Lehrkräften.',
            'measures' => [
                'Zugriff nur für die aufnehmende Lehrkraft (Richtlinie der Plattform, serverseitig bei jedem Abruf geprüft).',
                'Die Datei ist nicht öffentlich abrufbar, sondern nur über eine geprüfte Adresse, mit zufälligem Dateinamen.',
                "Kurze Frist: automatische Löschung nach {$recordingDays} Tagen; die Lehrkraft kann jederzeit selbst löschen.",
                'Grenze: Die Lehrkraft kann die Datei herunterladen. Was mit der Kopie geschieht, kann die Plattform nicht steuern. Das regelt die Dienstanweisung der Schule.',
            ],
        ],
        [
            'title' => 'Unbefugter Zugriff',
            'impact' => 'Fremde hören Aufnahmen oder lesen Transkripte, etwa durch übernommene Konten, geteilte Passwörter, geteilte Geräte oder Schwachstellen. Die Äußerungen von Kindern werden offengelegt.',
            'measures' => [
                'Passwörter werden nur als Hash gespeichert. Eine Sitzung läuft nach '.$sessionMinutes.' Minuten ohne Aktivität ab.',
                'Jede Schule sieht nur ihre Daten. Die Plattform-Administration hat keine Einsicht in Unterrichts- und Lerndaten oder Aufnahmen.',
                'Aufnahmen funktionieren nur über eine gesicherte Verbindung (HTTPS). Anfragen sind gegen Fälschung geschützt (CSRF).',
                'Grenze: Die Anwendung verschlüsselt die Audiodateien nicht zusätzlich. Server, Datenträger und Sicherungen schützt der Betrieb; das beschreibt die Anlage zu den technischen und organisatorischen Maßnahmen des Auftragsverarbeitungsvertrags.',
            ],
        ],
        [
            'title' => 'Wiedererkennung trotz Pseudonymisierung',
            'impact' => 'Personen werden aus Transkripten, Auswertungen oder der Stimme erkannt, obwohl Namen ersetzt wurden: durch Namen, die nicht bekannt sind (Eltern, andere Lehrkräfte, Spitznamen), durch Erkennungsfehler der Spracherkennung, durch Inhalte oder durch die Stimme selbst.',
            'measures' => [
                'Bei der Auswertung einer Aufnahme ersetzt die Plattform vor der KI die Namen der Klasse und der Lehrkraft durch Platzhalter („Schüler:in A“, „Lehrkraft“). Das gilt auch für Text aus der Aufnahme, der in die Unterrichtsnotizen übernommen wird.',
                'Nach der Transkription weist die Plattform darauf hin, das Transkript zu lesen und zu korrigieren. Die Plattform erzwingt das nicht; verbindlich macht es die Dienstanweisung der Schule.',
                'Die KI bekommt die Anweisung, Personen nie zu erraten oder zu identifizieren.',
                'Audio geht nie an die KI. Die Stimme wird nicht zur Identifizierung ausgewertet.',
                'Grenze: Die Ersetzung ist eine technische Hilfe und keine Anonymisierung. Die Schule geht von personenbezogenen Daten aus.',
                'Grenze: Ersetzt wird nur bei der Auswertung einer Aufnahme und beim Übernehmen in die Notizen. Notizen, die die Lehrkraft selbst schreibt, Arbeitsblatt-Dateien, Antworten und Gespräche gehen bei eingeschalteter KI unverändert an den Anbieter und können Namen enthalten.',
            ],
        ],
        [
            'title' => 'Fehler und Verzerrungen der KI',
            'impact' => 'Zusammenfassungen oder Einschätzungen sind falsch, unvollständig oder verzerrt. Ein Verständniswert wird wie eine Note gelesen, Empfehlungen werden ungeprüft übernommen (Automatisierungsneigung), Kinder werden dadurch benachteiligt.',
            'measures' => [
                'Es gibt keine automatisierte Entscheidung im Sinne von Art. 22 DSGVO. Ergebnisse sind als Hinweise gekennzeichnet.',
                'Der Verständniswert ist ausdrücklich keine Note. Die Lehrkraft entscheidet, was daraus folgt.',
                'Die Plattform weist nach der Transkription darauf hin, das Transkript vor der Auswertung zu lesen; verbindlich macht das die Dienstanweisung. Die Auswertung der Aufnahme nutzt nur Text.',
                'Die Fehlerart einer Antwort ist ein Hinweis zur Antwort, nicht zur Person: Sie ändert weder Punktzahl noch Note, erscheint nicht im Elternbericht und wird unterhalb der eingestellten Mindestsicherheit gar nicht gespeichert. Die Lehrkraft kann sie korrigieren oder zurücknehmen; ihre Einordnung hat Vorrang.',
                'KI-Auswertung und Server-Transkription sind ab Werk ausgeschaltet.',
            ],
        ],
        [
            'title' => 'Übermittlung an Anbieter, besonders in Drittländer',
            'impact' => 'Texte oder Audio gelangen an Dienstleister außerhalb der Kontrolle der Schule, möglicherweise in die USA, ohne ausreichende Garantien oder ohne Vertrag.',
            'measures' => [
                'Standardmäßig verlässt nichts die Plattform: KI-Anbindung und Server-Transkription sind ausgeschaltet. Aktuell: KI '.($aiEnabled ? 'eingeschaltet' : 'ausgeschaltet').', Server-Transkription '.($transcriptionEnabled ? 'eingeschaltet' : 'ausgeschaltet').'.',
                'Sie werden nur vom Betreiber über die Serverkonfiguration eingeschaltet, nicht von Lehrkräften. Die Plattform prüft dabei nicht, ob Vertrag und Garantien vorliegen. Das klärt der Betreiber vor dem Einschalten, und die Schule lässt es sich bestätigen (Checkliste).',
                'Die Live-Texterkennung läuft ausschließlich lokal im Browser. Steht sie dort nicht lokal zur Verfügung, wird sie nicht genutzt; es gibt keinen Rückfall auf einen Cloud-Dienst.',
                'Audio geht nie an die KI. Die Auswertung einer Aufnahme sendet nur Text und nur pseudonymisiert.',
                "Grenze: Die übrigen KI-Funktionen (Hausaufgaben-Entwurf, Auswertung von Abgaben, „Erklär’s mir“) senden {$aiData} ohne Ersetzung von Namen.",
            ],
        ],
        [
            'title' => 'Aufnahme ohne wirksame Einwilligung',
            'impact' => 'Jemand wird aufgenommen, ohne einzuwilligen, oder die Einwilligung ist unwirksam, weil sie nicht freiwillig war (Abhängigkeit, Gruppendruck) oder nicht informiert. Das ist unzulässig und kann nach § 201 StGB strafbar sein. Ein Widerruf lässt sich bei einer Klassenaufnahme nicht auf eine Stimme begrenzen.',
            'measures' => [
                'Aufnahmen sind je Schule ausgeschaltet, bis die Schuladministration sie einschaltet; vorher lässt die Plattform keine Aufnahme starten. '.$consentCheckOfPlatform,
                'Vor jeder Aufnahme muss die Lehrkraft in einem Dialog bestätigen, dass die Klasse informiert ist. Der Server verlangt die Bestätigung und speichert den Zeitpunkt. Danach, und erst danach, fragt der Browser nach dem Mikrofon.',
                'Grenze: Die Plattform kennt nur die Einwilligungen der Schüler:innen, nicht die von Lehrkräften und weiteren Personen im Raum. Sie kann nicht beurteilen, ob eine Einwilligung wirklich freiwillig zustande kam, etwa ohne Gruppendruck. Sie führt keine Anwesenheitsliste; wer anwesend ist, gibt die Lehrkraft an.',
                'Grenze: Bei einem Widerruf bleibt nur, die ganze Aufnahme zu löschen oder künftig nicht aufzunehmen. Einholen von Einwilligungen auf Papier und Umsetzen von Widerrufen anderer Personen ist Aufgabe der Schule (Vorlage „Einwilligung“).',
            ],
        ],
        [
            'title' => 'Zu lange Speicherung, unvollständige Löschung',
            'impact' => 'Aufnahmen oder Kopien bleiben länger gespeichert als nötig: in Sicherungen, in heruntergeladenen Dateien, in übernommenen Notizen. Sie können nach einem Widerruf nicht mehr gefunden werden.',
            'measures' => [
                "Aufnahmen werden täglich automatisch nach {$recordingDays} Tagen gelöscht, Datei und Eintrag zusammen. Verwaiste Dateien ohne Eintrag werden ebenfalls entfernt.",
                'Wird eine Stunde, ein Konto oder eine Schule gelöscht, entfallen die zugehörigen Einträge sofort. Übrig gebliebene Dateien räumt die tägliche Löschung nach spätestens etwa zwei Tagen ab.',
                'Grenze: Sicherungen des Betreibers, heruntergeladene Kopien und von der Lehrkraft übernommener Text folgen eigenen Fristen. Die Schule klärt das mit dem Betreiber (Auftragsverarbeitungsvertrag) und in der Dienstanweisung.',
            ],
        ],
        [
            'title' => 'Zweckentfremdung',
            'impact' => 'Aufnahmen oder Transkripte werden für andere Zwecke verwendet als vereinbart, etwa zur Beurteilung von Lehrkräften, als Beweismittel in Streitfällen oder für Disziplinarmaßnahmen.',
            'measures' => [
                'Die Plattform gibt Aufnahmen nur der aufnehmenden Lehrkraft. Die Schulleitung kann sie nicht über die Plattform abrufen.',
                'Der Zweck ist in Einwilligung und Elterninformation festgelegt. Die Dienstvereinbarung kann ihn für Beschäftigte festschreiben.',
                'Grenze: Wer die Datei herunterlädt oder weitergibt, verlässt den geschützten Raum der Plattform. Verstöße gegen die Zweckbindung regelt die Schule dienstrechtlich.',
            ],
        ],
        [
            'title' => 'Betroffenenrechte nicht erfüllbar',
            'impact' => 'Betroffene können Auskunft, Berichtigung, Löschung oder Widerruf nicht durchsetzen, weil niemand weiß, wo Daten liegen oder wer zuständig ist.',
            'measures' => [
                'Jede Person kann ihre Daten im Profil herunterladen. Die Schuladministration kann Konten und damit zugehörige Daten löschen. Die aufnehmende Lehrkraft löscht Aufnahmen selbst.',
                "Grenze: Nur die aufnehmende Lehrkraft kann eine Aufnahme über die Plattform löschen, solange sie die Stunde leitet. Ist sie nicht erreichbar oder wurde die Stunde einer anderen Lehrkraft zugewiesen, kann die Schuladministration die Aufnahme nur löschen, indem sie die Stunde oder das Konto der Lehrkraft löscht (dabei entfallen alle zugehörigen Aufnahmen), oder sie beauftragt den Betreiber. Spätestens löscht die Plattform automatisch nach {$recordingDays} Tagen.",
                'Die Elterninformation benennt Ansprechpartner und Rechte.',
                'Grenze: Eine einzelne Stimme lässt sich nicht aus einer Aufnahme herauslösen. Auskunft zu Aufnahmen gibt die Schule über die aufnehmende Lehrkraft.',
            ],
        ],
    ];

    $platformMeasures = [
        'Trennung der Schulen' => [
            'Jede Schule sieht nur ihre eigenen Klassen, Konten, Stunden, Abgaben, Gespräche und Aufnahmen. Schulen sehen nichts voneinander.',
            'Die Plattform-Administration betreut die Plattform und die Schulen. Sie sieht keine Unterrichts- und Lerndaten und keine Aufnahmen.',
        ],
        'Rollen und Rechte' => [
            'Vier Rollen mit festen Rechten: Plattform-Administration, Schuladministration, Lehrkraft, Schüler:in. Die Prüfung erfolgt serverseitig bei jedem Abruf. Die Seite „Rollen & Rechte“ macht sie sichtbar.',
            'Aufnahmen gehören der Lehrkraft der Stunde allein: Hören, Herunterladen, Transkript, Auswertung und Löschen. Wechselt die Lehrkraft der Stunde, verliert die bisherige den Zugriff, ohne dass die neue ihn erhält.',
        ],
        'Einwilligung und Transparenz' => [
            'Aufnahmen sind für jede Schule zunächst ausgeschaltet. Die Schuladministration schaltet sie erst ein, nachdem sie fünf Punkte bestätigt hat (Folgenabschätzung, Personalrat, Eltern informiert, Einwilligungserklärung freigegeben, Aufbewahrungsfrist) und eine Einwilligungserklärung veröffentlicht hat. Die Plattform kann nicht prüfen, ob diese Bestätigungen stimmen. In einer Schule ohne Freischaltung lässt sie keine Aufnahme starten.',
            'Eltern willigen je Schüler:in über einen persönlichen Link oder auf Papier ein, getrennt nach Zweck (Tonaufnahme, Transkript, KI-Auswertung), und können über denselben Link widerrufen. Die Plattform speichert Fassung, Zwecke, Zeitpunkt, Name und Widerruf. Wird der Text geändert, gelten Einwilligungen zur alten Fassung als veraltet.',
            $consentGateEnforced
                ? 'Die Plattform lässt eine Aufnahme nur zu, wenn für alle anwesenden Schüler:innen der Klasse eine gültige, nicht widerrufene Einwilligung zur aktuellen Fassung vorliegt, die die benötigten Zwecke abdeckt, und, wenn die Schule es verlangt, die Schüler:in selbst bestätigt hat.'
                : 'Die Plattform erfasst die Einwilligungen (gültig, widerrufen, veraltet) je Schüler:in und zeigt sie der Lehrkraft in der Übersicht „Einwilligungen“ der Klasse. Sie sperrt den Start einer Aufnahme in dieser Version nicht, wenn eine Einwilligung fehlt. Das Prüfen vor jeder Aufnahme verlangt die Dienstanweisung.',
            'Zusätzlich bestätigt die Lehrkraft vor jeder Aufnahme ausdrücklich in einem Dialog, dass die Klasse informiert ist. Der Server verlangt die Bestätigung und speichert den Zeitpunkt.',
            'Während der Aufnahme zeigt eine feste Leiste oben im Bildschirm den Zustand an, auch bei Pause und beim Speichern.',
            'Die Seite „Datenschutz & KI“ und die Datenschutzerklärung erklären in einfacher Sprache, was gespeichert wird, wer es sieht und was die KI tut.',
        ],
        'Datensparsamkeit und Fristen' => [
            'Nur Ton, kein Video, kein Bild.',
            "Aufnahmen werden nach {$recordingDays} Tagen automatisch gelöscht (täglich, mit Aufräumen verwaister Dateien). Die Lehrkraft kann sie jederzeit selbst löschen.",
            "Unterrichtsstunden mit Abgaben und Gesprächen werden nach {$schoolYearsKept} Schuljahren automatisch gelöscht.",
            'Auskunft und Datenübertragbarkeit: Jede Person kann ihre Daten im Profil herunterladen.',
        ],
        'KI und Dritte' => [
            'KI-Auswertung und Server-Transkription sind ab Werk ausgeschaltet und nur durch den Betreiber einzuschalten. Die Datenschutzerklärung wird vorher ergänzt.',
            'Audio erreicht nie die KI. Die Live-Texterkennung läuft nur lokal im Browser.',
            'Bei der Auswertung einer Aufnahme und beim Übernehmen in die Notizen werden Namen der Klasse und der Lehrkraft durch Platzhalter ersetzt (Pseudonymisierung, keine Anonymisierung). Die übrigen KI-Funktionen senden Texte und Arbeitsblatt-Dateien unverändert.',
            'Für die Fehlerart einer Antwort wertet die Plattform Frage, Erwartungshorizont und Antworttext aus. Die Namen der Klasse und der Lehrkraft ersetzt sie dabei vorher durch Platzhalter; hochgeladene Arbeitsblatt-Dateien gehen unverändert mit.',
            'Keine automatisierten Einzelentscheidungen. Nach der Transkription weist die Plattform darauf hin, das Transkript vor der Auswertung zu lesen; verbindlich macht das die Dienstanweisung. Der Verständniswert ist keine Note.',
        ],
        'Technik' => [
            'Passwörter nur als Hash, Sitzungsende nach '.$sessionMinutes.' Minuten ohne Aktivität, Schutz gegen gefälschte Anfragen.',
            'Audiodateien liegen nicht öffentlich auf dem Server, mit zufälligem Namen, und werden nur über eine geprüfte Adresse ausgeliefert.',
            'Kein Tracking, keine Werbung, keine Schriftarten oder Inhalte von fremden Servern im Kern der Plattform. Nur technisch notwendige Cookies.',
            'Betrieb in Deutschland. Server, Sicherungen und Unterauftragsverarbeiter beschreibt der Auftragsverarbeitungsvertrag.',
        ],
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Datenschutz-Folgenabschätzung (Art. 35 DSGVO)',
    'lead' => 'Vorlage für die Lernplattform einschließlich des optionalen Aufnahme-Moduls für Tonaufnahmen im Unterricht: Beschreibung, Notwendigkeit, Risiken für die Rechte der Kinder, Maßnahmen und die Bewertung der Schule.',
])

<h3>0. Beteiligte und Stand</h3>
<dl class="pd-facts">
    <dt>Verantwortliche Stelle</dt>
    <dd>{{ $school['name'] ?? $blank }}@if (filled($school['city'])), {{ $school['city'] }}@endif</dd>

    <dt>Schulnummer</dt>
    <dd>{{ $school['number'] ?? $blank }}</dd>

    <dt>Datenschutzbeauftragte(r)</dt>
    <dd>{{ $blank }}</dd>

    <dt>Auftragsverarbeiter (Betreiber)</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>

    <dt>Bewertet von / mit</dt>
    <dd>{{ $blank }}</dd>

    <dt>Datum der Bewertung</dt>
    <dd>{{ $blank }}</dd>
</dl>

<h3>1. Anlass und Schwellwertprüfung</h3>
<p>
    Eine Folgenabschätzung ist nötig, wenn eine Verarbeitung voraussichtlich ein hohes Risiko für die Rechte und Freiheiten natürlicher Personen bringt (Art. 35 Abs. 1 DSGVO). Hier treffen mehrere Merkmale zusammen:
</p>
<ul class="pd-checklist">
    @foreach ($criteria as [$criterion, $explanation, $applies])
        <li>
            <span @class(['pd-check', 'pd-on' => $applies]) aria-hidden="true"></span>
            <div>
                <p><strong>{{ $criterion }}: {{ $applies ? 'trifft zu' : 'trifft nicht zu' }}</strong></p>
                <p>{{ $explanation }}</p>
            </div>
        </li>
    @endforeach
</ul>
<p>
    Weil mehrere Merkmale zusammentreffen, ist eine Folgenabschätzung zu empfehlen, jedenfalls vor dem Aufnahme-Modul und vor dem Einschalten der KI. Ob die Aufsichtsbehörde des Landes die Verarbeitung ohnehin in ihrer Liste nach Art. 35 Abs. 4 DSGVO führt, prüft die Schule.
</p>

<h3>2. Systematische Beschreibung der Verarbeitung</h3>

<h4>2.1 Zwecke</h4>
<ul>
    <li>
        <strong>Lernplattform:</strong> Lehrkräfte halten fest, was sie im Unterricht behandelt haben, und verteilen daraus Hausaufgaben. Schüler:innen sehen, was sie schon verstanden haben und was sie noch üben sollten. Lehrkräfte erkennen Förderbedarf.
    </li>
    <li>
        <strong>Aufnahme-Modul (optional):</strong> Eine Lehrkraft nimmt den Ton einer Stunde auf, um daraus ein Transkript, eine Zusammenfassung und einen Tafelbild-Vorschlag für ihre Unterrichtsnotizen zu gewinnen. Die Aufnahme dient der Unterrichtsvor- und -nachbereitung der Lehrkraft. Sie dient nicht der Kontrolle von Schüler:innen oder Lehrkräften.
    </li>
</ul>

<h4>2.2 Betroffene Personen</h4>
<p>
    Schüler:innen (überwiegend minderjährig), Lehrkräfte und Schuladministration. Bei Aufnahmen zusätzlich alle weiteren Personen, die im Raum sprechen, etwa Vertretungslehrkräfte, Referendar:innen, Gäste, Praktikant:innen und Schulbegleitungen.
</p>

<h4>2.3 Daten, Zugriff und Fristen</h4>
<div class="pd-cards">
    @foreach ($dataCategories as $category)
        <section class="pd-card">
            <h4>{{ $category['title'] }}</h4>
            <dl class="pd-facts">
                <dt>Daten</dt>
                <dd>{{ $category['data'] }}</dd>

                <dt>Betroffene</dt>
                <dd>{{ $category['people'] }}</dd>

                <dt>Zugriff</dt>
                <dd>{{ $category['access'] }}</dd>

                <dt>Frist</dt>
                <dd>{{ $category['retention'] }}</dd>
            </dl>
        </section>
    @endforeach
</div>

<h4>2.4 Ablauf einer Aufnahme</h4>
<ol>
    <li>Die Schule hat Aufnahmen eingeschaltet und Einwilligungen der Eltern eingeholt. Die Lehrkraft öffnet die Aufnahmeseite zu einer ihrer Stunden. {{ $consentGateEnforced ? 'Die Plattform prüft die Einwilligungen der anwesenden Schüler:innen.' : 'Die Lehrkraft prüft in der Übersicht „Einwilligungen“, dass für alle Anwesenden eine gültige Einwilligung vorliegt; die Plattform erzwingt das in dieser Version nicht.' }} Die Lehrkraft bestätigt in einem Dialog ausdrücklich, dass die Klasse informiert ist. Erst danach fragt der Browser nach dem Mikrofon. Der Server speichert die Bestätigung mit Zeitpunkt.</li>
    <li>Der Browser nimmt nur Ton auf und sendet ihn in kurzen Teilen über die gesicherte Verbindung an den Server. Eine feste Leiste zeigt die laufende Aufnahme.</li>
    <li>Der Server legt die Datei mit zufälligem Namen auf dem nicht öffentlichen Speicher ab. Nur die aufnehmende Lehrkraft kann sie hören oder herunterladen.</li>
    <li>Optional: Eine Live-Texterkennung im Browser der Lehrkraft, nur lokal, liefert einen Text. Alternativ fügt die Lehrkraft einen Text selbst ein. Die Server-Transkription ist {{ $transcriptionStatus }}.</li>
    <li>Optional: Die Lehrkraft löst die Auswertung aus; die Plattform weist darauf hin, das Transkript vorher zu lesen. Namen der Klasse und der Lehrkraft werden zuvor durch Platzhalter ersetzt. Nur Text wird ausgewertet, nie Audio. Die KI-Anbindung ist {{ $aiStatus }}.</li>
    <li>Die Lehrkraft kann übernehmen, was sie braucht, und die Aufnahme jederzeit löschen. Spätestens nach {{ $recordingDays }} Tagen löscht die Plattform sie automatisch.</li>
</ol>

<h4>2.5 Empfänger</h4>
<ul>
    <li>Innerhalb der Schule nur die in 2.3 genannten Rollen.</li>
    <li>Der Betreiber der Plattform als Auftragsverarbeiter, technisch nur für Betrieb, Wartung und Fehlersuche.</li>
    <li>Unterauftragsverarbeiter des Betreibers, etwa für das Hosting, gemäß Auftragsverarbeitungsvertrag.</li>
    <li>Nur nach Einschalten: ein Anbieter für Spracherkennung und der KI-Anbieter Anthropic PBC (USA), jeweils nur zulässig mit Vertrag nach Art. 28 und, außerhalb der EU, mit geeigneten Garantien nach Art. 44 ff. DSGVO. Die Plattform prüft das nicht; die Schule lässt es sich vom Betreiber bestätigen.</li>
</ul>

<h4>2.6 Aktueller Stand dieser Plattform</h4>
<dl class="pd-facts">
    <dt>KI-Anbindung</dt>
    <dd>{{ ucfirst($aiStatus) }}</dd>

    <dt>Server-Transkription</dt>
    <dd>{{ ucfirst($transcriptionStatus) }}</dd>

    <dt>Aufbewahrung von Aufnahmen</dt>
    <dd>{{ $recordingDays }} Tage</dd>

    <dt>Aufbewahrung von Lerndaten</dt>
    <dd>{{ $schoolYearsKept }} Schuljahre (das laufende eingerechnet)</dd>
</dl>

<h3>3. Notwendigkeit und Verhältnismäßigkeit</h3>
<dl class="pd-facts">
    <dt>Rechtsgrundlage Lernplattform</dt>
    <dd>Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes. Die Schule prüft die genaue Norm.</dd>

    <dt>Rechtsgrundlage Aufnahme</dt>
    <dd>Einwilligung nach Art. 6 Abs. 1 lit. a, Art. 7 DSGVO, zusätzlich zum Schutz des nicht öffentlich gesprochenen Wortes (§ 201 StGB). Zufällig mitgesprochene besondere Kategorien (Art. 9 DSGVO, etwa Gesundheit, Herkunft, Religion, Familie) erfasst die Einwilligung nur, wenn sie ausdrücklich danach fragt (Art. 9 Abs. 2 lit. a, siehe Vorlage „Einwilligung“). Sie ist freiwillig, nachweisbar und jederzeit ohne Nachteil mit Wirkung für die Zukunft widerrufbar. Bei Minderjährigen willigen in der Regel die Sorgeberechtigten ein, je nach Land, Alter und Einsichtsfähigkeit zusätzlich die Schüler:innen. Bei Beschäftigten ist die Freiwilligkeit besonders zu prüfen (§ 26 BDSG oder die Vorschrift des Landes).</dd>

    <dt>Zweckbindung</dt>
    <dd>Die Zwecke sind in 2.1 festgelegt und stehen in Einwilligung und Elterninformation. Eine Nutzung zur Kontrolle von Schüler:innen oder Lehrkräften ist nicht vorgesehen.</dd>

    <dt>Datenminimierung</dt>
    <dd>Nur Ton, kein Video. Die KI bekommt nie Audio; bei der Auswertung einer Aufnahme nur pseudonymisierten Text. Aufnahmen sind nur für die aufnehmende Lehrkraft zugänglich.</dd>

    <dt>Speicherbegrenzung</dt>
    <dd>Aufnahmen nach {{ $recordingDays }} Tagen, Lerndaten nach {{ $schoolYearsKept }} Schuljahren, jeweils automatisch.</dd>

    <dt>Transparenz</dt>
    <dd>Elterninformation, Einwilligungstext, Seite „Datenschutz &amp; KI“ und Datenschutzerklärung.</dd>

    <dt>Betroffenenrechte</dt>
    <dd>Auskunft per Datenexport im Profil, Löschung der Aufnahme durch die aufnehmende Lehrkraft, ersatzweise über die Löschung der Stunde oder des Kontos durch die Schuladministration oder auf Weisung durch den Betreiber (siehe R10), Löschung des Kontos durch die Schuladministration, Widerruf über die Schule.</dd>
</dl>

@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Ist die Aufnahme für den Zweck erforderlich? Gibt es mildere Mittel?',
    'hint' => 'Zum Beispiel eigene Notizen der Lehrkraft, ein Foto des Tafelbilds, ein Gedächtnisprotokoll, Aufnahme nur in Einzelfällen. Begründung der Schule.',
])

<h3>4. Risiken für die Rechte und Freiheiten der Betroffenen</h3>
<p>
    Die Risiken betreffen vor allem Kinder. Zu jedem Risiko stehen die Maßnahmen, die die Plattform umsetzt, und die Grenzen dieser Maßnahmen. Die Schule bewertet Eintrittswahrscheinlichkeit und Schwere und trägt weitere Maßnahmen ein.
</p>

<div class="pd-cards">
    @foreach ($risks as $risk)
        <section class="pd-card">
            <h4>R{{ $loop->iteration }}: {{ $risk['title'] }}</h4>

            <dl class="pd-facts">
                <dt>Mögliche Folgen</dt>
                <dd>{{ $risk['impact'] }}</dd>

                <dt>Maßnahmen der Plattform</dt>
                <dd>
                    <ul>
                        @foreach ($risk['measures'] as $measure)
                            <li>{{ $measure }}</li>
                        @endforeach
                    </ul>
                </dd>

                <dt>Weitere Maßnahmen der Schule</dt>
                <dd><div class="pd-field-box" style="min-height: 4rem"></div></dd>

                <dt>Eintritt</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> gering</li>
                        <li><span class="pd-check" aria-hidden="true"></span> mittel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> hoch</li>
                    </ul>
                </dd>

                <dt>Schwere</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> gering</li>
                        <li><span class="pd-check" aria-hidden="true"></span> mittel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> hoch</li>
                    </ul>
                </dd>

                <dt>Restrisiko</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> akzeptabel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> nur mit weiteren Maßnahmen</li>
                        <li><span class="pd-check" aria-hidden="true"></span> nicht akzeptabel</li>
                    </ul>
                </dd>
            </dl>
        </section>
    @endforeach
</div>

<h3>5. Maßnahmen der Plattform</h3>
<p>Das setzt die Plattform um. Die Liste folgt dem tatsächlichen Verhalten der Software, nicht einem Wunsch.</p>

<div class="pd-cards">
    @foreach ($platformMeasures as $heading => $measures)
        <section class="pd-card">
            <h4>{{ $heading }}</h4>
            <ul>
                @foreach ($measures as $measure)
                    <li>{{ $measure }}</li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>

<h4>Was die Plattform nicht leistet</h4>
<ul>
    @if ($consentGateEnforced)
        <li>Sie prüft nur die Einwilligungen der Schüler:innen, die die Lehrkraft als anwesend angibt, nicht die von Lehrkräften und weiteren Personen im Raum, und nicht, ob die Bestätigungen der Schule (Folgenabschätzung, Personalrat und so weiter) stimmen. Das ist Sache der Schule.</li>
    @else
        <li>Sie sperrt den Start einer Aufnahme nicht, wenn Einwilligungen fehlen oder widerrufen sind. Sie prüft weder Einwilligungen von Lehrkräften und weiteren Personen im Raum noch, ob die Bestätigungen der Schule (Folgenabschätzung, Personalrat und so weiter) stimmen. Das ist Sache der Schule und der Dienstanweisung.</li>
    @endif
    <li>Sie führt keine Anwesenheitsliste. Wer bei einer Aufnahme anwesend ist, gibt die Lehrkraft an.</li>
    <li>Die Ersetzung von Namen vor der KI gibt es nur bei der Auswertung einer Aufnahme und beim Übernehmen in die Notizen, und sie ist keine Anonymisierung. Unbekannte Namen, Spitznamen und Erkennungsfehler bleiben im Text. Notizen, Arbeitsblatt-Dateien, Antworten und Gespräche gehen bei eingeschalteter KI ohne Ersetzung an den Anbieter.</li>
    <li>Sie erzwingt nicht, dass die Lehrkraft das Transkript vor der Auswertung liest, und bindet das Übernehmen in die Notizen und den Hausaufgaben-Entwurf mit KI nicht an die Zustimmung zur KI-Auswertung. Übernommener Text bleibt so lange in den Notizen wie diese und geht beim Hausaufgaben-Entwurf an den KI-Anbieter. Das regelt die Dienstanweisung.</li>
    <li>Löschen kann nur die aufnehmende Lehrkraft, solange sie die Stunde leitet. Ist sie nicht erreichbar oder wurde die Stunde neu zugewiesen, hilft nur die Löschung der Stunde oder des Kontos, ein Auftrag an den Betreiber oder die automatische Löschung (siehe R10).</li>
    <li>Die Anwendung verschlüsselt Audiodateien nicht zusätzlich. Server, Datenträger und Sicherungen schützt der Betrieb.</li>
    <li>Sie kann heruntergeladene Kopien und übernommenen Text nicht kontrollieren oder zurückholen.</li>
    <li>Sie kann einzelne Stimmen nicht aus einer Aufnahme herauslösen.</li>
</ul>

<h3>6. Weitere Maßnahmen der Schule</h3>
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Organisatorische Maßnahmen',
    'hint' => 'Zum Beispiel Dienstanweisung (Einwilligungsübersicht vor jeder Aufnahme prüfen, Transkript vor der Auswertung lesen, nur bei Zustimmung zur KI-Auswertung Text in die Notizen übernehmen, wer löscht, wenn die Lehrkraft nicht erreichbar ist), Dienstvereinbarung, Unterweisung der Lehrkräfte, Ankündigung vor jeder Aufnahme, Regeln für heruntergeladene Dateien, Prüfung der Sicherungen mit dem Betreiber.',
    'tall' => true,
])
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Offene Restrisiken und wie die Schule damit umgeht',
    'tall' => true,
])

<h3>7. Stellungnahmen, Ergebnis und Freigabe</h3>
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Stellungnahme der oder des Datenschutzbeauftragten (Art. 35 Abs. 2 DSGVO)',
])
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Standpunkte betroffener Personen oder ihrer Vertretungen (Art. 35 Abs. 9 DSGVO)',
    'hint' => 'Elternvertretung, Schülervertretung, Personalrat',
])

<h4>Entscheidung der Schulleitung</h4>
<ul class="pd-options">
    <li><span class="pd-check" aria-hidden="true"></span> Aufnahme-Modul freigegeben</li>
    <li><span class="pd-check" aria-hidden="true"></span> freigegeben mit Auflagen (siehe Teil 6)</li>
    <li><span class="pd-check" aria-hidden="true"></span> nicht freigegeben</li>
    <li><span class="pd-check" aria-hidden="true"></span> Konsultation der Aufsichtsbehörde nötig (Art. 36 DSGVO)</li>
</ul>

<p>
    Überprüfung der Folgenabschätzung spätestens am {{ $blank }}, außerdem bei jeder Änderung der Verarbeitung, zum Beispiel beim Einschalten der KI-Anbindung oder der Server-Transkription (Art. 35 Abs. 11 DSGVO).
</p>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Schulleitung', 'Datenschutzbeauftragte(r)'],
])
