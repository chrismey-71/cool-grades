# Admin-Dokumentation: Schuljahreswechsel

## Grundprinzip

Der Schuljahreswechsel ist historisch ausgelegt. Bestehende Klassen werden nicht umbenannt und Leistungsdaten werden nicht verschoben. Für das neue Schuljahr entsteht eine neue Klasseninstanz. Schüler:innen bleiben als Personen erhalten und werden über Klassenzuordnungen dem jeweiligen Schuljahr zugeordnet.

## Schuljahre verwalten

Schuljahre werden im Adminbereich unter `Stammdaten -> Schuljahre/Semester` angelegt. Ein Schuljahr enthält die Datumsbereiche für das 1. Semester, das 2. Semester und das gesamte Schuljahr. Genau ein Schuljahr kann als aktuelles Schuljahr markiert werden.

Das aktuelle Schuljahr steuert, welche Klassen Lehrer:innen standardmäßig sehen. Frühere Schuljahre bleiben für Auswertungen und PDF-Berichte erreichbar.

## Klassen pro Schuljahr

Eine Klasse ist immer eine Klasse eines konkreten Schuljahres. Eine `2FSB` im Schuljahr `2025/26` ist daher eine andere Klasseninstanz als eine `3FSB` im Schuljahr `2026/27`.

Alte Klassen können als `archiviert` markiert werden. Archivierte Klassen bleiben für Berichte sichtbar, sind aber für neue Erfassungen gesperrt. Abschlussklassen können zusätzlich als `ausgeschieden` markiert werden; sie erscheinen Lehrer:innen standardmäßig nicht mehr.

## Schuljahreswechsel durchführen

Der Assistent `Adminbereich -> Schuljahreswechsel` führt durch folgende Schritte:

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

## Was gespeichert wird

Beim Durchführen wird eine neue Zielklasse angelegt, falls sie noch nicht existiert. Für übernommene Schüler:innen wird eine neue Klassenzuordnung im Zielschuljahr angelegt. Die Schüler:innen selbst werden nicht dupliziert.

Bestehende Mitarbeitseinträge, besondere mündliche Leistungen, besondere schriftliche Leistungen und Abschlussbeurteilungen bleiben bei der ursprünglichen Klasse und beim ursprünglichen Schuljahr.

## Was nicht passiert

Der Assistent kopiert keine Leistungsdaten, verschiebt keine alten Einträge und benennt keine alte Klasse automatisch um. Dadurch bleiben Vorjahresberichte historisch korrekt.

## Klassenstatus korrigieren

Der Status einer Klasse wird im Bereich `Schuljahreswechsel` gepflegt. Dort kann eine Klasse bei Bedarf nachträglich auf `aktiv`, `archiviert` oder `ausgeschieden` gesetzt werden. Die Klassenverwaltung selbst dient nur der Stammdatenpflege einzelner Klasseninstanzen.

## Löschen alter Klassen

Wenn eine alte Klasse gelöscht wird, greifen die vorhandenen Datenbankbeziehungen. Dadurch werden auch die daran hängenden archivierten Leistungsdaten gelöscht. Das Löschen alter Klassen sollte daher nur bewusst erfolgen.

## Prüffälle

- Nach dem Wechsel bleibt die alte Klasse im alten Schuljahr auswertbar.
- Die neue Klasse enthält die übernommenen Schüler:innen.
- Eine Schüler:in existiert weiterhin einmalig und hat mehrere Klassenzuordnungen.
- Vorjahresdaten sind für berechtigte Lehrer:innen lesbar.
- In archivierten Klassen können keine neuen Einträge angelegt werden.
