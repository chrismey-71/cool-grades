# Changelog

Alle wesentlichen Änderungen an COOL-Grades werden in dieser Datei dokumentiert.

Das Format orientiert sich an "Keep a Changelog". Die Versionsnummern folgen der App-Version in `version.json`.

## [Unreleased]

### Geplant

- formative Lernrückmeldungen als eigener Workflow neben bewertungsrelevanter Mitarbeit
- Trennung zwischen reinen Lernhinweisen und Einträgen, die in Notenvorschläge einfließen
- Lernziele, Erfolgskriterien, beobachteter Lernstand und nächste Lernschritte pro Rückmeldung
- separate Darstellung formativer Lernrückmeldungen in Webauswertung und PDF-Berichten
- optionale Selbst- und Peer-Feedback-Funktionen
- stärkere Unterstützung von Lernentwicklung, Feedbackkultur und pädagogischer Reflexion
- weitere Fehlerkorrekturen, Sicherheits- und Dokumentationspflege nach Bedarf

## [1.68] - 2026-06-19

### Hinzugefügt

- lehrkraftbezogene Notenübersicht über alle zugeordneten Klassen und Fächer eines Schuljahres
- Filter nach Schuljahr, Beurteilungszeitraum, Klasse, Fach und Bearbeitungsstand
- Gesamtfortschritt für finale Beurteilungen, Entwürfe und noch offene Beurteilungen
- direkter Wechsel von der Übersicht zur Abschlussbeurteilung einer einzelnen Schüler:in
- PDF-Gesamtbericht im A4-Querformat, gruppiert nach Klasse und Fach

### Geändert

- Jahresmodell und semestrierte Modelle verwenden in der Gesamtübersicht jeweils den fachlich passenden aktuellen Beurteilungszeitraum
- Lehrer:innen-Handbuch um die neue Notenübersicht und ihre Filterwirkung ergänzt
- Versionsnummer auf 1.68 erhöht

## [1.67] - 2026-06-17

### Hinzugefügt

- GitHub-Issue-Templates für Fehlermeldungen, Funktionswünsche und Dokumentationshinweise
- GitHub-Discussion-Templates für allgemeine Nachrichten, Ideen und Fragen
- Issue-Konfiguration mit Hinweis auf Discussions für allgemeine Rückmeldungen und auf die Security Policy für Sicherheitsmeldungen

### Geändert

- Versionsnummer auf 1.67 erhöht

## [1.66] - 2026-06-17

### Hinzugefügt

- In der Mitarbeitserfassung werden Schüler:innen, die in der ausgewählten Stunde bereits bewertet wurden, blasser dargestellt
- Direkt am Namen erscheint ein kompakter Zähler der vorhandenen Einträge, z. B. `(1)` oder `(2)`
- Ein kurzer Hinweis erklärt die blasse Darstellung nur dann, wenn bereits Bewertungen in der Stunde vorhanden sind

### Geändert

- Die Ermittlung vorhandener Stundeneinträge erfolgt gesammelt über eine gruppierte Datenbankabfrage statt je Schüler:in einzeln
- Versionsnummer auf 1.66 erhöht

## [1.65] - 2026-06-16

### Geändert

- Beurteilungslogik für das Jahresmodell fachlich korrigiert
- Im Jahresmodell werden nur noch „Schulnachricht festlegen“ und „Jahresbeurteilung festlegen“ angeboten
- Die Option „2. Semesterbeurteilung festlegen“ wird im Jahresmodell nicht mehr angezeigt
- SOST- und NOST-Semesterlogik bleiben unverändert
- PDF-Ausgabe der Abschlussbeurteilung verwendet im Jahresmodell „Schulnachricht“ statt „1. Semester“
- Versionsnummer auf 1.65 erhöht

### Migration

- Bestehende „2. Semesterbeurteilungen“ aus Jahresmodell-Klassen werden einmalig nach „Jahresbeurteilung“ kopiert, wenn dort noch kein Jahresdatensatz existiert
- Bereits vorhandene Jahresbeurteilungen werden nicht überschrieben
- Konfliktfälle mit vorhandener 2.-Semester- und Jahresbeurteilung werden als Event `migration_yearly_semester2_to_year_conflict` protokolliert

## [1.64] - 2026-06-10

### Hinzugefügt

- zentrale Sicherheitsheader für gerenderte App-Seiten
- datenbankgestützte Login-Ratenbegrenzung mit konfigurierbarer Fehlversuchsanzahl, Verzögerung und temporärer Sperre
- konfigurierbare Passwortregeln im Adminbereich
- Einmal-Token und Lock-Datei für `install.php`
- konfigurierbarer Log-Pfad außerhalb des Webroots
- Betriebsdokumentation zu Sicherheitsheadern, Installation, Nginx und Logdateien

### Geändert

- Passwortprüfung für Kontoänderung, Lehrkräfteanlage und Passwort-Reset vereinheitlicht
- `.DS_Store`, AppleDouble-Dateien und `install.lock` explizit vom Git-Tracking ausgeschlossen
- Versionsnummer auf 1.64 erhöht

## [1.63] - 2026-06-08

### Hinzugefügt

- Admin-Pflegebereich für Impressum und Datenschutzbestimmung mit einfachem HTML-Editor
- öffentliche Seiten für Impressum und Datenschutz über die Fußzeile
- Migration für längere HTML-Inhalte in globalen App-Einstellungen

### Geändert

- Versionsnummer auf 1.63 erhöht

## [1.62] - 2026-06-07

### Hinzugefügt

- erste öffentliche Projektvorbereitung für GitHub
- AGPL-3.0-Lizenzdatei
- Sicherheitsrichtlinie
- Datenschutzhinweis für Repository und Betrieb
- Changelog als Grundlage für künftige Releases

### Enthaltene Hauptfunktionen

- Rollen für Administration und Lehrkräfte
- Verwaltung von Schulen, Schulformen, Schuljahren, Semestern, Klassen, Fächern, Lehrkräften und Schüler:innen
- schuljahresbezogene Klassenzuordnungen und Schuljahreswechsel
- schnelle Mitarbeitserfassung mit Kriterien, Presets, Gruppen und vereinfachter Eingabe
- besondere mündliche Leistungsfeststellungen
- besondere schriftliche Leistungsfeststellungen mit differenzierten Leistungsarten
- Auswertungen pro Klasse, Fach und Zeitraum
- PDF-Berichte
- Abschlussbeurteilungen als Entwurf oder final gespeicherte pädagogische Entscheidung
- Lehrer:innen-Handbuch und Administrationsdokumentation

### Sicherheit und Datenschutz

- lokale Konfigurationsdateien, Logs, Runtime-Daten und Datenbankdateien sind vom Git-Tracking ausgeschlossen
- `config.example.php` dient als Vorlage ohne echte Zugangsdaten

[Unreleased]: https://github.com/chrismey-71/cool-grades/compare/v1.67...HEAD
[1.67]: https://github.com/chrismey-71/cool-grades/compare/v1.66...v1.67
[1.66]: https://github.com/chrismey-71/cool-grades/compare/v1.65...v1.66
[1.65]: https://github.com/chrismey-71/cool-grades/compare/v1.64...v1.65
[1.64]: https://github.com/chrismey-71/cool-grades/compare/v1.63...v1.64
[1.63]: https://github.com/chrismey-71/cool-grades/compare/v1.62...v1.63
[1.62]: https://github.com/chrismey-71/cool-grades/releases/tag/v1.62
