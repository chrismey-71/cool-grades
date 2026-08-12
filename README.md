# COOL-Grades

COOL-Grades ist eine Web-App für österreichische Lehrkräfte und Schulen zur LBV-orientierten Dokumentation von Mitarbeit, besonderen Leistungsfeststellungen und Abschlussbeurteilungen.

Die App unterstützt Lehrer:innen bei der laufenden Mitarbeitsbewertung nach österreichischer Leistungsbeurteilung, bei besonderen mündlichen und schriftlichen Leistungsfeststellungen, bei Auswertungen für Klassen und Fächer sowie bei PDF-Berichten für Schulnachricht, Semesterbeurteilung oder Jahresbeurteilung.

Die Software ersetzt keine pädagogische Entscheidung. Notenvorschläge und Auswertungen sind Entscheidungshilfen; die finale Beurteilung bleibt bei der Lehrkraft.

Projektseite: https://chrismey-71.github.io/cool-grades/

## Für wen ist COOL-Grades gedacht?

COOL-Grades richtet sich an österreichische Lehrer:innen, Schulen und schulische Administrator:innen, die Leistungsbeurteilung nachvollziehbar und datensparsam dokumentieren möchten.

Typische Einsatzbereiche:

- Mitarbeit im Unterricht nach LBV dokumentieren
- positive, neutrale und negative Mitarbeitseinträge nachvollziehbar erfassen
- besondere mündliche Leistungsfeststellungen festhalten
- besondere schriftliche Leistungsfeststellungen wie Schularbeit, Test oder Diktat dokumentieren
- Schulnachricht, Semesterbeurteilung und Jahresbeurteilung vorbereiten
- Notenvorschläge als pädagogische Entscheidungshilfe verwenden
- Auswertungen und PDF-Berichte für Klasse, Fach, Schuljahr oder Semester erzeugen
- Schuljahreswechsel mit Archivierung alter Klassen abbilden

Die App ist besonders für Schulen in Österreich gedacht, die Begriffe wie Leistungsbeurteilungsverordnung, LBV, Mitarbeit, Schulnachricht, Semesterbeurteilung, Jahreszeugnis, Schularbeitsfach, SOST, NOST oder Jahresmodell im schulischen Alltag verwenden.

## Funktionen

- Rollen für Administration und Lehrkräfte
- Klassen, Fächer, Schuljahre, Semester und Schulformen verwalten
- Schüler:innen klassenzugeordnet und schuljahresbezogen führen
- schnelle Mitarbeitserfassung mit Kriterien, Presets und Gruppen
- besondere mündliche und schriftliche Leistungsfeststellungen dokumentieren
- Abschlussbeurteilungen als Entwurf oder final speichern
- Auswertungen und PDF-Berichte für Klassen, Fächer und Zeiträume
- Schuljahreswechsel mit Archivierung alter Klassen

## Was COOL-Grades nicht macht

- COOL-Grades vergibt keine automatische Endnote.
- COOL-Grades ersetzt keine pädagogische Gesamtbeurteilung.
- COOL-Grades gibt keine rechtsverbindliche Interpretation der Leistungsbeurteilungsverordnung.
- COOL-Grades ist keine WebUntis-Erweiterung; ein möglicher iCal-Import ist als spätere Weiterentwicklung vorgemerkt.

Die App soll Lehrkräfte bei einer sachlichen, transparenten und nachvollziehbaren Dokumentation unterstützen. Die endgültige Beurteilung erfolgt weiterhin durch die Lehrkraft auf Grundlage der geltenden schulrechtlichen Rahmenbedingungen, des Lehrplans, des Unterrichtsverlaufs und der dokumentierten Leistungsfeststellungen.

## Suchbegriffe und fachlicher Kontext

COOL-Grades bewegt sich im Umfeld folgender Themen:

- österreichische Leistungsbeurteilung
- Leistungsbeurteilungsverordnung, LBV und LBVO
- Mitarbeitsbewertung und Mitarbeit im Unterricht
- besondere mündliche Leistungsfeststellungen
- besondere schriftliche Leistungsfeststellungen
- Schularbeit, Test, Diktat und schriftliche Wiederholung
- Schulnachricht, Semesterbeurteilung und Jahresbeurteilung
- Notenvorschlag und Abschlussbeurteilung
- Schulverwaltung, Klassenverwaltung, Fächer, Schuljahre und Semester
- Lehrer:innen-Workflow für Unterrichtsdokumentation und PDF-Auswertung

## Technische Voraussetzungen

- PHP 8.x
- MySQL oder MariaDB
- Webserver mit PHP-Unterstützung

## Installation

1. Dateien auf den Webserver kopieren, z. B. nach `/cool-grades`.
2. `config.example.php` nach `config.php` kopieren.
3. In `config.php` die Datenbankverbindung, ein langes `install_token` und einen Log-Pfad außerhalb des Webroots eintragen.
4. Datenbankschema aus `schema.sql` einspielen.
5. Bei bestehenden Installationen zusätzlich die Dateien aus `migrations/` in zeitlicher Reihenfolge ausführen.
6. `install.php?token=...` im Browser lokal bzw. serverseitig geschützt aufrufen.
7. Nach erfolgreicher Installation `install.php` vom Server entfernen.
8. Standard-Adminzugang nach der Installation sofort ändern.

## Sicherheit

Die Datei `config.php` enthält Zugangsdaten und darf nicht veröffentlicht werden. Logs, Runtime-Daten, Datenbank-Dumps und Uploads gehören ebenfalls nicht in ein öffentliches Git-Repository.

Dieses Repository enthält deshalb nur `config.example.php` als Vorlage.

## Dokumentation

Die Benutzer- und Administrationsdokumentation liegt im Ordner `docs/`.

Weitere Projekthinweise:

- `CHANGELOG.md` dokumentiert Releases und wesentliche Änderungen.
- `SECURITY.md` beschreibt den Umgang mit Sicherheitsmeldungen.
- `DATENSCHUTZ.md` enthält Hinweise zum datenschutzbewussten Betrieb.
- `docs/security-deployment.md` enthält Hinweise zu Sicherheitsheadern, Installation, Login-Schutz, Nginx und Logdateien.

## Roadmap

Folgende Weiterentwicklungen sind als fachliche Entwicklungsrichtung vorgesehen. Sie sind noch nicht Bestandteil der aktuellen Version und sollen schrittweise geprüft, geplant und umgesetzt werden:

- formative Lernrückmeldungen als eigener Workflow neben bewertungsrelevanter Mitarbeit
- klare Trennung zwischen reinen Lernhinweisen und Einträgen, die in Notenvorschläge einfließen
- Lernziele, Erfolgskriterien, beobachteter Lernstand und nächste Lernschritte pro Rückmeldung
- separate Ausweisung formativer Lernrückmeldungen in Webauswertung und PDF-Berichten
- optionale Selbst- und Peer-Feedback-Funktionen
- stärkere Unterstützung von Lernentwicklung, Feedbackkultur und pädagogischer Reflexion

Die App soll dabei weiterhin keine automatische Beurteilung festlegen. Auch künftige Funktionen sollen Lehrkräfte unterstützen, aber die pädagogische Entscheidung nicht ersetzen.

## Lizenz

Dieses Projekt steht unter der GNU Affero General Public License v3.0. Details stehen in der Datei `LICENSE`.

## CSV-Import

Der CSV-Import für Schüler:innen erwartet Semikolon-getrennte Daten:

```csv
Vorname;Nachname
Anna;Muster
Max;Beispiel
```
