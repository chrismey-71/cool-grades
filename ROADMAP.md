# Roadmap für COOL-Grades

Diese Roadmap beschreibt geplante Weiterentwicklungen von COOL-Grades. Die genannten Punkte sind noch nicht Bestandteil der aktuellen Version, sondern dienen als fachliche Entwicklungsrichtung.

Grundsatz: COOL-Grades unterstützt Lehrkräfte bei Dokumentation, Auswertung und pädagogischer Entscheidungsfindung. Die App legt keine automatische rechtlich verbindliche Leistungsbeurteilung fest.

## Nächster geplanter Entwicklungsschritt

### 1. WebUntis-iCal-Import und Stundenplanansicht für den Stundenkontext

GitHub-Issue: [#2 WebUntis-iCal-Import für Stundenkontext vorbereiten](https://github.com/chrismey-71/cool-grades/issues/2)

Status: geplant, Umsetzung erst sinnvoll, sobald echte WebUntis-Daten aus dem laufenden Schuljahr verfügbar sind.

Ziel ist, Unterrichtsstunden aus einem WebUntis-iCal-/ICS-Feed als Vorschläge in COOL-Grades zu übernehmen. Die importierten Stunden sollen nicht nur technisch gespeichert werden, sondern für Lehrer:innen als übersichtlicher Stundenplan sichtbar sein.

Geplanter Nutzen für Lehrer:innen:

- der eigene Stundenplan kann in COOL-Grades angezeigt werden
- Unterrichtsstunden können direkt aus dem Stundenplan ausgewählt werden
- Datum, Uhrzeit, Fach, Klasse/Gruppe, Raum und Thema müssen nicht erneut manuell eingegeben werden
- die passende Stunde kann in der Mitarbeitserfassung als Stundenkontext übernommen werden
- bereits dokumentierte Stunden können sichtbar markiert werden
- doppelte oder versehentliche Mehrfacherfassungen werden leichter vermieden
- manuelle Stundenerfassung bleibt weiterhin möglich

Möglicher Workflow:

1. Lehrkraft hinterlegt ihren privaten WebUntis-iCal-Link in einem geschützten Konto- oder Verwaltungsbereich.
2. Die App ruft den iCal-/ICS-Feed manuell per Button oder später optional automatisiert ab.
3. Die App zeigt die importierten Stunden in einer Wochen- oder Tagesansicht an.
4. Die Lehrkraft wählt eine konkrete Stunde aus dem angezeigten Stundenplan.
5. COOL-Grades zeigt an, ob für diese Stunde bereits Mitarbeitseinträge vorhanden sind.
6. Die Lehrkraft kann aus der Stunde heraus direkt zur Mitarbeitserfassung wechseln.
7. Der Stundenkontext wird in der Mitarbeitserfassung vorausgefüllt.
8. Unklare oder nicht zuordenbare WebUntis-Termine werden nicht automatisch falsch gespeichert, sondern in einer Vorschau zur Prüfung angezeigt.

Mögliche Anzeige im Stundenplan:

- normale Stunde: neutral dargestellt
- Stunde mit vorhandenen Mitarbeitseinträgen: dezent markiert, z. B. mit kleinem Symbol oder Zähler
- Stunde mit unklarer Zuordnung: Hinweis „Zuordnung prüfen“
- entfallene oder geänderte Stunde: gesondert kennzeichnen, falls WebUntis diese Information liefert

Wichtige Anforderungen:

- iCal-Links enthalten Token und müssen wie Zugangsdaten behandelt werden
- keine Veröffentlichung von Token in GitHub, Logs, Screenshots oder PDF
- Import nur für berechtigte Lehrer:innen und deren Schulen/Klassen/Fächer
- Vorschau vor dem Speichern
- keine doppelten Stunden
- Anzeige, in welchen Stunden bereits Einträge vorhanden sind
- Auswahl einer Stunde direkt aus dem Stundenplan heraus
- manuelle Stundenerfassung bleibt möglich

## Weitere geplante Entwicklungsschritte

### 2. Formative Lernrückmeldungen als eigener Workflow

Ziel ist ein eigener Bereich für Lernrückmeldungen, die nicht automatisch bewertungsrelevant sind.

Geplanter Nutzen für Lehrer:innen:

- Lernfortschritte dokumentieren, ohne sofort eine Note oder Bewertung abzuleiten
- klare Trennung zwischen Lernbegleitung und Leistungsbeurteilung
- bessere Grundlage für Feedbackgespräche

Geplanter Nutzen für Schüler:innen, falls später sichtbar gemacht:

- verständlichere Hinweise zum eigenen Lernstand
- konkrete nächste Lernschritte
- weniger Verwechslung zwischen Feedback und Note

Mögliche Umsetzung:

- neuer Bereich „Lernrückmeldung erfassen“
- Auswahl: Lernhinweis, bewertungsrelevanter Eintrag, private Notiz
- keine automatische Einrechnung in Notenvorschläge
- deutliche Kennzeichnung in Webansicht und Exporten

### 3. Trennung zwischen Lernhinweisen und bewertungsrelevanten Einträgen

Ziel ist, dass jeder Eintrag klar erkennen lässt, ob er in Auswertungen und Notenvorschläge einfließt.

Geplanter Nutzen:

- mehr Transparenz für Lehrkräfte
- weniger Risiko, formative Hinweise versehentlich als bewertungsrelevant zu behandeln
- bessere Nachvollziehbarkeit bei Auswertungen und Abschlussbeurteilungen

Mögliche Umsetzung:

- Kennzeichnung pro Eintrag:
  - bewertungsrelevant
  - nur formative Lernrückmeldung
  - private Notiz
- Filter in Auswertungen
- getrennte Anzeige in PDF-Berichten

### 4. Lernziele, Erfolgskriterien, Lernstand und nächste Schritte

Ziel ist eine strukturierte Rückmeldung nach pädagogischen Gesichtspunkten.

Mögliche Felder:

- Lernziel
- Erfolgskriterium
- beobachteter Lernstand
- nächster Lernschritt
- optionaler Kommentar

Geplanter Nutzen:

- Rückmeldungen werden konkreter und handlungsorientierter
- Schüler:innen erhalten klarere Hinweise, was sie bereits können und woran sie weiterarbeiten sollen
- Lehrkräfte können Lernentwicklung besser nachvollziehen

### 5. Separate Ausweisung formativer Rückmeldungen in Auswertung und PDF

Ziel ist, formative Rückmeldungen sichtbar zu machen, aber nicht mit bewertungsrelevanter Mitarbeit zu vermischen.

Geplanter Nutzen:

- Auswertungen bleiben rechtlich und pädagogisch klar
- PDF-Berichte können Lernentwicklung zusätzlich zur Beurteilungsgrundlage zeigen
- Notenvorschläge bleiben getrennt von reinen Lernhinweisen

Mögliche Umsetzung:

- eigener Abschnitt „Formative Lernrückmeldungen“
- Anzeige von Lernzielen, Lernstand und nächsten Schritten
- Hinweis, dass diese Rückmeldungen nicht automatisch eine Note festlegen

### 6. Optionale Selbstfeedback-Funktion

Ziel ist, Schüler:innen einfache Selbsteinschätzungen zu ermöglichen, sofern die Schule bzw. Lehrkraft dies nutzen möchte.

Mögliche Umsetzung:

- Schüler:in schätzt eigenen Lernstand zu einem Lernziel ein
- Lehrkraft sieht Selbstfeedback ergänzend zur eigenen Beobachtung
- Selbstfeedback wird nicht automatisch bewertet

Geplanter Nutzen:

- stärkere Eigenverantwortung der Schüler:innen
- bessere Gesprächsgrundlage
- Unterstützung einer reflektierten Lernkultur

### 7. Optionale Peer-Feedback-Funktion

Ziel ist, strukturiertes Feedback zwischen Schüler:innen zu ermöglichen, z. B. bei Präsentationen, Gruppenarbeiten oder Projekten.

Wichtige Voraussetzung:

- Datenschutz und Sichtbarkeit müssen sehr sorgfältig geregelt werden
- Lehrkraft muss steuern können, wer was sieht
- Peer-Feedback darf nicht ungeprüft in Notenvorschläge einfließen

Mögliche Umsetzung:

- aktivierbare Funktion pro Klasse/Fach/Aufgabe
- vorgegebene Feedbackkriterien
- Freigabe oder Sichtprüfung durch Lehrkraft

### 8. Lernentwicklungsansicht und Reflexionsübersicht

Ziel ist, Entwicklungen über einen längeren Zeitraum sichtbar zu machen.

Geplanter Nutzen:

- Lehrkräfte erkennen Fortschritte, wiederkehrende Schwierigkeiten und Entwicklungslinien
- Schüler:innen können Lernfortschritt besser nachvollziehen
- Auswertung wird stärker pädagogisch interpretierbar

Mögliche Umsetzung:

- Verlauf pro Schüler:in
- Lernziele mit Statusentwicklung
- Übersicht über wiederkehrende nächste Schritte
- Reflexionsnotizen der Lehrkraft

## Nicht-Ziele

COOL-Grades soll auch künftig nicht:

- automatisch rechtlich verbindliche Noten festlegen
- formative Rückmeldungen ungeprüft in Notenvorschläge einrechnen
- Schüler:innen-Feedback ohne pädagogische Steuerung veröffentlichen
- gesetzliche Gewichtungen oder automatische Beurteilungsformeln vortäuschen

## Geplante GitHub-Issues

Die Roadmap soll in einzelne, fachlich prüfbare GitHub-Issues aufgeteilt werden:

1. WebUntis-iCal-Import und Stundenplanansicht vorbereiten und umsetzen
2. Formative Lernrückmeldungen als eigenen Workflow konzipieren
3. Bewertungsrelevanz von Einträgen eindeutig kennzeichnen
4. Strukturierte Lernrückmeldung mit Lernziel, Erfolgskriterium, Lernstand und nächstem Schritt einführen
5. Formative Rückmeldungen getrennt in Webauswertung und PDF-Berichten anzeigen
6. Selbstfeedback für Schüler:innen fachlich und datenschutzrechtlich prüfen
7. Peer-Feedback als optionalen Workflow konzipieren
8. Lernentwicklungs- und Reflexionsansicht planen
