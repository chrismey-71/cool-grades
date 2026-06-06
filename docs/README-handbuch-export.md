# Export des Lehrer:innen-Handbuchs

Diese Dokumentation beschreibt, wie das Lehrer:innen-Handbuch aus der Markdown-Quelle neu als Word-Dokument erzeugt wird und wie die dafür verwendeten App-Screenshots reproduzierbar neu erstellt werden können.

## Überblick

- Markdown-Quelle: `docs/lehrerhandbuch.md`
- DOCX-Zieldatei: `docs/lehrerhandbuch.docx`
- Exportskript: `scripts/export-lehrerhandbuch.py`
- Screenshot-Ordner: `docs/screenshots/lehrerhandbuch/`
- Demo-Startskript: `scripts/start_lehrerhandbuch_demo.sh`
- Demo-Stopskript: `scripts/stop_lehrerhandbuch_demo.sh`
- Demo-Daten-Skript: `scripts/setup_lehrerhandbuch_demo.php`
- Screenshot-Skript: `scripts/capture_lehrerhandbuch_screenshots.js`

## Demo-Umgebung

Die Handbuch-Screenshots stammen aus einer lauffähigen lokalen Demo-Umgebung mit anonymisierten Beispieldaten.

Aktuelle Demo-Zugangsdaten:

- Lehrer:in: `lehrer.demo`
- Passwort: `DemoLehrer123!`
- Admin-Testkonto: `admin.demo`
- Passwort: `DemoAdmin123!`

Die lokale App wurde für die Handbuch-Erstellung unter `http://127.0.0.1:8044` betrieben. Die Demo-Datenbank läuft lokal auf MariaDB unter Port `3307`.

## Demo-Umgebung starten und stoppen

Komfortabel startet die komplette Demo-Umgebung mit:

```bash
bash scripts/start_lehrerhandbuch_demo.sh
```

Das Skript:

- initialisiert bei Bedarf die lokale MariaDB-Datenablage
- startet MariaDB auf Port `3307`
- erzeugt bei Bedarf die lokale `config.php`
- setzt die Demo-Datenbank zurück
- startet den lokalen PHP-Server auf `http://127.0.0.1:8044`

Zum Stoppen:

```bash
bash scripts/stop_lehrerhandbuch_demo.sh
```

## 1. Demo-Daten neu aufbauen

Im Projektverzeichnis:

```bash
php scripts/setup_lehrerhandbuch_demo.php
```

Das Skript setzt die Demo-Datenbank gezielt für das Handbuch zurück und befüllt sie mit:

- einer Demo-Klasse
- zwei Demo-Fächern
- acht anonymisierten Schüler:innen
- Kriterien, Picklisten und Presets
- Gruppen
- Mitarbeitseinträgen
- besonderen mündlichen Leistungsfeststellungen
- besonderen schriftlichen Leistungsfeststellungen
- Zeiträumen für die Auswertung

Wichtig:

- Das Skript ist absichtlich auf die lokale Demo-Datenbank begrenzt.
- Es ist nicht für Produktivdaten gedacht.

## 2. Screenshots neu erzeugen

Wenn die lokale App läuft, können die Screenshots mit Playwright neu erzeugt werden:

```bash
node scripts/capture_lehrerhandbuch_screenshots.js
```

Das Skript:

- setzt die Demo-Daten vor dem Lauf nochmals zurück
- meldet sich mit dem Demo-Lehrer:innenkonto an
- öffnet die wichtigsten Lehrer:innen-Bereiche der echten App
- erstellt reale Screenshots
- lädt ein echtes Auswertungs-PDF herunter
- erzeugt zusätzlich Vorschaubilder des PDF

Die Bilddateien landen in:

`docs/screenshots/lehrerhandbuch/`

## 3. Word-Handbuch neu erzeugen

Nach Änderungen an Markdown oder Screenshots:

```bash
python3 scripts/export-lehrerhandbuch.py
```

Optional mit abweichendem Stand:

```bash
python3 scripts/export-lehrerhandbuch.py --stand 2026-05-09
```

Optional mit abweichender Eingabe- oder Ausgabedatei:

```bash
python3 scripts/export-lehrerhandbuch.py \
  --input docs/lehrerhandbuch.md \
  --output docs/lehrerhandbuch.docx
```

## Was der Export erzeugt

Das Skript erstellt ein DOCX-Dokument mit:

- Titelblatt
- Dokumentinformationen
- Inhaltsverzeichnis bis Ebene 3
- Hauptteil aus der Markdown-Datei
- A4-Seitenformat
- Kopfzeile mit Dokumenttitel
- Fußzeile mit Seitenzahl
- deutschen Dokumentmetadaten
- eingebetteten Screenshots aus dem Screenshot-Ordner

## Inhaltsverzeichnis in Word

Das Inhaltsverzeichnis wird als echtes Word-Feld erzeugt und ist aktualisierbar.

Beim Öffnen in Word kann es nötig sein, das Verzeichnis einmal zu aktualisieren:

1. Rechtsklick auf das Inhaltsverzeichnis
2. `Felder aktualisieren`
3. `Gesamtes Verzeichnis aktualisieren`

Zusätzlich ist im Dokument die Option gesetzt, Felder beim Öffnen zu aktualisieren. Je nach Word-Version kann die manuelle Bestätigung trotzdem nötig sein.

## Technischer Hinweis

Für diesen Export wird ein projektinternes Python-Skript verwendet. `pandoc` war in der aktuellen Umgebung nicht verfügbar, deshalb erzeugt das Skript die DOCX-Struktur direkt als OpenXML-Paket und bettet Bilder ohne zusätzliche Office-Abhängigkeit ein.

## Empfohlene Prüfung nach dem Export

1. `docs/lehrerhandbuch.docx` existiert
2. Titelblatt vorhanden
3. Inhaltsverzeichnis vorhanden
4. Überschriften korrekt strukturiert
5. Screenshots sichtbar und lesbar
6. Seitenzahlen in der Fußzeile vorhanden
7. Dokument in Word oder LibreOffice öffnen
8. Falls nötig: Inhaltsverzeichnis aktualisieren

## Technische Schnellprüfung

Auf macOS kann zusätzlich geprüft werden, ob das Dokument grundsätzlich lesbar ist:

```bash
textutil -convert txt docs/lehrerhandbuch.docx -output /tmp/lehrerhandbuch.txt
textutil -convert html docs/lehrerhandbuch.docx -output /tmp/lehrerhandbuch.html
```

Wenn beide Befehle ohne Fehler durchlaufen, ist das DOCX in der Regel korrekt aufgebaut.

Für eine echte Layout-Prüfung wurde das DOCX zusätzlich über LibreOffice nach PDF gerendert und visuell geprüft. Der Render-Ordner liegt bei Bedarf unter:

`../output/lehrerhandbuch-render/`
