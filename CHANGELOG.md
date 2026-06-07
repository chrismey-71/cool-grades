# Changelog

Alle wesentlichen Änderungen an COOL-Grades werden in dieser Datei dokumentiert.

Das Format orientiert sich an "Keep a Changelog". Die Versionsnummern folgen der App-Version in `version.json`.

## [Unreleased]

### Geplant

- weitere Fehlerkorrekturen und Verbesserungen nach Bedarf
- Sicherheits- und Dokumentationspflege

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

[Unreleased]: https://github.com/chrismey-71/cool-grades/compare/v1.62...HEAD
[1.62]: https://github.com/chrismey-71/cool-grades/releases/tag/v1.62
