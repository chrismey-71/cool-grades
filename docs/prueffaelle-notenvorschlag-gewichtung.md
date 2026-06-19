# Prüffälle: Gewichtung für Notenvorschläge

Stand: Version 1.69

Die folgenden Prüffälle kontrollieren ausschließlich die pädagogische Berechnungshilfe. Finale Noten dürfen durch keinen Prüffall automatisch gesetzt oder verändert werden.

1. Im Jahresmodell die Bereichsgewichte 60/20/20 speichern, Seite neu laden und Werte vergleichen.
2. Im Jahresmodell die Jahresgewichte 40/60 speichern, Seite neu laden und Werte vergleichen.
3. Eine Schulnachricht aus Daten des 1. Semesters öffnen und Zeitraum sowie Bereichswerte kontrollieren.
4. Eine Jahresbeurteilung öffnen und die Trennung zwischen Schulnachricht und restlichem Schuljahr kontrollieren.
5. Bei SOST und NOST sicherstellen, dass keine Jahresgewichtungsfelder angezeigt werden.
6. Bei SOST prüfen, dass das Wintersemester nur Winterdaten verwendet.
7. Bei SOST prüfen, dass das Sommersemester nur Sommerdaten verwendet.
8. Bei SOST die Jahresübersicht öffnen: Es darf kein automatischer Jahresvorschlag erscheinen.
9. Bei NOST prüfen, dass das Wintersemester nur Winterdaten verwendet.
10. Bei NOST prüfen, dass das Sommersemester nur Sommerdaten verwendet.
11. Bei NOST die Jahresübersicht öffnen: Es darf kein automatischer Jahresvorschlag erscheinen.
12. Mitarbeit mit positiven, unauffälligen und negativen Eindrücken erfassen; der Bereichswert muss aus Eindruck/Relevanz entstehen.
13. Besondere mündliche Leistungen prüfen; sie dürfen nicht als Notendurchschnitt behandelt werden.
14. Besondere schriftliche Leistungen prüfen; deren Noten müssen als Bereichswert verwendet werden.
15. Mehrere schriftliche Noten prüfen; ohne separate Relevanz werden sie gleich gewichtet.
16. Einen leeren Bereich prüfen; er darf weder als 0 noch als Note 1 oder 5 eingehen.
17. Ohne gespeicherte Einstellungen müssen 60/20/20 und beim Jahresmodell 40/60 verwendet werden.
18. Im Formular eine Summe ungleich 100 % speichern; die Speicherung muss mit verständlicher Meldung abgewiesen werden.
19. Einen alten, unvollständigen Datensatz prüfen; fehlende Werte müssen auf Standards zurückfallen und intern normalisiert werden.
20. Nur schriftliche Leistungen bereitstellen; es darf nur ein deutlich markierter, nicht ausreichend abgesicherter Zwischenwert erscheinen.
21. Eine bestehende finale Note öffnen und Gewichte ändern; die finale Note darf sich nicht automatisch ändern.
22. In `teacher/final_assessments.php` Vorschlag, konfigurierte Gewichte, wirksame Gewichte und ausgeschlossene Bereiche prüfen.
23. Gespeicherte Vorschläge in Übersicht und PDF auf dieselbe Snapshot-Erklärung prüfen.
24. Den Hinweisblock zu SchUG/LBVO in `teacher/manage.php` kontrollieren.
