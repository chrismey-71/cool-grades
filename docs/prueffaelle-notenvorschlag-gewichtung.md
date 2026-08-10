# Prüffälle: Orientierungsgewichtung für Notenvorschläge

Stand: Version 1.74

Die folgenden Prüffälle kontrollieren ausschließlich die pädagogische Berechnungshilfe. Finale Noten dürfen durch keinen Prüffall automatisch gesetzt oder verändert werden.

1. Neues Schularbeitsfach ohne gespeicherte Werte öffnen; als Orientierung müssen 40/10/50 angezeigt werden.
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
15. Mehrere schriftliche Noten mit Einzelgewicht prüfen; der schriftliche Bereichswert muss als gewichteter Mittelwert entstehen.
16. Einen leeren Bereich prüfen; er darf weder als 0 noch als Note 1 oder 5 eingehen.
17. Ohne gespeicherte Einstellungen müssen fachtypabhängige Orientierungswerte und beim Jahresmodell zusätzlich 40/60 verwendet werden.
18. Im Formular eine Summe ungleich 100 % speichern; die Speicherung muss mit verständlicher Meldung abgewiesen werden.
19. Einen alten, unvollständigen Datensatz prüfen; fehlende Werte müssen auf Standards zurückfallen und intern normalisiert werden.
20. Nur schriftliche Leistungen bereitstellen; es darf nur ein deutlich markierter, nicht ausreichend abgesicherter Zwischenwert erscheinen.
21. Eine bestehende finale Note öffnen und Gewichte ändern; die finale Note darf sich nicht automatisch ändern.
22. In `teacher/final_assessments.php` Vorschlag, konfigurierte Gewichte, wirksame Gewichte und ausgeschlossene Bereiche prüfen.
23. Gespeicherte Vorschläge in Übersicht und PDF auf dieselbe Snapshot-Erklärung prüfen.
24. Den Hinweisblock zur unverbindlichen Orientierungsgewichtung in `teacher/manage.php` kontrollieren.
25. Nicht-Schularbeitsfach ohne gespeicherte Werte öffnen; als Orientierung müssen 40/30/30 angezeigt werden.
26. Bestehende individuell gespeicherte Werte öffnen; sie dürfen nicht durch fachtypabhängige Presets überschrieben werden.
27. Ein Preset bewusst auswählen; erst danach dürfen die Formularwerte übernommen werden, gespeichert wird nur durch den Speichern-Button.
28. Schularbeiten, Tests und Diktate erfassen; die Typen müssen getrennt gezählt werden.
29. Schularbeitsfach mit schriftlicher Gewichtung unter 30 % prüfen; es soll ein sachlicher Hinweis erscheinen, aber keine Sperre.
