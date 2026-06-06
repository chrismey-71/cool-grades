# Usability-Audit der App zur Mitarbeitsbewertung

## 1. Kurzfazit

| Erkenntnis | Einschätzung |
|---|---|
| Die App bildet die fachlich-pädagogischen Anforderungen inzwischen sehr differenziert ab. Für Lehrkräfte ohne Einschulung ist die Informationsdichte aber stellenweise hoch. | wichtig |
| Die schnelle Mitarbeitserfassung ist funktional stark, wirkt in der Normalansicht jedoch lang und teilweise formularlastig. Für den Unterricht ist die vereinfachte Eingabe der wahrscheinlich bessere Standard. | hoch |
| Die Abschlussbeurteilung ist fachlich sinnvoll aufgebaut: Vorinformationen kommen vor der finalen Entscheidung. Die Seite hat aber noch zu viel erklärenden Text und zu viele Status-Badges direkt im Entscheidungsfluss. | hoch |
| Die Begriffe „Notenvorschlag“, „Datenlage“, „Schularbeitsfach“, „SOST“, „NOST“ und „Jahresbeurteilung“ werden grundsätzlich erklärt, aber nicht immer genau dort, wo die Lehrkraft eine Entscheidung trifft. | wichtig |
| Entwurf und Finalspeicherung sind technisch unterscheidbar, die UI sollte den Unterschied noch prägnanter und sicherer kommunizieren. | hoch |
| Die Auswertung ist deutlich mehr als eine Fallzählung und fachlich wertvoll. Die Haupttabelle ist aber breit und kann bei vielen Schüler:innen oder kleineren Bildschirmen schwer scanbar werden. | hoch |
| Konto- und Darstellungseinstellungen sind hilfreich, aber aus Nutzer:innensicht nicht überall klar genug danach getrennt, ob sie nur die Ansicht oder auch spätere Erfassungen/Auswertungen beeinflussen. | mittel |
| Die App hat gute Fehlervermeidungsansätze: CSRF, Pflichtfelder, Lehrer:innen-Zuordnung, Warnung bei finaler Änderung, Dirty-Form-Hinweise und Bestätigungen bei Löschaktionen. Einige riskante Workflows bleiben aber erklärungsbedürftig. | wichtig |
| PDF-Export und Berichtsfunktionen sind vorhanden und wertvoll, die Begriffe „Drucken / PDF“ und „PDF herunterladen“ könnten deutlicher voneinander abgegrenzt werden. | mittel |
| Größte UX-Chance: Weniger gleichzeitige Fachlogik auf der Oberfläche, mehr geführte Entscheidungswege mit kurzen, kontextgenauen Hinweisen. | wichtig |

## 2. Bewertungsmethode

Geprüft wurden die Lehrer:innenbereiche der App aus Sicht einer normalen Lehrkraft, die während oder nach dem Unterricht schnell dokumentieren und am Semesterende nachvollziehbar beurteilen möchte.

Geprüfte Dateien und Bereiche:

| Bereich | Geprüfte Datei(en) |
|---|---|
| Navigation und Lehrerbereich | `teacher/index.php` |
| Schnelle Mitarbeitserfassung | `teacher/participation_new.php` |
| Einträge suchen und bearbeiten | `teacher/participation_list.php`, stichprobenartig |
| Besondere mündliche Leistungen | `teacher/oral_new.php`, `teacher/orals.php`, stichprobenartig |
| Besondere schriftliche Leistungen | `teacher/exam_new.php`, `teacher/exams.php`, stichprobenartig |
| Auswertung | `reports.php`, `reports_pdf.php`, stichprobenartig |
| Abschlussbeurteilung | `teacher/final_assessments.php`, `teacher/final_assessments_pdf.php`, `lib/final_assessments.php` |
| Benutzerumgebung | `account.php`, `teacher/manage.php`, `teacher/criteria.php`, `teacher/options.php`, `teacher/presets.php`, `teacher/student_groups.php`, stichprobenartig |
| Begriffe und fachliche Hinweise | `lib/assessment_summaries.php`, `lib/report_evaluation.php`, `lib/assessment_systems.php` |

Zusätzlich wurden vorhandene Handbuch-Screenshots als visuelle Stichprobe betrachtet, insbesondere:

| Screenshot | Zweck der Stichprobe |
|---|---|
| `docs/screenshots/lehrerhandbuch/04-schnell-mitarbeit-leer.png` | Länge und Reihenfolge der Mitarbeitserfassung |
| `docs/screenshots/lehrerhandbuch/19-auswertung-tabelle.png` | Lesbarkeit der Auswertungstabelle |
| `docs/screenshots/lehrerhandbuch/25-abschlussbeurteilung-details.png` | Informationshierarchie der Abschlussbeurteilung |

Typische Nutzungssituationen:

| Situation | Annahme |
|---|---|
| Unterricht läuft oder ist gerade vorbei | Lehrkraft hat wenig Zeit, will 3 bis 10 Schüler:innen dokumentieren und schnell speichern. |
| Semesterende | Lehrkraft möchte pro Schüler:in die wichtigsten Informationen sehen und bewusst eine finale Note festlegen. |
| Nachbearbeitung | Lehrkraft sucht falsch gespeicherte Einträge, korrigiert Datum, Schüler:in, Eindruck oder Kommentar. |
| Auswertung / PDF | Lehrkraft möchte eine nachvollziehbare Entscheidungsgrundlage für eigene Dokumentation oder Konferenzunterlagen. |
| Erste Nutzung | Lehrkraft kennt Fachbegriffe, aber nicht die App-Logik und braucht kurze, kontextnahe Erklärungen. |

## 3. Wichtigste Usability-Probleme

| Bereich | Problem | Auswirkung auf Lehrer:innen | Schweregrad | Empfehlung |
|---|---|---|---|---|
| Schnelle Mitarbeitserfassung | Die Normalansicht zeigt viele Bereiche auf einmal: Stundenkontext, Preset, Datum, Anlass, Eindruck, Leistungsart, Kontext, Beobachtungsbereich, Kriterien, Schüler:innen. | Im Unterricht entsteht Scroll- und Suchaufwand. Lehrkräfte könnten Pflichtfelder übersehen oder die Erfassung abbrechen. | hoch | Vereinfachte Eingabe als empfohlenen Standard für neue Lehrkräfte prüfen; Normalansicht stärker als „Erweitert“ kennzeichnen. |
| Schnelle Mitarbeitserfassung | Der Speichern-Button liegt ganz unten, während Pflichtfelder oben liegen. | Bei langen Formularen ist nicht immer klar, ob die Auswahl vollständig ist. | mittel | Sticky-Speichern-Leiste oder kompakte Fortschrittsanzeige erwägen. |
| Abschlussbeurteilung | Der Kopfbereich enthält viele Statusinformationen, Chips und Hilfstexte. | Die wichtige Einzelentscheidung kann visuell nach unten rutschen. | hoch | Kopfbereich verdichten: Kontext in einer Zeile, ausführliche Hilfen einklappbar. |
| Abschlussbeurteilung | „Entwurf speichern“ und „Final speichern“ sind nebeneinander, aber der Unterschied wird erst im Kontext klar. | Risiko, dass Lehrkräfte versehentlich final speichern oder Entwürfe für fertig halten. | hoch | Direkt über den Buttons kurzen Unterschied anzeigen und Final-Button visuell vorsichtiger formulieren. |
| Abschlussbeurteilung | Automatischer Sprung zum nächsten Schüler ist effizient, aber nach dem Speichern nicht vorab angekündigt. | Lehrkräfte könnten irritiert sein, wenn nach dem Speichern eine andere Person geöffnet wird. | mittel | Button- oder Hinweistext ergänzen: „Speichern und nächste:n öffnen“. |
| Auswertung | Die Haupttabelle ist fachlich stark, aber sehr breit. | Seitliches Scrollen oder abgeschnittene Informationen erschweren schnelles Lesen. | hoch | Webansicht stärker in Karten oder responsive Detailzeilen aufteilen; PDF separat tabellarisch halten. |
| Auswertung | Begriffe wie „noch dünn“, „ausreichend“, „gute Datenbasis“ sind vorhanden, aber die konkrete Schwelle ist erst im Accordion erklärt. | Lehrkräfte können die Aussage falsch als Qualitätsurteil über Schüler:innen lesen. | mittel | Datenlage direkt im Tabellenkopf oder Tooltip kurz erklären. |
| Benutzerumgebung | Konto-Einstellungen erklären teilweise Wirkung, aber nicht überall Folgen für Daten, Auswertung oder nur Anzeige. | Neue Nutzer:innen wissen nicht sicher, welche Einstellung „gefahrlos“ ausprobiert werden kann. | mittel | Jede Einstellung nach Wirkung klassifizieren: „nur Ansicht“, „Erfassung“, „Auswertung“. |
| Picklisten / Kriterien | Verwaltung ist mächtig, aber Fachbezug, Quelle, Archiv und Aktiv/Inaktiv sind komplex. | Lehrkräfte könnten aus Versehen Standards materialisieren, Optionen deaktivieren oder alte Begriffe falsch interpretieren. | mittel | Mehr kontextnahe Kurztexte und klarere Unterscheidung „betrifft künftige Auswahl“ vs. „alte Einträge bleiben erhalten“. |
| PDF-Export | Es gibt „Drucken / PDF (A4)“ und „PDF herunterladen“. | Unterschied zwischen Browserdruck und echtem PDF ist nicht sofort klar. | mittel | Buttons umbenennen: „Druckansicht öffnen“ und „PDF-Datei herunterladen“. |
| Besondere schriftliche Leistungen | Noteneingabe erfolgt tabellarisch für alle Schüler:innen. | Bei großen Klassen kann die Zeile verrutschen; falsche Note bei falscher Person ist möglich. | hoch | Zeilen stärker visuell trennen, aktive Zeile markieren, Suche oder Gruppierung anbieten. |
| Besondere mündliche Leistungen | Artwechsel zwischen mündlicher Prüfung und mündlicher Übung verändert Felder per JavaScript. | Ohne JavaScript oder bei unbemerktem Wechsel können Felder missverständlich wirken. | niedrig | No-JS-Fallback prüfen; beim Artwechsel kurze Erklärung der geänderten Felder anzeigen. |

## 4. Detaillierte Analyse nach Bereichen

### 4.1 Navigation und Einstieg

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Der Lehrerbereich trennt zentrale Aufgaben in Karten: Mitarbeit, besondere mündliche Leistungen, besondere schriftliche Leistungen und Abschlussbeurteilung. | Gute Orientierung für wiederkehrende Nutzung. |
| Die Abschlussbeurteilung ist links unterhalb der schnellen Mitarbeitserfassung sichtbar. | Passt zum gewünschten Workflow: erst erfassen, später zusammenführen. |
| Direktlinks zu Einträge bearbeiten, Gruppen verwalten und Stundenerfassung sind vorhanden. | Praktisch für tägliche Arbeit. |

Unklar oder verbesserbar:

| Problem | Empfehlung |
|---|---|
| „Bes. mündl. Leistungsfeststellung“ und „Bes. schriftl. Leistungsfeststellung“ sind fachlich korrekt, aber auf Einstiegsebene sperrig. | Einstiegstitel ausschreiben oder als „Besondere mündliche Leistung“ und „Besondere schriftliche Leistung“ formulieren. |
| Der Unterschied zwischen Mitarbeit, besonderer Leistung, Auswertung und Abschlussbeurteilung wird auf der Startseite nur knapp erklärt. | Jede Karte sollte eine Ein-Satz-Entscheidungshilfe enthalten: „Nutzen Sie diesen Bereich, wenn ...“. |
| Bei fehlenden Lehrerzuordnungen erscheint ein Admin-Hinweis. | Gut, aber aus Lehrer:innensicht sollte der konkrete nächste Schritt lauten: „Bitte Admin bitten, Klasse/Fach zuzuordnen.“ |

Fehlerquellen:

| Fehlerquelle | Risiko |
|---|---|
| Klasse/Fach-Auswahl erfolgt in mehreren Karten separat. | Lehrkraft könnte versehentlich im falschen Fach eine besondere Leistung anlegen. |
| Startseite bietet viele Wege zur gleichen Datenwelt. | Neue Nutzer:innen wissen eventuell nicht, ob sie zuerst Stundenerfassung oder Mitarbeitserfassung öffnen sollen. |

### 4.2 Schnelle Mitarbeitserfassung

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Klasse und Fach werden oben klar angezeigt. | Gute Kontextkontrolle vor dem Speichern. |
| Pflichtfelder für Grund/Anlass und Eindruck/Relevanz sind als Selects mit „Bitte wählen…“ umgesetzt. | Verringert freie, uneinheitliche Eingaben. |
| Quick-Pick unterstützt faire Rotation bei Schüler:innen mit wenigen Einträgen. | Sehr hilfreich im Schulalltag. |
| Gruppen können direkt angewendet werden. | Praktisch für Projekt- und Gruppenarbeitsphasen. |
| Presets schließen Stunde, Datum und Schüler:innen bewusst aus. | Fachlich sinnvoll, verhindert versehentliche Massenspeicherung mit falschem Datum. |
| LBV-Hinweise sind optional einklappbar. | Gute Balance zwischen Rechtssicherheit und Workflow. |
| Vereinfachte Eingabe blendet fachliche Tiefe aus. | Sinnvoll für schnelle Alltagserfassung. |

Unklar oder verbesserbar:

| Problem | Auswirkung | Empfehlung |
|---|---|---|
| Der Bereich „Unterrichtskontext“ erscheint in der visuellen Reihenfolge vor „Kurze Beobachtung / Anlass“, wenn er geöffnet ist. | Lehrkraft könnte Kontextfelder wichtiger nehmen als die eigentliche Beobachtung. | In der Normalansicht den Kurztext stärker als Kernfeld markieren. |
| „Leistungsart (Mehrfach)“ ist fachlich korrekt, aber die Folgen sind nicht direkt erklärt. | Lehrkraft weiß nicht sicher, ob diese Auswahl später in Auswertung/PDF erscheint. | Direkt darunter ergänzen: „Erscheint später in Auswertung und Dokumentation; verändert keine Note.“ |
| „Beobachtungsbereich“ und „Kriterien“ wirken ähnlich. | Gefahr von Doppelpflege oder Unsicherheit, ob beides nötig ist. | Beobachtungsbereich als grobe Alltagsebene und Kriterien als optionale fachliche Präzisierung deutlicher trennen. |
| Die pädagogische Formativ/Summativ-Hinweisbox kann bei jedem Eintrag Aufmerksamkeit binden. | Bei Routineeingaben kann der Hinweis störend werden. | Nur bei potenziell problematischer Kombination deutlich hervorheben; sonst kompakt als Badge. |
| Der Speichern-Button am Ende ist sehr weit entfernt von Datum/Anlass/Eindruck. | Längere Scrollstrecke, besonders auf kleinen Bildschirmen. | Sticky-Save oder zweite Speichern-Möglichkeit nach Schüler:innenauswahl prüfen. |

Fehlerquellen:

| Fehlerquelle | Empfehlung |
|---|---|
| Mehrfachauswahl von Schüler:innen plus Gruppen kann bestehende Auswahl ersetzen. | Nach Gruppenauswahl kurz anzeigen: „Gruppe angewendet, einzelne Schüler:innen können ergänzt werden.“ |
| Preset wird beim Auswählen sofort angewendet. | Vor allem bei bereits ausgefüllter Maske kann das überraschend sein. Buttontext oder Hinweis „Preset anwenden“ wäre kontrollierter. |
| Neue Stunde anlegen ist in derselben Maske möglich. | Gut, aber komplex. Bei Aktivierung sollten neue Pflichtfelder visuell stärker hervortreten. |

### 4.3 Besondere mündliche Leistungen

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Art unterscheidet mündliche Prüfung und mündliche Übung. | Fachlich passend zur LBV. |
| Die Felder ändern sich je nach Art: Themengebiet/Fragen oder Kategorie/Titel. | Inhaltlich sinnvoll. |
| Eindruck/Relevanz wird wie in der Mitarbeit verwendet. | Konsistenz zur übrigen App. |
| Gesetzeshinweis wechselt zwischen § 5 und § 6 LBV. | Gute fachliche Orientierung. |

Unklar oder verbesserbar:

| Problem | Empfehlung |
|---|---|
| „Eindruck/Relevanz“ ist bei besonderen mündlichen Leistungen vielleicht weniger selbstverständlich als bei Mitarbeit. | Kurz ergänzen: „Wird später in Auswertung und Abschlussbeurteilung als Tendenz ausgewiesen.“ |
| Nach dem Speichern bleibt die Seite im Anlagefluss, aber die ausgewählte Schüler:in wird zurückgesetzt. | Optional anbieten: „Weitere Leistung für nächste:n Schüler:in erfassen“. |
| Die Fehlerausgabe erfolgt als allgemeine Box oben. | Feldnahe Fehlermeldungen wären schneller erfassbar. |

Fehlerquellen:

| Fehlerquelle | Risiko |
|---|---|
| Artwechsel blendet Felder aus. | Lehrkraft kann glauben, eingegebene Werte bleiben relevant, obwohl sie für die andere Art nicht gespeichert werden. |
| Kein eigener Score/Note, sondern Eindruck/Relevanz. | Muss pädagogisch bewusst sein, sonst Erwartung einer klassischen Note. |

### 4.4 Besondere schriftliche Leistungen

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Schriftliche Leistungsarten sind sauber getrennt: Schularbeit, Test, schriftliche Wiederholung, Arbeitsauftrag, sonstige schriftliche Leistung. | Fachlich sehr wertvoll. |
| Noten pro Schüler:in werden in einer Tabelle erfasst. | Effizient für eine ganze Klasse. |
| Tendenz und Bemerkung je Schüler:in sind möglich. | Erhöht pädagogische Nachvollziehbarkeit. |
| Gesetzeshinweis passt sich der Art an. | Hilfreich zur Einordnung. |

Unklar oder verbesserbar:

| Problem | Empfehlung |
|---|---|
| Die Tabelle kann bei großen Klassen lang werden. | Suche, Filter „nur bewertete Zeilen“, Zeilenhervorhebung oder Eingabe pro Schüler:in prüfen. |
| „Tendenz“ ist nicht direkt erklärt. | Kurztext ergänzen: „Tendenz präzisiert die Note, ersetzt sie aber nicht.“ |
| Leere Note bedeutet keine Speicherung für diese Schüler:in. | Direkt oberhalb der Tabelle erklären. |

Fehlerquellen:

| Fehlerquelle | Risiko |
|---|---|
| Falsche Zeile bei langer Tabelle. | Falsche Note kann gespeichert werden. |
| Kein offensichtlicher Schutz vor leerem Titel außer `required`. | Browservalidierung hilft, aber feldnahe Erklärung wäre besser. |
| Nach Speichern wird Formular geleert. | Gut für neue Eingabe, aber Lehrkraft sollte Bestätigung mit Art, Datum und Titel sehen. |

### 4.5 Auswertung

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Die Auswertung enthält pro Schüler:in eine zusammenfassende Tabelle statt reiner Ereignisliste. | Sehr guter Schritt für Semesterentscheidungen. |
| Datenlage, dokumentierte Tage, positiv/neutral/negativ, Qualität, Kriterien, besondere Leistungen und Hinweise werden gemeinsam sichtbar. | Fachlich aussagekräftig. |
| Schularbeitsfachstatus wird als Kontext angezeigt. | Korrekt, weil er die Interpretation beeinflusst. |
| „So entsteht der Notenvorschlag Mitarbeit“ ist einklappbar. | Transparenz ohne dauerhafte Überladung. |
| Einzelne Schüler:innen können für Details gefiltert werden. | Unterstützt gezielte Nachprüfung. |
| Druckansicht und echter PDF-Download existieren. | Praktisch für Dokumentation. |

Unklar oder verbesserbar:

| Problem | Auswirkung | Empfehlung |
|---|---|---|
| Die Haupttabelle ist horizontal sehr breit. | Auf Laptop oder Tablet schwer lesbar. | In Webansicht Karten/Accordion je Schüler:in prüfen; PDF kann tabellarisch bleiben. |
| „Noch dünn“, „ausreichend“ und „gute Datenbasis“ wirken wie Bewertungslabels. | Könnte als Aussage über Schüler:in statt Datenbasis verstanden werden. | Label klarer: „Datenbasis: dünn“, „Datenbasis: ausreichend“, „Datenbasis: gut“. |
| Die Spalte „Notenvorschlag Mitarbeit“ kann mit finaler Note verwechselt werden. | Risiko falscher Interpretation. | Spaltenkopf: „Mitarbeits-Tendenz / Vorschlag“. |
| „Drucken / PDF (A4)“ und „PDF herunterladen“ stehen nebeneinander. | Unterschied unklar. | Umbenennen in „Druckansicht öffnen“ und „PDF-Datei herunterladen“. |

Fehlerquellen:

| Fehlerquelle | Empfehlung |
|---|---|
| Zeitraumfilter und Schüler:innenfilter sind getrennte Formulare. | Nach Änderung des zweiten Filters ist klarer, aber der Zusammenhang könnte optisch stärker gruppiert werden. |
| Auswahl „– alle –“ erzeugt große Tabelle. | Für große Klassen zusätzlich Suche, Hervorhebung auffälliger Fälle oder Sortieroptionen anbieten. |
| Datumsformat wirkt in Screenshots teils US-orientiert im Eingabefeld. | Browserbedingt möglich; Anzeige daneben deutsch formatiert ergänzen. |

### 4.6 Abschlussbeurteilung

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Der Workflow stellt Vorinformationen vor die finale Entscheidung. | Pädagogisch richtig. |
| Klasse, Fach, Schuljahr, Zeitraum, Beurteilungssystem und Schularbeitsfachstatus werden angezeigt. | Gute Kontextsicherung. |
| Einzelne Schüler:in steht im Zentrum; Pfeile ermöglichen schnelles Durcharbeiten. | Passt zum gewünschten Lehrer:innen-Workflow. |
| Ergebnisse aus Semester 1 und bei Jahresbeurteilung Semester 2 werden sichtbar. | Sinnvoll für SOST/NOST/Jahresmodell. |
| Besondere mündliche und schriftliche Leistungen stehen nebeneinander. | Gute Vergleichbarkeit. |
| Mitarbeit wird kompakt zusammengefasst. | Schnell interpretierbar. |
| Finale Beurteilung ist ein eigener Abschnitt nach den Informationskarten. | Richtige Entscheidungsdramaturgie. |
| Finale Änderung benötigt einen Änderungsvermerk. | Gute Fehlervermeidung und Nachvollziehbarkeit. |
| Nach Speichern wird die nächste Schüler:in geöffnet. | Effizient für Klassenworkflow. |

Unklar oder verbesserbar:

| Problem | Auswirkung | Empfehlung |
|---|---|---|
| Kopfbereich und Kontextkarten sind noch relativ groß. | Lehrkraft muss vor der eigentlichen Schüler:innenentscheidung viel lesen. | Kontext kompakter machen, längere Systemhinweise einklappbar. |
| „1. Überblick 1. Semester“ erscheint auch bei Jahresbeurteilung als Abschnittstitel, enthält aber zusätzlich 2. Semester und Entwicklung. | Abschnittstitel passt nicht vollständig zum Inhalt. | Für Jahresbeurteilung umbenennen in „Semesterüberblick“. |
| Besondere mündliche und schriftliche Karten sind beide mit „2.“ nummeriert. | Logisch erklärbar als gleicher Abschnitt, aber visuell irritierend. | Nummer einmal als Abschnittstitel „2. Besondere Leistungsfeststellungen“ und darunter zwei Karten ohne Nummer. |
| Schularbeitsfach erscheint als Badge und zusätzlich als Text „Kein Schularbeitsfach.“ | Kleine Wiederholung. | Entweder Badge mit kurzem Tooltip oder Text, nicht beides prominent. |
| Der automatische Sprung zur nächsten Schüler:in wird erst nach Speichern per Meldung deutlich. | Erwartung kann beim ersten Mal unklar sein. | Buttons: „Entwurf speichern und nächste:n öffnen“, „Final speichern und nächste:n öffnen“. |
| „Final speichern“ ist kurz, aber rechtlich/pädagogisch schwerwiegend. | Kann zu beiläufig wirken. | „Finale Note speichern“ oder „Final speichern und nächste:n öffnen“. |
| Der Notenvorschlag steht in grünem Entscheidungsblock sehr präsent. | Risiko, dass Vorschlag psychologisch als Vorgabe wirkt. | Vorschlag als neutrales Element anzeigen; finale Lehrkraftentscheidung visuell stärker machen. |

Fehlerquellen:

| Fehlerquelle | Empfehlung |
|---|---|
| Finale Note „noch nicht festgelegt“ kann als Entwurf gespeichert werden, aber nicht final. | Gut, aber direkt beim Select erklären. |
| Bereits finale Noten können geändert werden, wenn Änderungsvermerk gesetzt wird. | Gut, aber vor „Final speichern“ bei Änderung zusätzlich dezente Bestätigung erwägen. |
| Wechsel zwischen Schüler:innen per Dropdown sendet automatisch ab. | Effizient mit JavaScript, aber „Öffnen“ ist nur im Noscript-Fall sichtbar. Sichtbarer kleiner Button könnte Klarheit erhöhen. |
| Jahresbeurteilung darf nicht Durchschnitt sein. | Wird textlich erklärt; die UI sollte im Jahresmodus zusätzlich „keine automatische Durchschnittsnote“ direkt bei Semesterübersicht erwähnen. |

### 4.7 Einstellungen / Benutzerumgebung

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Konto trennt Passwort und Darstellung. | Gute Grundstruktur. |
| Einstellungen sind als Karten gruppiert. | Lesbar und schnell scannbar. |
| Viele Einstellungen haben kurze Wirkungstexte. | Hilfreich für neue Nutzer:innen. |
| Verwaltung trennt Kriterien, Picklisten, Presets und Gruppen. | Logisch und modular. |
| Picklisten erklären, dass Änderungen eine eigene wirksame Liste materialisieren. | Technisch und fachlich wichtig. |
| Kriterien schützen benutzte Sets/Kriterien teilweise vor hartem Löschen. | Gute Datensicherheit. |

Unklar oder verbesserbar:

| Problem | Empfehlung |
|---|---|
| Konto-Einstellungen haben keine klare Kategorie „nur Ansicht“, „Erfassung“, „Auswertung“. | Pro Setting kleinen Wirkungschip anzeigen. |
| „Darkmode wirkt auf alle Bereiche“ sagt nicht explizit, dass Daten/PDF nicht verändert werden. | Ergänzen: „Nur Ihre Ansicht; keine Auswirkung auf gespeicherte Daten oder PDF.“ |
| „Vereinfachte Eingabe bei Mitarbeit“ verändert den Erfassungsworkflow stark. | Als „empfohlen für schnelle Alltagserfassung“ kennzeichnen. |
| Picklisten-Verwaltung nutzt Begriffe wie Quelle, Archiv, active/inactive. | Für normale Lehrkräfte stärker alltagssprachlich erklären. |
| Drag & Drop bei Picklisten hat kein offensichtliches Tastatur- oder No-JS-Äquivalent. | Alternative „nach oben / nach unten“ erwägen. |

Fehlerquellen:

| Fehlerquelle | Risiko |
|---|---|
| Lehrkraft deaktiviert oder löscht Picklistenoptionen und versteht Rückwirkung nicht. | Alte Einträge bleiben möglicherweise erhalten, neue Auswahl ändert sich. Muss klarer erklärt werden. |
| Presets gelten pro Fach, nicht pro Klasse. | Wird erwähnt, sollte auch beim Anwenden sichtbar bleiben. |
| Kriterienvorschläge werden automatisch eingefügt. | Gut, aber Lehrkraft sollte wissen, dass Vorschläge angepasst werden dürfen und keine Pflichtliste sind. |

### 4.8 PDF-Export und Berichte

Gut gelöst:

| Beobachtung | Bewertung |
|---|---|
| Auswertung bietet Druckansicht und PDF-Download. | Praxistauglich. |
| Abschlussbeurteilung hat eigenen Bericht/PDF. | Wichtig für Dokumentation. |
| Rechtlich-pädagogische Hinweise werden im Bericht berücksichtigt. | Fachlich richtig. |
| Schularbeitsstatus und Leistungsarten werden ausgewiesen. | Unterstützt korrekte Interpretation. |

Unklar oder verbesserbar:

| Problem | Empfehlung |
|---|---|
| Zwei PDF-nahe Buttons können verwechselt werden. | Buttontexte präzisieren. |
| PDF-Nutzung wird nicht direkt erklärt: Dokumentation, Besprechung, Ablage, Kontrollausdruck. | Kurzhinweis ergänzen. |
| Wenn der PDF-Export viele Spalten enthält, kann Lesbarkeit leiden. | PDF-Layout regelmäßig mit realistischen Klassengrößen testen. |

Fehlerquellen:

| Fehlerquelle | Empfehlung |
|---|---|
| Lehrkraft exportiert falschen Zeitraum. | PDF-Kopfbereich sollte Zeitraum sehr deutlich enthalten. |
| PDF wird als endgültige Note missverstanden. | Rechtshinweis beibehalten, aber kurz und sichtbar. |

## 5. Quick Wins

| Quick Win | Aufwand | Risiko | Erwarteter Nutzen |
|---|---|---|---|
| Button „Drucken / PDF (A4)“ in „Druckansicht öffnen“ umbenennen. | niedrig | niedrig | Weniger Verwechslung mit echtem PDF-Download. |
| Button „PDF herunterladen“ in „PDF-Datei herunterladen“ umbenennen. | niedrig | niedrig | Klarerer Export-Workflow. |
| Abschlussbeurteilung: Buttons in „Entwurf speichern und nächste:n öffnen“ und „Finale Note speichern und nächste:n öffnen“ umbenennen. | niedrig | niedrig | Automatischer Schüler:innenwechsel wird vorhersehbar. |
| Datenlage-Labels in Auswertung und Abschlussbeurteilung mit Präfix anzeigen: „Datenbasis: gut“. | niedrig | niedrig | Verhindert Missverständnis als Schüler:innenurteil. |
| Bei finaler Note direkt Hilfetext ergänzen: „Für Entwurf optional, für final verpflichtend.“ | niedrig | niedrig | Reduziert Speicherfehler. |
| Konto-Einstellungen mit Wirkungschips ergänzen: „nur Ansicht“, „Erfassung“, „Auswertung“. | niedrig | niedrig | Lehrkräfte verstehen Folgen schneller. |
| In der Mitarbeitserfassung unter „Leistungsart“ ergänzen: „Mehrfachauswahl erscheint später in Auswertung/PDF.“ | niedrig | niedrig | Bessere Bedeutung der Auswahl. |
| In der Auswertung den Accordion-Titel „So entsteht der Notenvorschlag Mitarbeit“ ändern in „Schwellenwerte und Datenlage erklären“. | niedrig | niedrig | Bessere Auffindbarkeit der Erklärung. |
| In Abschlussbeurteilung bei Jahresmodus Abschnitt „1. Überblick 1. Semester“ zu „Semesterüberblick“ ändern. | niedrig | niedrig | Verhindert Irritation bei Jahresbeurteilung. |
| In besonderer schriftlicher Leistung erklären: „Leere Note = keine Bewertung für diese Schüler:in speichern.“ | niedrig | niedrig | Weniger Eingabefehler. |
| Bei Presets „Preset wählen“ in „Preset anwenden“ oder mit Button absichern. | mittel | niedrig | Weniger überraschende Formularänderungen. |
| In Picklisten bei Löschen/Archivieren ergänzen: „Alte Einträge bleiben erhalten, neue Auswahl wird geändert.“ | niedrig | niedrig | Mehr Datensicherheit im Verständnis. |

## 6. Größere Verbesserungen

### Muss dringend umgesetzt werden

| Verbesserung | Begründung |
|---|---|
| Abschlussbeurteilung sprachlich und visuell weiter auf Einzelentscheidung fokussieren. | Diese Seite ist rechtlich und pädagogisch am sensibelsten. Der Workflow soll maximale Klarheit haben. |
| Auswertungstabelle für Webansicht responsiver machen. | Aktuell ist sie bei vielen Spalten schwer lesbar, obwohl die Daten fachlich gut sind. |
| Schriftliche Leistungsfeststellung gegen Zeilenverwechslung absichern. | Notentabellen für ganze Klassen sind effizient, aber fehleranfällig. |

### Sollte mittelfristig umgesetzt werden

| Verbesserung | Begründung |
|---|---|
| Vereinfachte Mitarbeitserfassung als geführter Standardmodus für neue Lehrkräfte prüfen. | Sie passt besser zum Schulalltag und reduziert kognitive Last. |
| Konto-Einstellungen in Wirkungskategorien gliedern. | Neue Nutzer:innen verstehen schneller, was gefahrlos ausprobiert werden kann. |
| Picklisten, Kriterien und Presets mit stärkerem „betrifft künftige Einträge“-Hinweis versehen. | Verhindert Sorge vor Datenverlust oder falschen Rückwirkungen. |
| Abschlussbeurteilung im Jahresmodus mit eigener Microcopy zur Nicht-Durchschnittslogik ausstatten. | Fachlich zentral für österreichische Beurteilung. |

### Kann später umgesetzt werden

| Verbesserung | Begründung |
|---|---|
| Tastaturbedienbare Sortierung für Picklisten ergänzen. | Barrierefreiheit und No-JS-Robustheit. |
| Onboarding-Hinweise für Erstnutzung einbauen. | Hilfreich, aber nicht kritisch für geübte Lehrkräfte. |
| Detailansichten in der Auswertung stärker modularisieren. | Erhöht langfristig Wartbarkeit und Lesbarkeit. |
| PDF-Vorschau innerhalb der App anbieten. | Komfortgewinn, aber nicht zwingend. |

## 7. Konkrete Textvorschläge

### Buttons

| Aktuell | Vorschlag |
|---|---|
| Anzeigen | Auswertung anzeigen |
| Filter anwenden | Schüler:innenfilter anwenden |
| Drucken / PDF (A4) | Druckansicht öffnen |
| PDF herunterladen | PDF-Datei herunterladen |
| Bericht / PDF | Bericht als PDF öffnen |
| Beurteilung öffnen | Abschlussbeurteilung öffnen |
| Entwurf speichern | Entwurf speichern und nächste:n öffnen |
| Final speichern | Finale Note speichern und nächste:n öffnen |
| Speichern | Mitarbeit speichern |
| Anlegen | Leistungsfeststellung anlegen |
| Bearbeiten/Übersicht | Übersicht und Bearbeitung öffnen |
| Aktuelle Auswahl als Preset speichern | Auswahl als Preset speichern |

### Hilfetexte

| Kontext | Vorschlag |
|---|---|
| Theme | „Ändert nur Ihre persönliche Darstellung. Gespeicherte Daten, Auswertungen und PDF-Dateien bleiben unverändert.“ |
| Gesetzeshinweise | „Blendet kurze LBV-Hinweise in Erfassung und Auswertung ein oder aus. Die gespeicherten Leistungsdaten werden dadurch nicht verändert.“ |
| Kompakte Formulare | „Bündelt längere Eingabebereiche in aufklappbare Abschnitte. Die gespeicherten Daten bleiben gleich.“ |
| Vereinfachte Eingabe | „Empfohlen für schnelle Alltagserfassung. Detailkriterien und Unterrichtskontext werden ausgeblendet, können aber in der normalen Ansicht weiter genutzt werden.“ |
| Quick-Pick | „Zeigt Schüler:innen mit wenigen bisherigen Einträgen in dieser Klasse und diesem Fach. Die Auswahl ist nur ein Vorschlag.“ |
| Leistungsart | „Mehrfachauswahl möglich. Diese Angabe erscheint später in Auswertung und PDF, verändert aber keine automatische Note.“ |
| Beobachtungsbereich | „Grobe fachliche Einordnung der Beobachtung. Ein bis zwei Bereiche reichen meistens.“ |
| Kriterien | „Optionale fachliche Präzisierung. Nutzen Sie Kriterien nur, wenn sie für die spätere Nachvollziehbarkeit wirklich helfen.“ |
| Preset | „Ein Preset füllt typische Felder vor. Es speichert noch keine Mitarbeit und wählt keine Schüler:innen aus.“ |

### Fehlermeldungen

| Situation | Vorschlag |
|---|---|
| Finale Note fehlt | „Für eine finale Speicherung muss eine Note ausgewählt werden. Als Entwurf kann die Beurteilung ohne Note gespeichert werden.“ |
| Klasse/Fach fehlt | „Bitte zuerst Klasse und Fach auswählen. Danach werden Schüler:innen und Leistungsdaten geladen.“ |
| Kein Schuljahr vorhanden | „Es ist kein Schuljahr hinterlegt. Bitte kontaktieren Sie den Admin, damit Semester- und Jahreszeiträume eingerichtet werden.“ |
| Schüler:in nicht gefunden | „Diese Schüler:in gehört nicht zur gewählten Klasse/Fach-Kombination oder ist nicht mehr aktiv.“ |
| Änderungsvermerk fehlt | „Diese Beurteilung war bereits final gespeichert. Bitte begründen Sie kurz, warum sie geändert wird.“ |
| Quick-Pick-Zahl ungültig | „Bitte eine ganze Zahl zwischen 1 und 30 eingeben.“ |
| Option doppelt | „Diese Bezeichnung existiert bereits in Ihrer Liste.“ |

### Hinweise zur Abschlussbeurteilung

| Kontext | Vorschlag |
|---|---|
| Einstieg | „Wählen Sie Klasse, Fach, Schuljahr und Beurteilungszeitraum. Danach bearbeiten Sie die Beurteilung Schüler:in für Schüler:in.“ |
| Notenvorschlag | „Der Vorschlag fasst die dokumentierten Daten zusammen. Er ist keine automatische Note.“ |
| Finale Entscheidung | „Prüfen Sie zuerst Datenlage, Mitarbeit und besondere Leistungen. Die finale Note wählen und speichern Sie bewusst selbst.“ |
| Automatischer Wechsel | „Nach dem Speichern wird automatisch die nächste Schüler:in geöffnet.“ |
| Jahresbeurteilung | „Die App zeigt Semesterinformationen und Entwicklung. Sie berechnet keine Jahresnote als Durchschnitt.“ |

### Hinweise zum Schularbeitsfach

| Status | Vorschlag |
|---|---|
| Ja | „Schularbeitsfach: Ja. Schularbeitsleistungen müssen bei der abschließenden Beurteilung gesondert berücksichtigt werden.“ |
| Nein | „Schularbeitsfach: Nein. Die dokumentierte Mitarbeit und erfasste besondere Leistungen können stärker als Entscheidungsgrundlage dienen.“ |
| Nicht festgelegt | „Schularbeitsfach: nicht festgelegt. Bitte Fachstatus prüfen, damit die Auswertung korrekt interpretiert wird.“ |

### Hinweise zur Datenlage

| Status | Vorschlag |
|---|---|
| Keine Daten | „Datenbasis: keine Einträge im gewählten Zeitraum.“ |
| Dünn | „Datenbasis: dünn. Einschätzung nur vorsichtig verwenden.“ |
| Erste Einschätzung | „Datenbasis: ausreichend für eine erste pädagogische Einschätzung.“ |
| Gut | „Datenbasis: gut. Mehrere dokumentierte Tage stützen die Einschätzung.“ |

### Hinweise zum Speichern

| Aktion | Vorschlag |
|---|---|
| Entwurf | „Speichert den aktuellen Zwischenstand. Die Beurteilung kann später ergänzt oder final gespeichert werden.“ |
| Final | „Speichert die finale Note der Lehrkraft. Spätere Änderungen bleiben möglich, benötigen aber einen Änderungsvermerk.“ |
| Löschen Pickliste | „Die Option wird archiviert. Alte Einträge bleiben nachvollziehbar erhalten.“ |
| Löschen Preset | „Das Preset wird gelöscht. Bereits gespeicherte Mitarbeitseinträge bleiben unverändert.“ |
| Löschen Gruppe | „Die Gruppe wird entfernt. Bereits gespeicherte Bewertungen bleiben bestehen.“ |

## 8. Priorisierte Umsetzungsliste

### 1. Sehr risikoarme Änderungen

| Reihenfolge | Änderung |
|---|---|
| 1 | PDF-nahe Buttons klarer benennen. |
| 2 | Abschlussbeurteilung-Buttons mit „und nächste:n öffnen“ präzisieren. |
| 3 | Datenlage-Labels mit „Datenbasis:“ präzisieren. |
| 4 | Kurzhinweis bei finaler Note ergänzen: Entwurf optional, final verpflichtend. |
| 5 | Hilfe unter Leistungsart, Beobachtungsbereich und Kriterien ergänzen. |
| 6 | Konto-Einstellungen mit kurzen Wirkungshinweisen ergänzen. |

### 2. Wichtige Formular- und Speicherverbesserungen

| Reihenfolge | Änderung |
|---|---|
| 1 | Finale Änderung zusätzlich vor dem Speichern bewusst bestätigen oder deutlicher kennzeichnen. |
| 2 | Schriftliche Leistungsfeststellung gegen Zeilenverwechslung verbessern. |
| 3 | Preset-Anwendung kontrollierter machen, besonders bei bereits ausgefüllter Maske. |
| 4 | Feldnahe Fehlermeldungen in mündlichen und schriftlichen Leistungsformularen ergänzen. |

### 3. Layout-Verbesserungen

| Reihenfolge | Änderung |
|---|---|
| 1 | Abschlussbeurteilung-Kopfbereich verdichten. |
| 2 | Jahresmodus-Abschnitt „Semesterüberblick“ statt „Überblick 1. Semester“. |
| 3 | Besondere Leistungsfeststellungen als gemeinsamer Abschnitt mit zwei Karten ohne doppelte Nummer „2.“ darstellen. |
| 4 | Auswertung in Webansicht responsiver machen. |
| 5 | Mitarbeitserfassung um Sticky-Speicherleiste oder kompakteren Abschlussbereich ergänzen. |

### 4. Größere Refactorings

| Reihenfolge | Änderung |
|---|---|
| 1 | Webauswertung von breiter Tabelle zu kombinierter Schüler:innenliste plus Detailkarten umbauen. |
| 2 | Abschlussbeurteilung weiter als geführten Einzelschüler:innen-Workflow ausbauen. |
| 3 | Schriftliche Leistungsfeststellung optional als Einzel-/Gruppenworkflow statt nur Klassentabelle anbieten. |
| 4 | Einstellungsseiten konsequent als „Ansicht“, „Erfassung“, „Auswertung“ strukturieren. |
| 5 | Tastaturbedienbare Alternativen für Drag & Drop bereitstellen. |

## 9. Nicht anfassen ohne Rückfrage

| Bereich | Warum Rückfrage sinnvoll ist |
|---|---|
| Berechnungslogik für Notenvorschläge | Fachlich und rechtlich sensibel; Änderungen können pädagogische Interpretation verändern. |
| Schwellenwerte für Datenlage | Direkt relevant für Aussage „dünn“, „ausreichend“, „gut“. |
| Mapping von positiv, neutral, negativ | Kernlogik der Auswertung; jede Änderung kann bestehende Berichte beeinflussen. |
| Schularbeitsfach-Interpretation | Soll nur Interpretation, nicht Zählung verändern. Vor Änderung fachlich bestätigen. |
| SOST/NOST/Jahresmodell-Logik | Schulorganisatorisch sensibel und je nach Schule unterschiedlich. |
| Datenbankstruktur der Abschlussbeurteilungen | Momentaufnahme, Historie und Eindeutigkeit sind wichtig; Änderungen nur mit Migrationsplan. |
| PDF-Layout für rechtlich genutzte Berichte | Layoutänderungen können Dokumentationswirkung verändern. |
| Lösch- und Archivierungslogik bei Kriterien/Picklisten | Alte Einträge müssen nachvollziehbar bleiben. |
| Vereinfachte Eingabe als Standard | UX-seitig sinnvoll, aber pädagogisch und organisatorisch vorher bestätigen. |
| Preset-Anwendung per Auto-Submit | Eine Änderung verbessert Kontrolle, kann aber bestehenden schnellen Workflow verändern. |

