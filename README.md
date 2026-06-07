# COOL-Grades

COOL-Grades ist eine PHP/MySQL-Web-App zur dokumentierten Mitarbeitsbewertung und pädagogischen Abschlussbeurteilung. Die App unterstützt Lehrkräfte bei laufenden Mitarbeitseinträgen, besonderen mündlichen und schriftlichen Leistungsfeststellungen, Auswertungen und PDF-Berichten.

Die Software ersetzt keine pädagogische Entscheidung. Notenvorschläge und Auswertungen sind Entscheidungshilfen; die finale Beurteilung bleibt bei der Lehrkraft.

## Funktionen

- Rollen für Administration und Lehrkräfte
- Klassen, Fächer, Schuljahre, Semester und Schulformen verwalten
- Schüler:innen klassenzugeordnet und schuljahresbezogen führen
- schnelle Mitarbeitserfassung mit Kriterien, Presets und Gruppen
- besondere mündliche und schriftliche Leistungsfeststellungen dokumentieren
- Abschlussbeurteilungen als Entwurf oder final speichern
- Auswertungen und PDF-Berichte für Klassen, Fächer und Zeiträume
- Schuljahreswechsel mit Archivierung alter Klassen

## Technische Voraussetzungen

- PHP 8.x
- MySQL oder MariaDB
- Webserver mit PHP-Unterstützung

## Installation

1. Dateien auf den Webserver kopieren, z. B. nach `/cool-grades`.
2. `config.example.php` nach `config.php` kopieren.
3. In `config.php` die Datenbankverbindung eintragen.
4. Datenbankschema aus `schema.sql` einspielen.
5. Bei bestehenden Installationen zusätzlich die Dateien aus `migrations/` in zeitlicher Reihenfolge ausführen.
6. `install.php` im Browser lokal bzw. serverseitig geschützt aufrufen.
7. Standard-Adminzugang nach der Installation sofort ändern.

## Sicherheit

Die Datei `config.php` enthält Zugangsdaten und darf nicht veröffentlicht werden. Logs, Runtime-Daten, Datenbank-Dumps und Uploads gehören ebenfalls nicht in ein öffentliches Git-Repository.

Dieses Repository enthält deshalb nur `config.example.php` als Vorlage.

## Dokumentation

Die Benutzer- und Administrationsdokumentation liegt im Ordner `docs/`.

Weitere Projekthinweise:

- `CHANGELOG.md` dokumentiert Releases und wesentliche Aenderungen.
- `SECURITY.md` beschreibt den Umgang mit Sicherheitsmeldungen.
- `DATENSCHUTZ.md` enthaelt Hinweise zum datenschutzbewussten Betrieb.

## Lizenz

Dieses Projekt steht unter der GNU Affero General Public License v3.0. Details stehen in der Datei `LICENSE`.

## CSV-Import

Der CSV-Import für Schüler:innen erwartet Semikolon-getrennte Daten:

```csv
Vorname;Nachname
Anna;Muster
Max;Beispiel
```
