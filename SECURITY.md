# Security Policy

COOL-Grades verarbeitet potentiell besonders schutzbedürftige schulische Leistungs- und Personendaten. Sicherheitsmeldungen sollen deshalb verantwortungsvoll und nicht öffentlich erfolgen.

## Unterstützte Versionen

| Version | Status |
| --- | --- |
| 1.62 und neuer | Sicherheitskorrekturen vorgesehen |
| ältere Entwicklungsstände | keine garantierte Pflege |

## Sicherheitslücken melden

Bitte melden Sie Sicherheitsprobleme nicht als öffentliches Issue, wenn dadurch personenbezogene Daten, Zugangsdaten oder konkrete Angriffsschritte offengelegt werden könnten.

Empfohlener Meldeweg:

1. Wenn im GitHub-Repository private vulnerability reporting aktiviert ist, bitte diese Funktion verwenden.
2. Andernfalls bitte den Repository-Inhaber über GitHub kontaktieren und zunächst nur eine kurze, nicht ausnutzbare Beschreibung senden.
3. Zugangsdaten, echte Schüler:innendaten, Datenbank-Dumps oder Logdateien bitte nicht unaufgefordert übermitteln.

Eine hilfreiche Meldung enthält:

- betroffene Version oder Commit-ID
- betroffene Seite oder Funktion
- kurze Beschreibung der Auswirkung
- reproduzierbare Schritte ohne Echtdaten
- Hinweise, ob personenbezogene Daten betroffen sein könnten

## Erwarteter Umgang

Nach Eingang einer Meldung sollte:

- der Eingang bestätigt werden,
- die Auswirkung geprüft werden,
- bei Bedarf ein Fix vorbereitet werden,
- die Veröffentlichung erst erfolgen, wenn eine sichere Korrektur verfügbar ist.

## Betriebssicherheit

Betreiber:innen einer Installation sollten mindestens folgende Punkte beachten:

- `config.php`, Logs, Datenbank-Dumps und Backups niemals veröffentlichen.
- HTTPS verwenden.
- starke Admin-Passwörter und individuelle Benutzerkonten verwenden.
- Standardzugang nach der Installation sofort ändern.
- Schreibrechte auf dem Server auf das notwendige Minimum beschränken.
- Backups verschlüsselt und zugriffsgeschützt speichern.
- Updates und Sicherheitskorrekturen zeitnah einspielen.
- Logs regelmäßig prüfen und nicht dauerhaft unnötig aufbewahren.

## Kein Sicherheitsversprechen

Dieses Projekt wird ohne Gewährleistung bereitgestellt. Vor dem produktiven Einsatz in einer Schule ist eine eigene technische und datenschutzrechtliche Prüfung erforderlich.
