# Demoinstallation

COOL-Grades kann bei der Installation wahlweise als Produktivinstallation oder als Demoinstallation angelegt werden.

Die Demoinstallation ist ausschließlich für Vorführungen, Tests und öffentliche Demo-Umgebungen gedacht. Sie darf nicht für echte Schüler:innendaten verwendet werden.

## Installation

1. `install.php?token=...` lokal mit gültigem Installationstoken öffnen.
2. `Demoinstallation` auswählen.
3. Demoschuljahr im Format `2025/26` eintragen.
4. Installation starten.

Die Demo legt automatisch an:

- Schule: `Demoschule HLW`
- Klasse: `2HLW`
- Fächer: `BW` und `APM`
- Admin: `demoadmin`
- Lehrkraft: `demolehrer`
- Passwort: `DemoZugang47!`

Für `demolehrer` werden praxisnahe Konto-Voreinstellungen gesetzt: normale Mitarbeitserfassung, Dashboard-Schnellzugriffe als Buttons, Quick-Pick mit 7 Vorschlägen, kompakte Eingabefenster, kontrastreiche Hervorhebungen, helles Theme und eingeblendete Gesetzeshinweise.

Die Daten enthalten Mitarbeitseinträge, besondere mündliche Leistungsfeststellungen, besondere schriftliche Leistungsfeststellungen, final gespeicherte Schulnachrichten für das 1. Semester und Entwürfe für die Jahresbeurteilung. Die Jahresbeurteilung bleibt bewusst offen, damit der Abschluss am Ende des 2. Semesters getestet werden kann.

## Demo zurücksetzen

Eine aktive Demoinstallation kann im Adminbereich unter `Einstellungen` zurückgesetzt werden.

Für eine öffentliche Demo kann zusätzlich ein Cronjob eingerichtet werden:

```bash
0 3 * * * php /path/to/cool-grades/tools/reset_demo_installation.php
```

Optional kann ein anderes Demoschuljahr übergeben werden:

```bash
php /path/to/cool-grades/tools/reset_demo_installation.php --year=2026/27
```

Der Reset löscht Demo-Daten und erzeugt denselben Demo-Ausgangszustand neu. Er läuft nur, wenn die Installation als Demo markiert ist.

In der Demoinstallation werden die Musterdaten ausschließlich für die `Demoschule HLW` angelegt. Globale Schuljahres-Fallbacks aus der allgemeinen Erstinitialisierung werden beim Demo-Seed entfernt, damit Menüs und Auswertungen nicht versehentlich auf `global / alle Schulen` zeigen.

## Demo entfernen

Im Adminbereich unter `Einstellungen` kann eine aktive Demoinstallation gelöscht werden. Dabei muss zuerst ein neues echtes Administrationskonto angelegt werden. Danach werden Demo-Schule, Demo-Benutzer und Demodaten entfernt und die Installation wird auf Produktivbetrieb umgestellt.
