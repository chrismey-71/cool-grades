# COOL Noten & Mitarbeit (PHP + MySQL)

Web-App im Stil des COOL Raumfinders (modern, mobile-first).

## Installation
1. Upload nach z.B. `/cool-grades`
2. `config.example.php` -> `config.php` kopieren und DB-Daten eintragen
3. `install.php` im Browser aufrufen
4. Login: `admin` / `admin12345` (Passwort sofort ändern)

## Workflow
- Admin: Klassen + Fächer + Schüler:innen (CSV) + Lehrkräfte anlegen
- Lehrer: Klasse+Fach wählen -> Mitarbeit erfassen / Schularbeit anlegen
- Auswertungen: Basisübersicht je Klasse/Fach

## CSV-Import
Semikolon getrennt: `Vorname;Nachname` (Header optional)
