# Admin-Dokumentation: Schuljahreswechsel

## Grundprinzip

Der Schuljahreswechsel ist historisch ausgelegt. Bestehende Klassen werden nicht umbenannt und Leistungsdaten werden nicht verschoben. Für das neue Schuljahr entsteht eine neue Klasseninstanz. Schüler:innen bleiben als Personen erhalten und werden über Klassenzuordnungen dem jeweiligen Schuljahr zugeordnet.

Ziel ist nicht, eine Klasse „weiter umzubenennen“, sondern den tatsächlichen schulischen Verlauf abzubilden: Die `2FSB` des alten Schuljahres bleibt als historische Klasse erhalten, die `3FSB` des neuen Schuljahres wird neu erzeugt und mit den übernommenen Schüler:innen verbunden. Dadurch können Lehrer:innen auch später noch Berichte, Jahresnotenlisten und alte Leistungsdaten aus dem Vorjahr aufrufen.

## Empfohlene Admin-Reihenfolge

Für einen sauberen Schuljahreswechsel empfiehlt sich diese Reihenfolge:

1. Schulen und Schulformen prüfen.
2. Neues Schuljahr mit Semesterzeiträumen anlegen.
3. Neues Schuljahr erst dann als aktuell setzen, wenn die Arbeit im neuen Jahr beginnen soll.
4. Neue Einstiegsklassen manuell anlegen, zum Beispiel neue erste Klassen.
5. Bestehende Klassen ausschließlich über den Schuljahreswechsel-Assistenten fortführen.
6. Abschlussklassen über `Abschlussklasse ohne Zielklasse` abschließen.
7. Schüler:innen-Sonderfälle prüfen: Wiederholung, Abgang, Klassenwechsel oder noch nicht zuordnen.
8. Lehrer:innen-/Fachzuweisungen übernehmen oder anschließend gezielt neu setzen.
9. Vor der Durchführung die Vorschau prüfen.
10. Nach dem Wechsel mit einem Lehrer:innen-Testkonto prüfen, ob aktuelle und archivierte Schuljahre korrekt sichtbar sind.

Diese Reihenfolge verhindert, dass Klassen versehentlich doppelt angelegt oder alte Leistungsdaten durch manuelle Umbenennungen unübersichtlich werden.

## Schuljahre verwalten

Schuljahre werden im Adminbereich unter `Stammdaten -> Schuljahre/Semester` angelegt. Ein Schuljahr enthält die Datumsbereiche für das 1. Semester, das 2. Semester und das gesamte Schuljahr. Es kann entweder global für alle Schulen gelten oder einer konkreten Schule zugeordnet werden. Das ist sinnvoll, wenn Schulen unterschiedliche Semestertermine führen. Pro Schule bzw. im globalen Bereich kann ein Schuljahr als aktuell markiert werden.

Ein bereits global angelegtes Schuljahr kann nachträglich einer konkreten Schule zugeordnet werden. Die vorhandene Schuljahres-ID bleibt dabei erhalten; Klassen, Leistungsdaten, Auswertungen und Abschlussbeurteilungen werden nicht kopiert und nicht verschoben. Die Zuordnung wird aus Sicherheitsgründen blockiert, wenn das globale Schuljahr bereits Klassen aus einer anderen Schule enthält.

Bei mehreren Schulen sollte ein neues Schuljahr in der Regel direkt einer konkreten Schule zugeordnet werden. Die Option `global / alle Schulen` ist ein Sonderfall für Installationen mit nur einer Schule oder für Schulen, die bewusst exakt dieselben Semestertermine gemeinsam verwenden.

Das aktuelle Schuljahr steuert, welche Klassen Lehrer:innen standardmäßig sehen. Frühere Schuljahre bleiben für Auswertungen und PDF-Berichte erreichbar. Globale Schuljahre stehen allen Schulen zur Verfügung; schulbezogene Schuljahre nur der jeweils gewählten Schule.

## Klassen pro Schuljahr

Eine Klasse ist immer eine Klasse eines konkreten Schuljahres. Eine `2FSB` im Schuljahr `2025/26` ist daher eine andere Klasseninstanz als eine `3FSB` im Schuljahr `2026/27`.

Alte Klassen können als `archiviert` markiert werden. Archivierte Klassen bleiben für Berichte sichtbar, sind aber für neue Erfassungen gesperrt. Abschlussklassen können zusätzlich als `ausgeschieden` markiert werden. Auch ausgeschiedene Klassen bleiben für berechtigte Lehrer:innen in Vorjahres-Auswertungen, Eintragslisten, Notenübersichten und PDF-Berichten sichtbar.

Neue Klassen werden manuell nur dann angelegt, wenn es keine Vorgängerklasse gibt, zum Beispiel bei neuen Einstiegsklassen. Gibt es eine Vorjahresklasse, wird die neue Klasseninstanz über den Schuljahreswechsel-Assistenten erzeugt.

Praxisregel:

- Neue erste Klassen werden unter `Klassen` angelegt.
- Aufsteigende Klassen werden über `Schuljahreswechsel` erzeugt.
- Abschlussklassen werden nicht künstlich fortgeführt, sondern mit `Abschlussklasse ohne Zielklasse` historisch abgeschlossen.

## Schuljahreswechsel durchführen

Der Assistent `Adminbereich -> Schuljahreswechsel` kennt zwei Modi:

- `Klasse fortführen`: für normale Fortführungen wie `1FSB -> 2FSB`.
- `Abschlussklasse ohne Zielklasse`: für Klassen, die nicht mehr in eine nächste Klasse übergehen.

Bei einer normalen Fortführung führt der Assistent durch folgende Schritte:

1. Schule wählen.
2. Ausgangsschuljahr wählen.
3. Ausgangsklasse wählen.
4. Zielschuljahr wählen.
5. Zielklasse definieren.
6. Beurteilungssystem übernehmen oder anpassen.
7. Schüler:innen übernehmen, als Wiederholer:in markieren, als Abgang markieren, in eine andere Klasse wechseln oder zunächst nicht zuordnen.
8. Lehrer:innen-/Fachzuordnungen optional übernehmen.
9. Status der Ausgangsklasse festlegen: aktiv lassen, archivieren oder als ausgeschieden markieren.
10. Vorschau prüfen.
11. Schuljahreswechsel durchführen.

Bei `Abschlussklasse ohne Zielklasse` wird keine Zielklasse angelegt. Die Ausgangsklasse wird als `ausgeschieden` markiert. Schüler:innen werden standardmäßig als Abgang behandelt; Sonderfälle wie Wiederholung, Klassenwechsel oder noch offene Zuordnung können pro Schüler:in abweichend markiert werden.

## Was gespeichert wird

Beim Durchführen einer normalen Fortführung wird eine neue Zielklasse angelegt, falls sie noch nicht existiert. Für übernommene Schüler:innen wird eine neue Klassenzuordnung im Zielschuljahr angelegt. Die Schüler:innen selbst werden nicht dupliziert.

Beim Abschlussmodus wird keine Zielklasse angelegt. Die bisherige Klasse bleibt als historische Klasseninstanz erhalten und wird für neue Erfassungen gesperrt.

Bestehende Mitarbeitseinträge, besondere mündliche Leistungen, besondere schriftliche Leistungen und Abschlussbeurteilungen bleiben bei der ursprünglichen Klasse und beim ursprünglichen Schuljahr.

## Was nicht passiert

Der Assistent kopiert keine Leistungsdaten, verschiebt keine alten Einträge und benennt keine alte Klasse automatisch um. Dadurch bleiben Vorjahresberichte historisch korrekt.

## Klassenstatus korrigieren

Der Status einer Klasse wird im Bereich `Schuljahreswechsel` gepflegt. Dort kann eine Klasse bei Bedarf nachträglich auf `aktiv`, `archiviert` oder `ausgeschieden` gesetzt werden. Die Klassenverwaltung selbst dient nur der Stammdatenpflege einzelner Klasseninstanzen.

## Lehrer:innen-Zuweisungen beenden

Unter `Zuweisungen` werden Lehrkraft, Klasse und Fach gemeinsam verwaltet. Eine Zuweisung ohne gespeicherte Kontextdaten kann endgültig gelöscht werden.

Sobald die Lehrkraft zu dieser Klasse-Fach-Kombination bereits Stunden, Mitarbeitseinträge, besondere mündliche oder schriftliche Leistungsfeststellungen, Abschlussbeurteilungen, Gruppen oder Gewichtungen gespeichert hat, wird die Zuweisung stattdessen **beendet**. Die bisherige Lehrkraft kann diese historischen Daten weiterhin lesen, auswerten und als PDF ausgeben; neue oder geänderte Einträge sind über diese beendete Zuweisung nicht mehr möglich. Bei einer versehentlichen Umteilung kann der Admin die Zuweisung jederzeit reaktivieren.

## Löschen alter Klassen

Wenn eine alte Klasse gelöscht wird, greifen die vorhandenen Datenbankbeziehungen. Dadurch werden auch die daran hängenden archivierten Leistungsdaten gelöscht. Das Löschen alter Klassen sollte daher nur bewusst erfolgen.

## Datensicherung

Vor einem größeren Schuljahreswechsel sollte im Adminbereich unter `Einstellungen -> Datenbanksicherung` eine Gesamtsicherung erstellt werden. Die Admin-Sicherung kann als vollständiger SQL-Dump der Datenbank oder als schulbezogene ZIP-Sicherung im JSON-Format erzeugt werden. Die Schulsicherung enthält nur Klassen, Zuordnungen und Leistungsdaten der ausgewählten Schule.

Optional kann die ZIP-Datei mit einem Kennwort verschlüsselt werden. Das Kennwort muss außerhalb der App sicher aufbewahrt werden.

## Prüffälle

- Nach dem Wechsel bleibt die alte Klasse im alten Schuljahr auswertbar.
- Die neue Klasse enthält die übernommenen Schüler:innen.
- Eine Schüler:in existiert weiterhin einmalig und hat mehrere Klassenzuordnungen.
- Vorjahresdaten sind für berechtigte Lehrer:innen lesbar.
- Eine Abschlussklasse ohne Zielklasse erzeugt keine künstliche Zielklasse.
- Ausgeschiedene Klassen bleiben in Vorjahres-Auswertungen für berechtigte Lehrer:innen sichtbar.
- In archivierten Klassen können keine neuen Einträge angelegt werden.
