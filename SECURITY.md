# Security Policy

COOL-Grades verarbeitet potentiell besonders schutzbeduerftige schulische Leistungs- und Personendaten. Sicherheitsmeldungen sollen deshalb verantwortungsvoll und nicht oeffentlich erfolgen.

## Unterstuetzte Versionen

| Version | Status |
| --- | --- |
| 1.62 und neuer | Sicherheitskorrekturen vorgesehen |
| aeltere Entwicklungsstaende | keine garantierte Pflege |

## Sicherheitsluecken melden

Bitte melden Sie Sicherheitsprobleme nicht als oeffentliches Issue, wenn dadurch personenbezogene Daten, Zugangsdaten oder konkrete Angriffsschritte offengelegt werden koennten.

Empfohlener Meldeweg:

1. Wenn im GitHub-Repository private vulnerability reporting aktiviert ist, bitte diese Funktion verwenden.
2. Andernfalls bitte den Repository-Inhaber ueber GitHub kontaktieren und zunaechst nur eine kurze, nicht ausnutzbare Beschreibung senden.
3. Zugangsdaten, echte Schueler:innendaten, Datenbank-Dumps oder Logdateien bitte nicht unaufgefordert uebermitteln.

Eine hilfreiche Meldung enthaelt:

- betroffene Version oder Commit-ID
- betroffene Seite oder Funktion
- kurze Beschreibung der Auswirkung
- reproduzierbare Schritte ohne Echtdaten
- Hinweise, ob personenbezogene Daten betroffen sein koennten

## Erwarteter Umgang

Nach Eingang einer Meldung sollte:

- der Eingang bestaetigt werden,
- die Auswirkung geprueft werden,
- bei Bedarf ein Fix vorbereitet werden,
- die Veroeffentlichung erst erfolgen, wenn eine sichere Korrektur verfuegbar ist.

## Betriebssicherheit

Betreiber:innen einer Installation sollten mindestens folgende Punkte beachten:

- `config.php`, Logs, Datenbank-Dumps und Backups niemals veroeffentlichen.
- HTTPS verwenden.
- starke Admin-Passwoerter und individuelle Benutzerkonten verwenden.
- Standardzugang nach der Installation sofort aendern.
- Schreibrechte auf dem Server auf das notwendige Minimum beschraenken.
- Backups verschluesselt und zugriffsgeschuetzt speichern.
- Updates und Sicherheitskorrekturen zeitnah einspielen.
- Logs regelmaessig pruefen und nicht dauerhaft unnoetig aufbewahren.

## Kein Sicherheitsversprechen

Dieses Projekt wird ohne Gewaehrleistung bereitgestellt. Vor dem produktiven Einsatz in einer Schule ist eine eigene technische und datenschutzrechtliche Pruefung erforderlich.
