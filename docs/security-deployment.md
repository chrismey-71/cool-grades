# Sicherheits-Hinweise für den Betrieb

Diese Hinweise ergänzen die Anwendungssicherheit. Sie ersetzen keine schulinterne technische und datenschutzrechtliche Prüfung.

## Sicherheitsheader

COOL-Grades sendet für die gerenderten App-Seiten zentrale Sicherheitsheader:

- `Content-Security-Policy`
- `X-Frame-Options`
- `X-Content-Type-Options`
- `Strict-Transport-Security` bei HTTPS
- `Referrer-Policy`
- `Permissions-Policy`

Die Content-Security-Policy erlaubt wegen vorhandener Inline-Skripte und Inline-Styles noch `'unsafe-inline'`. Externe Skripte, Frames und Objekte werden dennoch blockiert. Eine spätere Härtung kann Inline-Skripte schrittweise in externe Dateien verlagern und die CSP weiter verschärfen.

## Login-Schutz

Der Login ist datenbankgestützt gegen wiederholte Fehlversuche gedrosselt. Die Administration kann festlegen:

- maximale Fehlversuche,
- Verzögerung nach Fehlversuch,
- Dauer der temporären Sperre.

Die Zuordnung erfolgt über Username und gehashten IP-Wert. Die IP-Adresse wird dabei nicht im Klartext gespeichert.

## Installation absichern

`install.php` benötigt zusätzlich zum Localhost-Check ein Einmal-Token aus `config.php`.

Vorgehen:

1. In `config.php` ein langes, zufälliges `install_token` setzen.
2. Installation mit `install.php?token=...` aufrufen.
3. Nach erfolgreicher Installation wird `install.lock` angelegt.
4. `install.php` anschließend vom Server entfernen.

## Logs schützen

Empfohlen ist, Logs außerhalb des öffentlich erreichbaren Webroots zu speichern:

```php
'log_dir' => __DIR__.'/../cool-grades-logs',
```

Das ist der wirksamste Schutz, weil der Webserver die Logdateien dann gar nicht ausliefern kann.

Wenn Logs aus betrieblichen Gründen im Webroot liegen, muss der Webserver den Zugriff explizit sperren.

Apache-Beispiel:

```apache
<Directory "/pfad/zur/app/logs">
  Require all denied
</Directory>
```

Nginx-Beispiel:

```nginx
location ^~ /logs/ {
  deny all;
  return 403;
}

location ~ /\.(?!well-known) {
  deny all;
}
```

Zusätzlich sollte das Webserver-Root möglichst auf ein öffentliches Unterverzeichnis zeigen. Konfiguration, Logs, Backups und Runtime-Daten sollten außerhalb des Webroots liegen.

## macOS-Dateien

`.DS_Store` und AppleDouble-Dateien (`._*`) sind in `.gitignore` ausgeschlossen. Vor Releases kann geprüft werden:

```bash
find . -name .DS_Store -o -name '._*'
```
