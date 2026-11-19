# Tests und lokale Vorschau

Die Tests laufen ohne Symcon-Server, gegen einen Stub der Symcon-Funktionen (`stubs.php`) und mit erfundenen API-Antworten aus `fixtures/`.

```bash
../tools/php/php tests/run.php
```

## Kachel lokal ansehen

Im Repo-Verzeichnis einen PHP-Server starten:

```bash
../tools/php/php -S 127.0.0.1:8765
```

Danach `http://127.0.0.1:8765/tests/tile-preview.php` öffnen. Die Vorschau zeigt die Kachel mit Beispielen aus den Fixtures, umschaltbar zwischen hell und dunkel sowie groß und klein.

## Echte API-Antworten

`capture-openboards.php` läuft als Skript in IP-Symcon, nutzt die Zugangsdaten der Instanz und speichert die Antworten im IP-Symcon-Verzeichnis unter `bahn-capture/`, nie im Repo. Mehr dazu in [fixtures/README.md](fixtures/README.md).
