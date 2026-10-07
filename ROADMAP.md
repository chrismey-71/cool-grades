# Roadmap für COOL-Grades

Diese Roadmap beschreibt geplante Weiterentwicklungen von COOL-Grades. Die genannten Punkte sind noch nicht Bestandteil der aktuellen Version, sondern dienen als fachliche Entwicklungsrichtung.

Grundsatz: COOL-Grades unterstützt Lehrkräfte bei Dokumentation, Auswertung und pädagogischer Entscheidungsfindung. Die App legt keine automatische rechtlich verbindliche Leistungsbeurteilung fest.

## Bereits umgesetzt

- **WebUntis-iCal-Import und Stundenplanansicht** ([#2](https://github.com/chrismey-71/cool-grades/issues/2), geschlossen): Unterrichtsstunden werden aus dem WebUntis-iCal-Feed übernommen, als Wochen-Stundenplan angezeigt und können direkt als Stundenkontext in der Mitarbeitserfassung verwendet werden. Details stehen im CHANGELOG.
- **Kompetenz-Beobachtung** (1.81.5): eigener, nicht bewertender Erfassungsweg für Methoden-, Sozial- und Selbstkompetenz mit Kompetenzprofil. Er dient als Vorlage für die formative Lernrückmeldung.
- **Sitzplan-Editor** (1.81.6): freie Tischanordnung mit Vorlagen (Lerninseln, U-Form, Fischgräte u. a.) für Mitarbeitserfassung und Kompetenz-Beobachtung.

## Nächster geplanter Entwicklungsschritt

### 1. Formative Lernrückmeldung

GitHub-Issue: [#4 Formative Lernrückmeldung: Kennzeichnung, Erfassung und getrennte Auswertung](https://github.com/chrismey-71/cool-grades/issues/4) (fasst die früheren Issues #4, #5, #6 und #7 zusammen)

Ziel ist, Lernfortschritte und Rückmeldungen zu dokumentieren, ohne dass daraus automatisch eine Bewertung oder ein Notenvorschlag entsteht.

Ausgangslage: Mitarbeitseinträge lassen sich bereits als „lernbegleitend (formativ)“ oder „bilanzierend (summativ)“ beschriften, fließen aber unabhängig davon alle in die Notenvorschläge ein.

Umsetzung in drei Schritten:

1. **Bewertungsrelevanz kennzeichnen:** Jeder Eintrag ist eindeutig *bewertungsrelevant*, *nur Lernrückmeldung* oder *private Notiz*. Nur bewertungsrelevante Einträge fließen in Notenvorschläge ein. Bestehende Einträge bleiben bewertungsrelevant, damit sich bereits berechnete Notenvorschläge nicht nachträglich verändern.
2. **Lernrückmeldung erfassen:** eigener Bereich nach dem Muster der Kompetenz-Beobachtung, mit den Feldern Lernziel, Erfolgskriterium, beobachteter Lernstand, nächster Lernschritt und Kommentar. Die Erfassung muss im Unterricht schnell gehen.
3. **Getrennte Anzeige:** eigener Abschnitt „Formative Lernrückmeldungen“ in Webauswertung und PDF-Berichten, getrennt von der Beurteilungsgrundlage.

Geplanter Nutzen:

- klare Trennung zwischen Lernbegleitung und Leistungsbeurteilung
- weniger Risiko, formative Hinweise versehentlich als bewertungsrelevant zu behandeln
- konkretere, handlungsorientierte Rückmeldungen als Grundlage für Feedbackgespräche

## Weitere geplante Entwicklungsschritte

### 2. Lernentwicklungs- und Reflexionsansicht

GitHub-Issue: [#10 Lernentwicklungs- und Reflexionsansicht planen](https://github.com/chrismey-71/cool-grades/issues/10) (setzt #4 voraus)

Ziel ist, Entwicklungen über einen längeren Zeitraum sichtbar zu machen. Die Ansicht folgt dem Muster von Kompetenzprofil und Kriterien-Profil (Auswertung je Schüler:in über einen gewählten Zeitraum).

Mögliche Inhalte:

- Verlauf pro Schüler:in
- Lernziele mit Entwicklung des Lernstands
- Übersicht über wiederkehrende nächste Schritte
- Reflexionsnotizen der Lehrkraft

### 3. Beteiligung der Schüler:innen: Selbst- und Peer-Feedback

GitHub-Issue: [#8 Beteiligung der Schüler:innen: Zugang, Selbst- und Peer-Feedback (Konzept)](https://github.com/chrismey-71/cool-grades/issues/8) (fasst die früheren Issues #8 und #9 zusammen)

Ausgangslage: COOL-Grades kennt nur Lehrkräfte und Admins. Selbst- und Peer-Feedback setzen einen Zugang für Schüler:innen voraus und sind damit eine Architekturentscheidung.

Zuerst zu klären:

- Zugangsart (Schüler:innen-Konten, zeitlich begrenzte Links oder Erfassung am Gerät der Lehrkraft)
- Sichtbarkeit, Freigabe durch die Lehrkraft, Speicherung und Löschung
- datenschutzrechtliche Grundlage und Abstimmung mit der Schule

Anwendungsfälle danach:

- **Selbstfeedback:** Schüler:in schätzt den eigenen Lernstand zu einem Lernziel ein, sichtbar nur für die Lehrkraft.
- **Peer-Feedback:** strukturierte Rückmeldungen zu Präsentationen, Gruppenarbeiten oder Projekten anhand vorgegebener Kriterien, mit Freigabe durch die Lehrkraft.

Weder Selbst- noch Peer-Feedback fließen ohne Entscheidung der Lehrkraft in Notenvorschläge ein.

## Nicht-Ziele

COOL-Grades soll auch künftig nicht:

- automatisch rechtlich verbindliche Noten festlegen
- formative Rückmeldungen ungeprüft in Notenvorschläge einrechnen
- Schüler:innen-Feedback ohne pädagogische Steuerung veröffentlichen
- gesetzliche Gewichtungen oder automatische Beurteilungsformeln vortäuschen

## Geplante GitHub-Issues

1. [#4 Formative Lernrückmeldung: Kennzeichnung, Erfassung und getrennte Auswertung](https://github.com/chrismey-71/cool-grades/issues/4)
2. [#10 Lernentwicklungs- und Reflexionsansicht planen](https://github.com/chrismey-71/cool-grades/issues/10)
3. [#8 Beteiligung der Schüler:innen: Zugang, Selbst- und Peer-Feedback (Konzept)](https://github.com/chrismey-71/cool-grades/issues/8)

Die früheren Issues #5, #6 und #7 sind in #4 aufgegangen, #9 in #8.
