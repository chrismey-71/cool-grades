# Datenschutzhinweis

Dieser Hinweis beschreibt den datenschutzbezogenen Rahmen des Projekts COOL-Grades. Er ersetzt keine rechtsverbindliche Datenschutzerklaerung fuer eine konkrete Schule oder Installation.

Vor einem produktiven Einsatz muessen Betreiber:innen die datenschutzrechtlichen Anforderungen ihrer Schule, ihres Traegers und der jeweils geltenden Rechtslage pruefen.

## Zweck der Anwendung

COOL-Grades dient der Dokumentation und Auswertung von:

- laufender Mitarbeit,
- besonderen muendlichen Leistungsfeststellungen,
- besonderen schriftlichen Leistungsfeststellungen,
- paedagogischen Abschlussbeurteilungen durch Lehrkraefte.

Die App unterstuetzt Lehrkraefte bei einer nachvollziehbaren Dokumentation. Sie ersetzt keine paedagogische oder rechtliche Entscheidung.

## Moegliche personenbezogene Daten

Je nach Nutzung koennen in einer Installation insbesondere folgende Daten verarbeitet werden:

- Namen von Schueler:innen,
- Klassenzuordnungen und Schuljahre,
- Faecher und Lehrkraefte,
- Mitarbeitsbeobachtungen,
- Kriterien und Leistungsarten,
- Kommentare und Notizen,
- besondere muendliche und schriftliche Leistungsfeststellungen,
- gespeicherte Abschlussbeurteilungen,
- Benutzerkonten und Rollen,
- technische Protokolldaten.

Das oeffentliche GitHub-Repository enthaelt keine Produktivdaten und soll niemals echte Schueler:innen-, Lehrer:innen-, Datenbank- oder Logdaten enthalten.

## Verantwortlichkeit

Fuer den konkreten Betrieb ist die jeweilige Schule, Institution oder betreibende Stelle verantwortlich. Dazu gehoeren insbesondere:

- Festlegung der rechtlichen Grundlage,
- Information der betroffenen Personen,
- Berechtigungskonzept,
- Aufbewahrungs- und Loeschfristen,
- technische und organisatorische Massnahmen,
- Sicherung von Backups,
- Umgang mit Auskunfts-, Berichtigungs- und Loeschanfragen.

## Grundsaetze fuer den Einsatz

Beim Einsatz von COOL-Grades sollten mindestens folgende Grundsaetze beachtet werden:

- nur erforderliche Daten erfassen,
- sachlich und fachbezogen dokumentieren,
- keine unnoetigen Persoenlichkeitsurteile speichern,
- Zugriff nur fuer berechtigte Personen erlauben,
- starke Passwoerter und individuelle Konten verwenden,
- HTTPS einsetzen,
- regelmaessige Backups schuetzen und verschluesseln,
- Logs nicht laenger als erforderlich speichern,
- alte Daten nach festgelegten Fristen archivieren oder loeschen,
- Produktivdaten niemals in GitHub, Testumgebungen oder Screenshots veroeffentlichen.

## Repository und Demo-Daten

Dieses Repository ist fuer Quellcode, Dokumentation und Demo-Material gedacht.

Nicht in das Repository gehoeren:

- `config.php`,
- `.env`-Dateien,
- Datenbank-Dumps,
- Backups,
- Logdateien,
- echte Schueler:innenlisten,
- echte Leistungsdaten,
- Screenshots mit Echtdaten,
- Exportdateien aus produktiven Installationen.

Demo-Daten muessen anonymisiert oder frei erfunden sein.

## Technische Schutzmassnahmen

Die Anwendung bringt grundlegende Schutzmechanismen mit, etwa Rollen, Login, Konfigurationsdateien ausserhalb des Git-Trackings und Logging. Der sichere Betrieb haengt aber wesentlich von der konkreten Serverumgebung ab.

Betreiber:innen sollten insbesondere pruefen:

- Serverhaertung,
- HTTPS-Konfiguration,
- Dateirechte,
- Datenbankrechte,
- Backup-Konzept,
- Update-Prozess,
- Protokollierung und Log-Aufbewahrung,
- Schutz vor unberechtigtem Zugriff.

## Hinweise zu Kommentaren und Notizen

Freitextfelder koennen besonders sensible Informationen enthalten. Lehrkraefte sollten Kommentare kurz, sachlich und fachbezogen formulieren.

Empfohlen:

- beobachtbare Leistung beschreiben,
- konkrete Unterrichtssituation nennen,
- keine Diagnosen oder persoenlichen Zuschreibungen speichern,
- keine privaten Informationen erfassen,
- Verhalten und fachliche Leistung trennen.

## Keine Rechtsberatung

Dieser Hinweis ist eine technische und organisatorische Orientierung fuer das Projekt. Er ist keine Rechtsberatung und keine abschliessende Datenschutzerklaerung nach DSGVO. Vor dem produktiven Einsatz sollte eine schulinterne oder rechtliche Pruefung erfolgen.
