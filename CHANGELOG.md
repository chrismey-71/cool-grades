# Changelog

Alle wesentlichen Änderungen an COOL-Grades werden in dieser Datei dokumentiert.

Das Format orientiert sich an "Keep a Changelog". Die Versionsnummern folgen der App-Version in `version.json`.

## [Unreleased]

### Geplant

- formative Lernrückmeldungen als eigener Workflow neben bewertungsrelevanter Mitarbeit
- Trennung zwischen reinen Lernhinweisen und Einträgen, die in Notenvorschläge einfließen
- Lernziele, Erfolgskriterien, beobachteter Lernstand und nächste Lernschritte pro Rückmeldung
- separate Darstellung formativer Lernrückmeldungen in Webauswertung und PDF-Berichten
- optionale Selbst- und Peer-Feedback-Funktionen
- stärkere Unterstützung von Lernentwicklung, Feedbackkultur und pädagogischer Reflexion
- weitere Fehlerkorrekturen, Sicherheits- und Dokumentationspflege nach Bedarf

## [1.78] - 2026-08-14

### Hinzugefügt

- Der Installer bietet nun eine klare Wahl zwischen Produktivinstallation und Demoinstallation.
- Die Demoinstallation erzeugt eine Demoschule HLW, die Demoklasse 2HLW, die Demofächer BW und APM, Demo-Benutzerkonten und umfangreiche anonymisierte Beispieldaten für ein ganzes Schuljahr.
- Demodaten enthalten laufende Mitarbeit, besondere mündliche Leistungsfeststellungen, besondere schriftliche Leistungsfeststellungen, Orientierungsgewichtungen sowie Entwürfe für Schulnachricht und Jahresbeurteilung.
- Das 1. Semester der Demodaten wird nun als final gespeicherte Schulnachricht angelegt; die Jahresbeurteilung bleibt als Entwurf zum Testen offen.
- Das Demo-Lehrerkonto `demolehrer` erhält nun feste Konto-Voreinstellungen für normale Mitarbeitserfassung, Button-Schnellzugriff, Quick-Pick mit 7 Vorschlägen, kompakte kontrastreiche Eingaben, helles Theme und eingeblendete Gesetzeshinweise.
- Im Adminbereich wird eine aktive Demoinstallation sichtbar angezeigt und kann manuell zurückgesetzt werden.
- Demoinstallationen können im Adminbereich gelöscht werden, nachdem ein neues echtes Administrationskonto angelegt wurde.
- Für öffentliche Testseiten steht ein CLI-Reset-Skript zur Verfügung, das per Cron z. B. täglich einen frischen Demo-Datenstand erzeugen kann.
- Eine kurze Dokumentation beschreibt Installation, Reset und Entfernung der Demodaten.
- Eine temporäre `install_check.php` prüft PHP-Version, Extensions, Log-Schreibrechte, Lockfile-Schreibrechte und Datenbankverbindung mit gültigem Install-Token.

### Geändert

- Angemeldete Benutzer:innen sehen in einer Demoinstallation einen dezenten Hinweis, dass keine echten Schüler:innendaten erfasst werden sollen.
- Der Installer kann nun auf Webhosting-Umgebungen mit gültigem Einmal-Token ausgeführt werden; eine zusätzliche Localhost-Sperre ist optional über `install_local_only` aktivierbar.
- Installationsfehler bei Datenbank, Schema oder Lockfile werden nun explizit protokolliert und mit einem verständlicheren Hinweis angezeigt.
- `install.lock` wird nicht mehr stillschweigend ignoriert, sondern bei fehlenden Schreibrechten als Installationsfehler gemeldet.
- Bei zu alter PHP-Version erscheint nun früh ein klarer Hinweis, bevor weitere App-Dateien geladen werden.

### Korrigiert

- Qualitative Bereiche im Notenvorschlag der Abschlussbeurteilung werden nun feiner aus dem Eindrucksdurchschnitt abgeleitet, statt vor der Gewichtung pauschal auf ganze Noten wie `2` verdichtet zu werden.
- Durchgehend positive Mitarbeitseinträge mit ausreichender Datenbasis werden im Mitarbeitsnotenvorschlag nun angemessener als sehr positive Tendenz berücksichtigt.
- Das Demo-Standardpasswort erfüllt nun die aktuelle Passwortrichtlinie der App und blockiert die Demoinstallation nicht mehr.
- Der Demo-Reset ist bei vorhandenen Demo-Konten idempotent und aktualisiert `demoadmin`/`demolehrer`, statt an doppelten Benutzernamen abzubrechen.
- Der Demo-Reset repariert nun auch teilweise angelegte Demo-Stammdaten wie Schule, Schulform, Schuljahr, Klasse und Fächer, statt an Unique-Key-Konflikten abzubrechen.
- Demo-Schuljahre werden nun ausschließlich schulbezogen für die `Demoschule HLW` geführt; globale Schuljahres-Fallbacks werden beim Demo-Seed entfernt, damit die Klasse `2HLW` in Lehrer:innen-Menüs stabil erscheint.
- Lehrer:innen-Menüs und Auswertungsseiten verwenden den gewählten Schulkontext nun auch bei der Schuljahr- und Fächerauswahl.
- Dokumentation und Admin-Hinweis zur Demoinstallation zeigen das Demo-Passwort `DemoZugang47!`.
- Versionsnummer auf 1.78 erhöht.

## [1.77] - 2026-08-12

### Geändert

- Die Abschlussbeurteilung berücksichtigt bei der automatischen Zeitraum-Vorauswahl nun das im Adminbereich als aktuell markierte Schuljahr.
- Wenn ein neues Schuljahr bereits während der Ferien als aktuell gesetzt ist, wird für dieses Schuljahr das 1. Semester bzw. im Jahresmodell die Schulnachricht vorausgewählt.
- Die Konto-Seite trennt persönliche Einstellungen und Passwortänderung klarer; die Speichern-Tasten sind eindeutig beschriftet und die Einstellungen praxisnäher gruppiert.
- Smoke-Tests sichern den Ferienfall zwischen altem Schuljahrende und neuem Schuljahrbeginn ab.
- Versionsnummer auf 1.77 erhöht.

## [1.76] - 2026-08-11

### Geändert

- Hinweise zu kontoabhängigen Darstellungsoptionen verlinken nun direkt auf die passende Kontoeinstellung.
- Die Mitarbeitserfassung zeigt auch bei ausgeschalteter vereinfachter Eingabe einen direkten Hinweis zur Aktivierung im Konto.
- Das Feld `Kurze Beobachtung / Anlass` ist nun als Accordion dargestellt und beim Öffnen der vereinfachten Eingabe geschlossen.
- Wird ein Stundenkontext ausgewählt oder neu angelegt, übernimmt die Mitarbeitserfassung das Datum aus diesem Kontext und sperrt das spätere Datumsfeld gegen manuelle Änderung.
- Versionsnummer auf 1.76 erhöht.

## [1.74] - 2026-08-10

### Hinzugefügt

- Personenverwaltung kann nun getrennte Konten für Lehrkräfte und Administrator:innen anlegen.
- Administrator:innen können einer oder mehreren Schulen zugeordnet werden und erscheinen mit ihrer Rolle in der Personenübersicht.
- Picklisten für `Eindruck/Relevanz` enthalten nun eine explizit pflegbare Wertungsrichtung: positiv, neutral, negativ oder ohne Wertung.
- Neue Mitarbeitseinträge und besondere mündliche Leistungen speichern diese Wertungsrichtung als Momentaufnahme mit dem Eintrag.
- Die Gewichtung für Notenvorschläge wurde zur unverbindlichen `Orientierungsgewichtung` erweitert, inklusive fachtypabhängiger Presets für Schularbeitsfächer und Nicht-Schularbeitsfächer.
- Besondere mündliche und schriftliche Leistungsfeststellungen können optional mit einem Einzelgewicht von `0,5 x` bis `3 x` versehen werden.
- Der schriftliche Bereich unterstützt nun `Diktat` als eigenen Typ zusätzlich zu Schularbeit, Test und den bisherigen schriftlichen Arten.
- Idempotente Migration ergänzt die Wertungsrichtung bei bestehenden Picklisten und historischen Einträgen; konfliktträchtige Fälle wie `kaum nachweisbar` werden als negativ eingeordnet.
- Die Löschung einer noch unbenutzten Stunde aus der vereinfachten Mitarbeitserfassung wurde repariert und führt mit erhaltenem Klasse-Fach-Kontext zurück zur Erfassung.
- Lehrkräfte mit mehreren Schulzuordnungen können im Dashboard ihre Arbeitsschule wählen; Klassen, Fächer und Schnellzugriffe werden darauf beschränkt.
- Die Schulbezeichnung im Kopfbereich übernimmt nun die im Dashboard gewählte Arbeitsschule und bleibt als geprüfter Sitzungs-Kontext auf weiteren Lehrer:innenseiten sichtbar.
- Die Arbeitsbereich-Schule bleibt bei Navigation in andere Lehrer:innenmenüs und beim anschließenden Rücksprung zum Dashboard erhalten.
- Schulgebundene Administrator:innen werden nun konsequent auf ihre zugeordneten Schulen begrenzt; Administrationskonten ohne Schulzuordnung gelten als Superadministrator:innen.
- Administrator:innen können neue Schulen anlegen; schulgebundene Admins werden neu angelegten Schulen automatisch zugeordnet.
- Zusätzlicher Prüffall sichert die Schultrennung für Schulen, Klassen, Schüler:innen, Fächer, Kriterienvorschläge, Schuljahre, Zuweisungen, Sicherungen und Ereignisse ab.

### Geändert

- Lehrkraft- und Administrationsrolle bleiben pro Konto fest getrennt; für beide Aufgaben derselben Person sind bewusst zwei Konten mit unterschiedlichen Usernames erforderlich.
- Administrator:innen werden nicht als Lehrkräfte in Klassen-Fach-Zuweisungen angeboten und erhalten dadurch keine Erfassungsrechte.
- Das letzte aktive Administrationskonto und das eigene Administrationskonto sind vor dem Löschen geschützt.
- Der Menüpunkt heißt nun `Personen & Rollen`.
- Die pädagogische Einordnung nutzt die gespeicherte Wertungsrichtung zentral in Erfassung, Berichten und Notenvorschlägen; die Textbezeichnung dient nur noch als Rückfall für Altdaten.
- Negative Eindruckswerte bleiben bei formativ vorgeschlagenem Anlass als sichtbarer Konflikthinweis erhalten; sie werden nicht stillschweigend zu einer summativen Bewertung umgedeutet.
- Löschversuche für Stunden werden nun vollständig protokolliert; ein optional fehlgeschlagenes Ereignisprotokoll meldet nicht mehr fälschlich einen fehlgeschlagenen Löschvorgang.
- Die Gewichtung für Notenvorschläge verwendet nun dieselbe ruhige Kartenoptik wie die übrigen Bereiche der Lehrkräfteverwaltung.
- Bestehende individuelle Gewichtungen werden nicht automatisch durch neue Orientierungs-Presets ersetzt; Presets wirken nur nach bewusster Auswahl.
- Schulbezogene Adminbereiche filtern und validieren Schule, Schulform, Klasse, Fach, Schuljahr und Benutzerkonto nun serverseitig gegen die Schulzuordnung des angemeldeten Administrationskontos.
- Schulübergreifende Fach- und Benutzerzuordnungen werden bei Bearbeitung durch schulgebundene Admins nicht versehentlich entfernt.
- Versionsnummer auf 1.74 erhöht.

## [1.73] - 2026-08-10

### Geändert

- Schulauswahlen im Adminbereich sind farblich und inhaltlich klarer gegliedert: Die aktuell gewählte Schule bleibt direkt am Auswahlfeld sichtbar.
- Mehrfachauswahlen für Schulen und Schulformen verwenden nun moderne, farblich getrennte Auswahlkacheln mit eindeutiger Zuordnung.
- Schulbezogene Auswahlfelder bei Klassen, Zuweisungen, Schuljahren, Schuljahreswechsel, Sicherungen, Ereignissen, Fächern und Kriterien-Vorschlägen folgen einem einheitlichen Design.
- Versionsnummer auf 1.73 erhöht.

## [1.72] - 2026-08-09

### Hinzugefügt

- Zuweisungen von Lehrkraft, Klasse und Fach können im Adminbereich nun sicher beendet und bei Bedarf reaktiviert werden
- beendete Zuweisungen werden als historische Zuweisung mit Lesemodus geführt, damit die bisherige Lehrkraft Einträge, Auswertungen und PDFs weiter einsehen kann
- kompakte Übersicht vorhandener Dokumentationen je Zuweisung, einschließlich Mitarbeit, Stunden, besonderer mündlicher und schriftlicher Leistungen sowie Abschlussbeurteilungen
- automatisierter Prüffall für die datensichere Behandlung von Lehrer:innen-Zuweisungen
- Lehrkräfte können einer oder mehreren Schulen zugeordnet werden
- Fächer können gezielt einer oder mehreren Schulformen zugeordnet werden
- konkrete Schulformzuordnung für Kriterien-Vorschläge; Vorschläge können auch bewusst für alle Schulformen gelten
- schulbezogene Schuljahre und Semestertermine zusätzlich zu bestehenden globalen Schuljahren
- Schulfilter im Schuljahreswechsel-Assistenten, damit Klassenwechsel innerhalb einer Schule erfolgen
- schulbezogene ZIP-Sicherung mit den Klassen-, Zuordnungs- und Leistungsdaten einer ausgewählten Schule
- Schulfilter in der Ereignisauswertung; neue Ereignisse werden anhand ihrer Klasse automatisch einer Schule zugeordnet

### Geändert

- Zuweisungen ohne gespeicherte Kontextdaten können endgültig gelöscht werden
- Zuweisungen mit vorhandenen Dokumentationen werden nicht mehr gelöscht, sondern beendet; Leistungsdaten werden weder verschoben noch gelöscht
- neue oder nachträgliche Eingaben sind bei beendeten Zuweisungen serverseitig gesperrt, historische Listen und Berichte bleiben zugänglich
- aktive Arbeitsbereiche zeigen nur aktive Zuweisungen als neue Erfassungskombinationen
- neue oder reaktivierte Zuweisungen berücksichtigen die Schulzuordnung der Lehrkraft sowie die Schulform der Klasse und des Fachs; die Prüfung erfolgt auch serverseitig
- die Zuordnungsmaske zeigt nach Auswahl einer Lehrkraft nur deren Klassen und die dazu passenden Fächer an
- Klassen mit derselben Bezeichnung können in unterschiedlichen Schulformen eines Schuljahres geführt werden
- bestehende globale Schuljahre und Kriterien-Vorschläge bleiben erhalten und weiterhin nutzbar
- Kriterien-Vorschläge werden für Lehrkräfte nach den Schulformen ihrer zugewiesenen Klassen gefiltert
- Versionsnummer auf 1.72 erhöht

## [1.71] - 2026-07-05

### Geändert

- bisheriger Lehrerbereich in `Dashboard` umbenannt und als eindeutigen Startpunkt für Lehrkräfte geführt
- separate Dashboard-Auswahl zwischen Lehrerbereich und Auswertung entfernt
- allgemeine Auswertung als Kachel `Berichte & Auswertungen` in das Lehrer:innen-Dashboard integriert
- Hauptnavigation für Lehrkräfte vereinfacht: `Dashboard`, `Verwaltung`, `Konto`
- Schuljahreswechsel um den Modus `Abschlussklasse ohne Zielklasse` ergänzt
- Admin-Dashboard im Bereich `Stammdaten` um eine aufklappbare empfohlene Reihenfolge für den Schuljahreswechsel erweitert
- Klassenanlage deutlicher als Funktion für neue Einstiegsklassen ohne Vorgängerklasse beschrieben
- archivierte und ausgeschiedene Vorjahresklassen bleiben für berechtigte Lehrkräfte in lesenden Auswertungs- und Übersichtsbereichen sichtbar
- störende Verlassen-Warnung im Schuljahreswechsel-Assistenten entfernt
- Admin-Gesamtsicherung als gepackte ZIP-Sicherung mit optionalem Kennwortschutz umgesetzt
- Lehrkraft-Datensicherung für zugewiesene Klassen, Fächer, Schuljahre und Leistungsdaten als gepackten Export mit optionalem Kennwortschutz ergänzt
- Klassenübersicht zeigt bei Abschluss- und Archivklassen aktive und historische Schüler:innen getrennt, z. B. `0 aktiv / 24 historisch`
- Lehrer:innen-Handbuch an die neue Dashboard-Struktur angepasst
- Versionsnummer auf 1.71 erhöht

## [1.70] - 2026-06-20

### Geändert

- Gewichtung für Notenvorschläge in der Lehrer:innen-Verwaltung als standardmäßig geschlossenes Accordion dargestellt
- Statuskennzahlen der Notenübersicht als direkt anklickbare Filter umgesetzt
- Schüler:innennamen in der Notenübersicht direkt mit der passenden Einzelbeurteilung verknüpft
- doppelte ausgeschriebene Darstellung gespeicherter Noten entfernt; die Zahl im Notenkreis bleibt maßgeblich
- Notenübersicht verwendet nun den aktuellen gewichteten Gesamtnotenvorschlag statt eines veralteten oder missverständlichen Mitarbeit-Vorschlags
- Begriffe appweit in `Mitarbeitsnotenvorschlag` und gewichteten `Notenvorschlag` getrennt
- PDF-Notenübersicht priorisiert Status, Aktualisierung und gespeicherte Note vor dem abgeschwächten Notenvorschlag
- Beurteilungen und Vorschläge in der Einzelansicht einheitlich rechts oben positioniert
- Entscheidungsbereich der Abschlussbeurteilung auf eine kompakte Gegenüberstellung der drei Leistungsbereiche reduziert; Rechendetails sind aufklappbar
- Versionsnummer auf 1.70 erhöht

## [1.69] - 2026-06-19

### Hinzugefügt

- lehrkraftbezogene Gewichtung für Notenvorschläge je Schuljahr, Klasse und Fach
- pädagogische Standardgewichtung von 60 % Mitarbeit, 20 % besondere mündliche und 20 % besondere schriftliche Leistungsfeststellungen
- zusätzliche 40/60-Gewichtung von Schulnachricht und restlichem Schuljahr ausschließlich im Jahresmodell
- transparente Anzeige von Bereichswerten, wirksamen Gewichten, herausgerechneten Bereichen und Warnhinweisen
- rechtlich vorsichtiger Hinweisblock zur Gleichwertigkeit der Leistungsformen und zur alleinigen Verantwortung der Lehrkraft
- idempotente Datenbankmigration und automatisierte Prüffälle für die Gewichtungslogik

### Geändert

- zentrale Notenvorschlagslogik berücksichtigt qualitative Werte bei Mitarbeit und besonderen mündlichen Leistungen sowie Noten bei besonderen schriftlichen Leistungen
- fehlende Leistungsbereiche werden nicht als 0 gewertet; vorhandene Gewichte werden proportional normalisiert
- ausschließlich schriftliche Leistungen erzeugen nur einen nicht ausreichend abgesicherten Zwischenwert
- SOST und NOST bleiben semesterbezogen und erzeugen keinen regulären Jahresvorschlag aus beiden Semestern
- PDF-Berichte und gespeicherte Vorschlags-Snapshots dokumentieren die verwendete Berechnungsgrundlage
- Versionsnummer auf 1.69 erhöht

## [1.68] - 2026-06-19

### Hinzugefügt

- lehrkraftbezogene Notenübersicht über alle zugeordneten Klassen und Fächer eines Schuljahres
- Filter nach Schuljahr, Beurteilungszeitraum, Klasse, Fach und Bearbeitungsstand
- Gesamtfortschritt für finale Beurteilungen, Entwürfe und noch offene Beurteilungen
- direkter Wechsel von der Übersicht zur Abschlussbeurteilung einer einzelnen Schüler:in
- PDF-Gesamtbericht im A4-Querformat, gruppiert nach Klasse und Fach

### Geändert

- Jahresmodell und semestrierte Modelle verwenden in der Gesamtübersicht jeweils den fachlich passenden aktuellen Beurteilungszeitraum
- Lehrer:innen-Handbuch um die neue Notenübersicht und ihre Filterwirkung ergänzt
- Versionsnummer auf 1.68 erhöht

## [1.67] - 2026-06-17

### Hinzugefügt

- GitHub-Issue-Templates für Fehlermeldungen, Funktionswünsche und Dokumentationshinweise
- GitHub-Discussion-Templates für allgemeine Nachrichten, Ideen und Fragen
- Issue-Konfiguration mit Hinweis auf Discussions für allgemeine Rückmeldungen und auf die Security Policy für Sicherheitsmeldungen

### Geändert

- Versionsnummer auf 1.67 erhöht

## [1.66] - 2026-06-17

### Hinzugefügt

- In der Mitarbeitserfassung werden Schüler:innen, die in der ausgewählten Stunde bereits bewertet wurden, blasser dargestellt
- Direkt am Namen erscheint ein kompakter Zähler der vorhandenen Einträge, z. B. `(1)` oder `(2)`
- Ein kurzer Hinweis erklärt die blasse Darstellung nur dann, wenn bereits Bewertungen in der Stunde vorhanden sind

### Geändert

- Die Ermittlung vorhandener Stundeneinträge erfolgt gesammelt über eine gruppierte Datenbankabfrage statt je Schüler:in einzeln
- Versionsnummer auf 1.66 erhöht

## [1.65] - 2026-06-16

### Geändert

- Beurteilungslogik für das Jahresmodell fachlich korrigiert
- Im Jahresmodell werden nur noch „Schulnachricht festlegen“ und „Jahresbeurteilung festlegen“ angeboten
- Die Option „2. Semesterbeurteilung festlegen“ wird im Jahresmodell nicht mehr angezeigt
- SOST- und NOST-Semesterlogik bleiben unverändert
- PDF-Ausgabe der Abschlussbeurteilung verwendet im Jahresmodell „Schulnachricht“ statt „1. Semester“
- Versionsnummer auf 1.65 erhöht

### Migration

- Bestehende „2. Semesterbeurteilungen“ aus Jahresmodell-Klassen werden einmalig nach „Jahresbeurteilung“ kopiert, wenn dort noch kein Jahresdatensatz existiert
- Bereits vorhandene Jahresbeurteilungen werden nicht überschrieben
- Konfliktfälle mit vorhandener 2.-Semester- und Jahresbeurteilung werden als Event `migration_yearly_semester2_to_year_conflict` protokolliert

## [1.64] - 2026-06-10

### Hinzugefügt

- zentrale Sicherheitsheader für gerenderte App-Seiten
- datenbankgestützte Login-Ratenbegrenzung mit konfigurierbarer Fehlversuchsanzahl, Verzögerung und temporärer Sperre
- konfigurierbare Passwortregeln im Adminbereich
- Einmal-Token und Lock-Datei für `install.php`
- konfigurierbarer Log-Pfad außerhalb des Webroots
- Betriebsdokumentation zu Sicherheitsheadern, Installation, Nginx und Logdateien

### Geändert

- Passwortprüfung für Kontoänderung, Lehrkräfteanlage und Passwort-Reset vereinheitlicht
- `.DS_Store`, AppleDouble-Dateien und `install.lock` explizit vom Git-Tracking ausgeschlossen
- Versionsnummer auf 1.64 erhöht

## [1.63] - 2026-06-08

### Hinzugefügt

- Admin-Pflegebereich für Impressum und Datenschutzbestimmung mit einfachem HTML-Editor
- öffentliche Seiten für Impressum und Datenschutz über die Fußzeile
- Migration für längere HTML-Inhalte in globalen App-Einstellungen

### Geändert

- Versionsnummer auf 1.63 erhöht

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

[Unreleased]: https://github.com/chrismey-71/cool-grades/compare/v1.67...HEAD
[1.67]: https://github.com/chrismey-71/cool-grades/compare/v1.66...v1.67
[1.66]: https://github.com/chrismey-71/cool-grades/compare/v1.65...v1.66
[1.65]: https://github.com/chrismey-71/cool-grades/compare/v1.64...v1.65
[1.64]: https://github.com/chrismey-71/cool-grades/compare/v1.63...v1.64
[1.63]: https://github.com/chrismey-71/cool-grades/compare/v1.62...v1.63
[1.62]: https://github.com/chrismey-71/cool-grades/releases/tag/v1.62
