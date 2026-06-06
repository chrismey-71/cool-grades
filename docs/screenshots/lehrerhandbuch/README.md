# Screenshots für das Lehrer:innen-Handbuch

Dieser Ordner enthält die **echten Screenshots aus der laufenden App**, die im Lehrer:innen-Handbuch verwendet werden.

## Herkunft der Screenshots

Die Bilder stammen aus einer lokalen Demo-Umgebung mit anonymisierten Beispieldaten. Die Screenshots wurden automatisiert mit Playwright erzeugt, damit sie später reproduzierbar neu erstellt werden können.

Verwendete Hilfsskripte:

- Demo-Umgebung starten:
  `scripts/start_lehrerhandbuch_demo.sh`
- Demo-Umgebung stoppen:
  `scripts/stop_lehrerhandbuch_demo.sh`
- Demo-Daten aufbauen:
  `scripts/setup_lehrerhandbuch_demo.php`
- Screenshots erzeugen:
  `scripts/capture_lehrerhandbuch_screenshots.js`

## Enthaltene Dateien

- `01-dashboard.png`
- `02-kontoeinstellungen.png`
- `03-theme-einstellungen.png`
- `04-schnell-mitarbeit-leer.png`
- `05-schnell-mitarbeit-ausgefueellt.png`
- `06-mitarbeit-gespeichert.png`
- `07-besondere-muendliche-leistung.png`
- `08-besondere-schriftliche-leistung.png`
- `09-eintragsliste.png`
- `10-eintragsfilter.png`
- `11-eintrag-bearbeiten.png`
- `12-kriterienverwaltung.png`
- `13-picklistenverwaltung.png`
- `14-presets-verwalten.png`
- `15-preset-anwenden.png`
- `16-gruppenverwaltung.png`
- `17-fachstatus-auswertung.png`
- `18-auswertung-filter.png`
- `19-auswertung-tabelle.png`
- `20-auswertung-detail.png`
- `21-pdf-export-schaltflaeche.png`
- `22-beispiel-pdf-auswertung.png`
- `23-pdf-export-ergebnis.png`
- `24-abschlussbeurteilung-uebersicht.png`
- `25-abschlussbeurteilung-details.png`
- `26-abschlussbeurteilung-pdf.png`
- `example-report.pdf`
- `final-assessment-report.pdf`

## Qualitätsregeln

Für spätere Neugenerierungen gelten weiterhin diese Regeln:

- keine Echtdaten verwenden
- nach Möglichkeit nur Demo- oder anonymisierte Daten zeigen
- keine abgeschnittenen Masken aufnehmen
- ganze Arbeitsbereiche zeigen, nicht nur kleine Ausschnitte
- für PDF-Beispiele eine gut lesbare, repräsentative Seite wählen

## Neu erzeugen

1. lokale Demo-Umgebung starten:

```bash
bash scripts/start_lehrerhandbuch_demo.sh
```

2. lokale Demo-Daten bei Bedarf zusätzlich zurücksetzen:

```bash
php scripts/setup_lehrerhandbuch_demo.php
```

3. Screenshots neu erzeugen:

```bash
node scripts/capture_lehrerhandbuch_screenshots.js
```

Die Dateien in diesem Ordner werden dabei überschrieben bzw. neu erzeugt.
