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

[Unreleased]: https://github.com/chrismey-71/cool-grades/compare/v1.64...HEAD
[1.64]: https://github.com/chrismey-71/cool-grades/compare/v1.63...v1.64
[1.63]: https://github.com/chrismey-71/cool-grades/compare/v1.62...v1.63
[1.62]: https://github.com/chrismey-71/cool-grades/releases/tag/v1.62
