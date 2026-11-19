# Test-Fixtures

**Synthetische** Antworten im Format von OpenBoards 1.0.3 und OpenStation (NeTEx) für einen erfundenen Bahnhof „WohnortBahnhof“ (EVA 8099001) mit erfundenen Nachbarbahnhöfen (EVA 8099002 und folgende), Linien und Betreibern.

Echte Antworten holt `tests/capture-openboards.php`, das als Skript in IP-Symcon läuft und die Zugangsdaten der Instanz nutzt. Es speichert im IP-Symcon-Verzeichnis unter `bahn-capture/`, nie im Repo, weil die Antworten den Bahnhof und damit den Wohnort verraten.

Ein Abgleich mit einer echten S-Bahn- und Regionalstation am 19.11.2026 ergab:
- `via` enthält mit `maxViaStops: 100` alle Zwischenhalte, ohne Herkunft und Ziel. Bei der Ankunft stehen sie vom Bahnhof aus rückwärts, `displayPriority` zählt 1, 2, 3 ….
- `line.name` ist kompakt (`S7`, `RE30`), `category` ist der Betreiber-Code.
- Das Zeitfenster filtert nach bester Zeit: Verspätete Züge, die vor Fensterbeginn geplant waren, sind enthalten.
- `attributes` hängen an fast jedem Zug, `notes` mit `disruptionID` (z. B. Aufzug außer Betrieb) an vielen. Zugbezogene `notes` haben einen `code`.

| Datei | Inhalt |
|---|---|
| `ob_departures_wohnort.json` | 04.11.2026, 18 bis 20 Uhr: 14 Abfahrten. RE7 18:06 (`-7002…`) startet hier und fällt aus, Störung „Bauarbeiten …“. S1 18:06 pünktlich (`REAL`). RE3 18:19 (`-7004…`) → 18:31, Gleis 2 → 3, Code 43. S2 18:37 +4 min. Bus 100 (`type` `BUS`). RE6 18:54 (`-7008…`) mit Qualitätshinweis, zugbezogener Notiz, Aufzug-Notiz, Attribut und Code 00. IC 2000 19:12 ohne Linie. RB10 19:40. |
| `ob_arrivals_wohnort.json` | 12 Ankünfte zu denselben Fahrten, dazu RE7 19:20, der hier endet. |
| `openstation_wohnort.xml` | Suche „WohnortBahnhof“: WohnortBahnhof (8099001, mit einem Gleis als Quay mit eigener EVA), WohnortBahnhof Nord (8099010) und ein Eintrag ohne EVA. |

Bekannter Trip für Tests: `-7008-2611041854-3`, RE6, Abfahrt 18:54 Gleis 1, Ziel Grenzstadt, über Großstadt Hbf.
