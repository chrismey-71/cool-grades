# Datenschutzhinweis

Dieser Hinweis beschreibt den datenschutzbezogenen Rahmen des Projekts COOL-Grades. Er ersetzt keine rechtsverbindliche Datenschutzerklärung für eine konkrete Schule oder Installation.

Vor einem produktiven Einsatz müssen Betreiber:innen die datenschutzrechtlichen Anforderungen ihrer Schule, ihres Trägers und der jeweils geltenden Rechtslage prüfen.

## Zweck der Anwendung

COOL-Grades dient der Dokumentation und Auswertung von:

- laufender Mitarbeit,
- besonderen mündlichen Leistungsfeststellungen,
- besonderen schriftlichen Leistungsfeststellungen,
- pädagogischen Abschlussbeurteilungen durch Lehrkräfte.

Die App unterstützt Lehrkräfte bei einer nachvollziehbaren Dokumentation. Sie ersetzt keine pädagogische oder rechtliche Entscheidung.

## Mögliche personenbezogene Daten

Je nach Nutzung können in einer Installation insbesondere folgende Daten verarbeitet werden:

- Namen von Schüler:innen,
- Klassenzuordnungen und Schuljahre,
- Fächer und Lehrkräfte,
- Mitarbeitsbeobachtungen,
- Kriterien und Leistungsarten,
- Kommentare und Notizen,
- besondere mündliche und schriftliche Leistungsfeststellungen,
- gespeicherte Abschlussbeurteilungen,
- Benutzerkonten und Rollen,
- technische Protokolldaten.

Das öffentliche GitHub-Repository enthält keine Produktivdaten und soll niemals echte Schüler:innen-, Lehrer:innen-, Datenbank- oder Logdaten enthalten.

## Verantwortlichkeit

Für den konkreten Betrieb ist die jeweilige Schule, Institution oder betreibende Stelle verantwortlich. Dazu gehören insbesondere:

- Festlegung der rechtlichen Grundlage,
- Information der betroffenen Personen,
- Berechtigungskonzept,
- Aufbewahrungs- und Löschfristen,
- technische und organisatorische Maßnahmen,
- Sicherung von Backups,
- Umgang mit Auskunfts-, Berichtigungs- und Löschanfragen.

## Grundsätze für den Einsatz

Beim Einsatz von COOL-Grades sollten mindestens folgende Grundsätze beachtet werden:

- nur erforderliche Daten erfassen,
- sachlich und fachbezogen dokumentieren,
- keine unnötigen Persönlichkeitsurteile speichern,
- Zugriff nur für berechtigte Personen erlauben,
- starke Passwörter und individuelle Konten verwenden,
- HTTPS einsetzen,
- regelmäßige Backups schützen und verschlüsseln,
- Logs nicht länger als erforderlich speichern,
- alte Daten nach festgelegten Fristen archivieren oder löschen,
- Produktivdaten niemals in GitHub, Testumgebungen oder Screenshots veröffentlichen.

## Repository und Demo-Daten

Dieses Repository ist für Quellcode, Dokumentation und Demo-Material gedacht.

Nicht in das Repository gehören:

- `config.php`,
- `.env`-Dateien,
- Datenbank-Dumps,
- Backups,
- Logdateien,
- echte Schüler:innenlisten,
- echte Leistungsdaten,
- Screenshots mit Echtdaten,
- Exportdateien aus produktiven Installationen.

Demo-Daten müssen anonymisiert oder frei erfunden sein.

## Technische Schutzmaßnahmen

Die Anwendung bringt grundlegende Schutzmechanismen mit, etwa Rollen, Login, Konfigurationsdateien außerhalb des Git-Trackings und Logging. Der sichere Betrieb hängt aber wesentlich von der konkreten Serverumgebung ab.

Betreiber:innen sollten insbesondere prüfen:

- Serverhärtung,
- HTTPS-Konfiguration,
- Dateirechte,
- Datenbankrechte,
- Backup-Konzept,
- Update-Prozess,
- Protokollierung und Log-Aufbewahrung,
- Schutz vor unberechtigtem Zugriff.

## Hinweise zu Kommentaren und Notizen

Freitextfelder können besonders sensible Informationen enthalten. Lehrkräfte sollten Kommentare kurz, sachlich und fachbezogen formulieren.

Empfohlen:

- beobachtbare Leistung beschreiben,
- konkrete Unterrichtssituation nennen,
- keine Diagnosen oder persönlichen Zuschreibungen speichern,
- keine privaten Informationen erfassen,
- Verhalten und fachliche Leistung trennen.

## Keine Rechtsberatung

Dieser Hinweis ist eine technische und organisatorische Orientierung für das Projekt. Er ist keine Rechtsberatung und keine abschließende Datenschutzerklärung nach DSGVO. Vor dem produktiven Einsatz sollte eine schulinterne oder rechtliche Prüfung erfolgen.
