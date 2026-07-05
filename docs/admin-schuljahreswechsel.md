# Admin-Dokumentation: Schuljahreswechsel

## Grundprinzip

Der Schuljahreswechsel ist historisch ausgelegt. Bestehende Klassen werden nicht umbenannt und Leistungsdaten werden nicht verschoben. Für das neue Schuljahr entsteht eine neue Klasseninstanz. Schüler:innen bleiben als Personen erhalten und werden über Klassenzuordnungen dem jeweiligen Schuljahr zugeordnet.

## Schuljahre verwalten

Schuljahre werden im Adminbereich unter `Stammdaten -> Schuljahre/Semester` angelegt. Ein Schuljahr enthält die Datumsbereiche für das 1. Semester, das 2. Semester und das gesamte Schuljahr. Genau ein Schuljahr kann als aktuelles Schuljahr markiert werden.

Das aktuelle Schuljahr steuert, welche Klassen Lehrer:innen standardmäßig sehen. Frühere Schuljahre bleiben für Auswertungen und PDF-Berichte erreichbar.

## Klassen pro Schuljahr

Eine Klasse ist immer eine Klasse eines konkreten Schuljahres. Eine `2FSB` im Schuljahr `2025/26` ist daher eine andere Klasseninstanz als eine `3FSB` im Schuljahr `2026/27`.

Alte Klassen können als `archiviert` markiert werden. Archivierte Klassen bleiben für Berichte sichtbar, sind aber für neue Erfassungen gesperrt. Abschlussklassen können zusätzlich als `ausgeschieden` markiert werden. Auch ausgeschiedene Klassen bleiben für berechtigte Lehrer:innen in Vorjahres-Auswertungen, Eintragslisten, Notenübersichten und PDF-Berichten sichtbar.

Neue Klassen werden manuell nur dann angelegt, wenn es keine Vorgängerklasse gibt, zum Beispiel bei neuen Einstiegsklassen. Gibt es eine Vorjahresklasse, wird die neue Klasseninstanz über den Schuljahreswechsel-Assistenten erzeugt.

## Schuljahreswechsel durchführen

Der Assistent `Adminbereich -> Schuljahreswechsel` kennt zwei Modi:

- `Klasse fortführen`: für normale Fortführungen wie `1FSB -> 2FSB`.
- `Abschlussklasse ohne Zielklasse`: für Klassen, die nicht mehr in eine nächste Klasse übergehen.

Bei einer normalen Fortführung führt der Assistent durch folgende Schritte:

1. Ausgangsschuljahr wählen.
2. Ausgangsklasse wählen.
3. Zielschuljahr wählen.
4. Zielklasse definieren.
5. Beurteilungssystem übernehmen oder anpassen.
6. Schüler:innen übernehmen, als Wiederholer:in markieren, als Abgang markieren, in eine andere Klasse wechseln oder zunächst nicht zuordnen.
7. Lehrer:innen-/Fachzuordnungen optional übernehmen.
8. Status der Ausgangsklasse festlegen: aktiv lassen, archivieren oder als ausgeschieden markieren.
9. Vorschau prüfen.
10. Schuljahreswechsel durchführen.

Bei `Abschlussklasse ohne Zielklasse` wird keine Zielklasse angelegt. Die Ausgangsklasse wird als `ausgeschieden` markiert. Schüler:innen werden standardmäßig als Abgang behandelt; Sonderfälle wie Wiederholung, Klassenwechsel oder noch offene Zuordnung können pro Schüler:in abweichend markiert werden.

## Was gespeichert wird

Beim Durchführen einer normalen Fortführung wird eine neue Zielklasse angelegt, falls sie noch nicht existiert. Für übernommene Schüler:innen wird eine neue Klassenzuordnung im Zielschuljahr angelegt. Die Schüler:innen selbst werden nicht dupliziert.

Beim Abschlussmodus wird keine Zielklasse angelegt. Die bisherige Klasse bleibt als historische Klasseninstanz erhalten und wird für neue Erfassungen gesperrt.

Bestehende Mitarbeitseinträge, besondere mündliche Leistungen, besondere schriftliche Leistungen und Abschlussbeurteilungen bleiben bei der ursprünglichen Klasse und beim ursprünglichen Schuljahr.

## Was nicht passiert

Der Assistent kopiert keine Leistungsdaten, verschiebt keine alten Einträge und benennt keine alte Klasse automatisch um. Dadurch bleiben Vorjahresberichte historisch korrekt.

## Klassenstatus korrigieren

Der Status einer Klasse wird im Bereich `Schuljahreswechsel` gepflegt. Dort kann eine Klasse bei Bedarf nachträglich auf `aktiv`, `archiviert` oder `ausgeschieden` gesetzt werden. Die Klassenverwaltung selbst dient nur der Stammdatenpflege einzelner Klasseninstanzen.

## Löschen alter Klassen

Wenn eine alte Klasse gelöscht wird, greifen die vorhandenen Datenbankbeziehungen. Dadurch werden auch die daran hängenden archivierten Leistungsdaten gelöscht. Das Löschen alter Klassen sollte daher nur bewusst erfolgen.

## Datensicherung

Vor einem größeren Schuljahreswechsel sollte im Adminbereich unter `Einstellungen -> Datenbanksicherung` eine Gesamtsicherung erstellt werden. Die Admin-Sicherung wird als ZIP-Datei erzeugt und enthält einen vollständigen SQL-Dump der Datenbank.

Optional kann die ZIP-Datei mit einem Kennwort verschlüsselt werden. Das Kennwort muss außerhalb der App sicher aufbewahrt werden.

## Prüffälle

- Nach dem Wechsel bleibt die alte Klasse im alten Schuljahr auswertbar.
- Die neue Klasse enthält die übernommenen Schüler:innen.
- Eine Schüler:in existiert weiterhin einmalig und hat mehrere Klassenzuordnungen.
- Vorjahresdaten sind für berechtigte Lehrer:innen lesbar.
- Eine Abschlussklasse ohne Zielklasse erzeugt keine künstliche Zielklasse.
- Ausgeschiedene Klassen bleiben in Vorjahres-Auswertungen für berechtigte Lehrer:innen sichtbar.
- In archivierten Klassen können keine neuen Einträge angelegt werden.
