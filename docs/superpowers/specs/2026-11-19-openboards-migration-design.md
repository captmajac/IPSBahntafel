# Bahntafel: Umstellung von Timetables auf OpenBoards

Stand: 2026-11-19 · Status: Entwurf zur Freigabe, noch kein Code

## Anlass

Die DB ersetzt die Timetables API durch die OpenBoards API.

- November 2026: OpenBoards geht produktiv.
- Danach etwa 6 Monate Parallelbetrieb.
- Voraussichtlich Mitte 2027: Timetables wird abgeschaltet.
- Neue Abos für Timetables gibt es schon jetzt nicht mehr. Timetables steht nicht mehr im Produktkatalog des Marketplace. Wer das Modul neu einrichtet, kann nur noch OpenBoards abonnieren.

**Ziel:** Die Bahntafel läuft mit OpenBoards genauso wie heute: Abfahrt und Ankunft, Echtzeit, Gleiswechsel, Ausfälle, Hinweise, Richtungsfilter, Erreichbarkeit. Bestandsnutzer behalten bis zur Abschaltung eine funktionierende Tafel, auch wenn sie OpenBoards noch nicht abonniert haben. Neunutzer richten das Modul ohne Timetables ein.

## Quellen

- Produktseite und OpenAPI-Dokument „OpenBoards 1.0.3“ im DB API Marketplace (`/db-api-marketplace/apis/product/open-boards`). Das Dokument war im Browser ohne Anmeldung lesbar. Die Feldnamen unten stammen aus dieser Spezifikation.
- Doku-Seite „Open-API Baustein OpenBoards“ auf developer-docs.deutschebahn.com mit Beispielaufruf.
- OpenAPI-Dokument „OpenStation 1.3.3“ für die Bahnhofssuche.
- **Migrationshinweise Timetables → OpenBoards habe ich auf keiner der Seiten gefunden.** Die Doku-Seite beschreibt nur OpenBoards selbst. Eine Zuordnung von alt nach neu, Termine oder eine Liste entfallener Felder gibt es dort nicht. Die Zuordnung unten habe ich selbst aus beiden Spezifikationen abgeleitet.
- Echte Antworten habe ich am 2026-11-19 ausgewertet, siehe „Ergebnisse aus echten Antworten“.

## OpenBoards in Kürze

- Basis-URL `https://apis.deutschebahn.com/db-api-marketplace/apis/open-boards/v1`
- Zwei Endpunkte: `POST /departures` und `POST /arrivals`. Die Parameter stehen im JSON-Body, die Antwort ist JSON.
- Gleiche Auth-Header wie Timetables: `DB-Client-Id` und `DB-Api-Key`.
- Kostenloser Plan mit 10 Aufrufen pro Sekunde, Betrieb rund um die Uhr.
- Nur Bahnhöfe in Deutschland, nur Schienenverkehr („schwere Schiene“). Daten reichen höchstens 30 Tage zurück.

Beispiel-Body:

```json
{
  "stopPlaceOrStation": { "evaNumbers": ["8099001"], "boardConfigurations": ["NONE"] },
  "timeWindow": { "timeStart": "2026-11-19T17:00:00+01:00", "timeEnd": "2026-11-19T20:00:00+01:00" },
  "maxViaStops": 100,
  "languages": ["deu"]
}
```

Wichtige Request-Felder:

| Feld | Bedeutung |
|---|---|
| `stopPlaceOrStation.evaNumbers` | bis zu 15 EVA-Nummern; alternativ `stada` oder `dhids` |
| `stopPlaceOrStation.boardConfigurations` | Standard `DIRECT` und `WALK`: die Tafel enthält dann auch benachbarte Haltestellen desselben Umsteigebereichs. `NONE` liefert nur die angefragte Station. |
| `timeWindow.timeStart` / `timeEnd` | ISO 8601 mit Offset, höchstens 8 Stunden; Standard ist jetzt bis jetzt + 30 min. Alternativ `entryCount`. |
| `maxViaStops` | Standard 3, nach Anzeigepriorität. Zusatzhalte und ausgefallene Halte kommen immer mit. |
| `maxEntriesPerTransportType` | Standard 25, höchstens 50 pro Produktklasse |
| `transportTypes` | `HIGH_SPEED_TRAIN`, `INTERCITY_TRAIN`, `INTER_REGIONAL_TRAIN`, `REGIONAL_TRAIN`, `CITY_TRAIN`; ohne Angabe alle Schienenverkehre |
| `sortBy` | `TIME` (beste bekannte Zeit, Standard) oder `TIME_SCHEDULE` |
| `noCancellations`, `onlyReplacementTransports`, `includeLineColours`, `includePlatformAccessibility`, `languages` | Zusatzoptionen |

Antwort (`DepartureBoardResponse` bzw. `ArrivalBoardResponse`): eine Liste `departures` bzw. `arrivals`. Dazu kommen globale Nachschlagelisten `stopPlaces` (EVA → Name), `messages`, `administrations` (Betreiber) und `meta.consideredStopPlaces`. Halte und Meldungen in einem Eintrag sind nur Verweise (`evaNumber`, Meldungs-IDs). Die Namen und Texte stehen in den globalen Listen.

## Gegenüberstellung

| Heute aus Timetables | OpenBoards | Bewertung |
|---|---|---|
| **Bahnhofssuche** `GET /station/{pattern}`, liefert `eva` und `name` | **Gibt es nicht.** Die Doku verweist auf die separate API **OpenStation**: `GET /open-station/v1/netex?stopPlaceName=…&limit=…`, Antwort NeTEx-XML, Namenssuche als Teilstring ohne Ranking. Die EVA steht als `KeyValue` `EVA`. | **Lücke.** Neunutzer brauchen ein zweites Abo (OpenStation). Ob das kostenlos ist, sagt die Seite ohne Anmeldung nicht. |
| **Plan pro Stunde** `GET /plan/{eva}/{yymmdd}/{HH}`, heute 3 bis 6 Aufrufe | Ein `POST /departures` mit `timeWindow` bis 8 h. Soll und Echtzeit kommen in derselben Antwort. | **Besser.** Kein Stundenraster, kein Zusammenführen mit `fchg`, keine Sommerzeit-Sonderfälle |
| **Änderungen** `GET /fchg/{eva}` | In der Tafel enthalten | entfällt |
| Echtzeit `ct` | `time` mit `timeType` `SCHEDULE` / `PREVIEW` / `REAL`; Soll in `timeSchedule` | gleichwertig |
| Gleis `pp` / `cp` | `platformSchedule.name` / `platform.name`, dazu `platformHistory` | gleichwertig, die Historie ist neu |
| Ausfall `cs="c"` | `cancelled` am Halt, `journeyInfo.journeyCancelled` für die ganze Fahrt | gleichwertig |
| Meldungen `m@t`: `d` mit Code → `MessageCodes.php`, `h` mit `cat`, `q` verworfen | `messages` mit IDs → `risCauseCodes` (Code **und Klartext**), `disruptions` (Störungen mit Text, Ursache, Auswirkung), `notes` (Freitexte, Bauarbeiten), `risQualityDeviations` (z. B. „WLAN nicht verfügbar“), `attributes`. Dazu Störungen für die ganze Station (`disruptionCommunications`). | **Besser.** Die Verspätungsgründe kommen als Text, `MessageCodes.php` wird für OpenBoards nicht gebraucht. Die Codeliste ist fast dieselbe wie bei Timetables. |
| **Laufweg** `ppth`: alle folgenden Halte bei der Abfahrt, alle vorherigen bei der Ankunft, als Namen | `transport.via` mit EVA und `displayPriority`; Namen über `stopPlaces`. Herkunft und Ziel stehen in `journeyInfo.origin` / `destination`. | **Größtes Risiko.** Standardmäßig kommen nur 3 Via-Halte nach Priorität. Ob ein hoher `maxViaStops` wirklich **alle** Halte in Fahrtreihenfolge liefert, sagt die Spezifikation nicht. Ohne vollständige Halte funktioniert der Richtungsfilter nur für wichtige Halte. |
| „weiter nach“ bei der Ankunft: heute Ende des `ppth` der Abfahrt | `journeyInfo.destination` direkt am Ankunftseintrag | **Besser.** Ein Aufruf genügt, die Abfahrtstafel wird dafür nicht gebraucht. |
| Linie `l` (z. B. `RE3`, `S1`, `3`) | `transport.line.name`, in echten Antworten kompakt (`S7`, `RE30`) | gleichwertig |
| Kategorie `tl@c`, Nummer `tl@n` | `transport.category` (in echten Antworten der Betreiber-Code, z. B. `ENO`), `journeyNumber`, dazu `type` (`HIGH_SPEED_TRAIN` … `BUS`, `TRAM`) | **Besser.** Mit `type` lässt sich die Gruppe zuordnen, ohne Muster im Namen zu raten |
| Fahrt-ID `s@id` | `journeyId` (dazu `departureId` / `arrivalId`, `dfid`) | gleichwertig. Abfahrt und Ankunft derselben Fahrt haben dieselbe `journeyId`. |
| Auth `DB-Client-Id` / `DB-Api-Key` | dieselben Header | gleich. Vermutlich reicht es, die bestehende Anwendung zusätzlich für OpenBoards zu abonnieren (noch nicht geprüft). |
| Rate-Limit | 10 Aufrufe pro Sekunde | unkritisch, das Modul braucht höchstens ein paar Aufrufe pro Stunde |
| Busse, Trams in der Tafel | **Nur Schiene.** `type` kennt zwar `BUS` und `TRAM`, die Doku schließt sie aber aus. Ersatzverkehr wird als `replacementTransport` gekennzeichnet. | **Kleine Lücke.** Die Gruppen Bus und Tram bleiben meist leer, Bus ist ohnehin standardmäßig aus. |
| Stationsnamen, z. B. `Frankfurt(Main)Hbf` | `localizedNames[].name`, z. B. `Frankfurt (Main) Hbf` | **Achtung:** Die Schreibweise kann abweichen. Ein gespeicherter „Über Bahnhof“ passt dann nicht mehr (siehe Design). |

### Neu nutzbar (nicht Teil dieser Umstellung)

Klartext-Meldungen in mehreren Sprachen, Störungen für den ganzen Bahnhof, Zusatzhalte, Ersatzverkehr (SEV) und Ersatz- oder Entlastungszüge, Linienfarben, Barrierefreiheit der Gleise, Richtungstexte, Wagenreihung vorhanden. Davon übernehme ich jetzt nur die Klartext-Meldungen, weil sie `MessageCodes.php` ersetzen. Der Rest ist eine eigene Ausbaustufe.

## Ansätze

**A – Zweiter Client hinter gemeinsamer Schnittstelle, Umschalter in der Instanz (empfohlen).**
Neuer `OpenBoardsClient` mit `fetchTrips` und `searchStations`, gleiches Trip-Format. Eine neue Eigenschaft „Datenquelle“ wählt zwischen OpenBoards und Timetables. Neue Instanzen starten mit OpenBoards. Bestehende Instanzen bleiben einmalig auf Timetables, bis der Nutzer umschaltet. Nach der Abschaltung fliegt Timetables in einem eigenen Release raus.
- \+ Für Bestandsnutzer bricht nichts. Sie schalten um, wenn ihr OpenBoards-Abo steht, und können bei Problemen in der Anfangszeit zurück.
- \+ Neunutzer sehen nur OpenBoards als Standard.
- \+ `BoardBuilder`, `BoardHtml`, Kachel und Tests bleiben fast unverändert, weil das Trip-Format gleich bleibt.
- − Eine Eigenschaft und ein Client mehr, bis Mitte 2027.

**B – Harter Wechsel auf OpenBoards.**
`TimetablesClient` wird ersetzt.
- \+ Am wenigsten Code.
- − Jeder Bestandsnutzer ohne OpenBoards-Abo hat nach dem Modul-Update eine kaputte Tafel („Zugangsdaten abgelehnt“). Bei einer Kinderkrankheit der neuen API gibt es keinen Ausweg.

**C – Automatischer Fallback.**
Erst OpenBoards versuchen, bei 401/403 Timetables.
- \+ Kein Schalter.
- − Doppelte Aufrufe und unklarer Zustand („welche API lief gerade?“). Ab Mitte 2027 scheitert der Fallback still. Die Fehlerbilder werden schwer verständlich.

**Empfehlung: A.** Der Parallelbetrieb ist genau dafür da. Ein sichtbarer Schalter mit klarem Status ist für Bestandsnutzer verständlicher als Automatik. Der Aufwand gegenüber B ist klein, weil beide Clients dieselbe Schnittstelle bedienen.

## Design (Ansatz A)

### Schnittstelle und Fehler

- `libs/BoardSource.php`: `interface BoardSource { searchStations(string $pattern): array; fetchTrips(string $eva, int $now, int $ahead = 1): array; }`. Das Rückgabeformat bleibt wie heute dokumentiert: `['trips' => Trip[], 'realtime' => bool]`.
- Die Ausnahmen werden neutral: `ApiException` und `ApiAuthException` in `libs/BoardSource.php`. Sie ersetzen `TimetablesException` und `TimetablesAuthException` überall im Repo. Beide Clients werfen sie, das Modul fängt nur sie.
- `TimetablesClient implements BoardSource` bleibt unverändert.

### OpenBoardsClient (`libs/OpenBoardsClient.php`)

- `fetchTrips($eva, $now, $ahead)`: Das Zeitfenster entspricht heute: Start bei `$now`, weil das Fenster nach bester Zeit filtert und Verspätete darum mitkommen. Ende am Ende der Stunde `$now + $ahead` h, höchstens 8 h. Je ein `POST /departures` und `POST /arrivals` mit `evaNumbers: [$eva]`, `boardConfigurations: ["NONE"]` (nur der Heimatbahnhof wie heute), `maxViaStops: 100`, `maxEntriesPerTransportType: 50`, `languages: ["deu"]`. Abfahrten und Ankünfte werden über `journeyId` zu einem Trip zusammengeführt. Die bisherige Schleife im Modul, die bei zu wenigen Zeilen weitere Stunden nachlädt, bleibt. Pro Durchlauf sind es dann 2 statt 4 bis 6 Aufrufe.
- Zuordnung pro Halt:
  - `planned` ← `timeSchedule`
  - `changed` ← `time`, wenn `timeType` nicht `SCHEDULE` ist, sonst `null`
  - `platform` ← `platformSchedule.name`, `changedPlatform` ← `platform.name`
  - `cancelled` ← `cancelled` oder `journeyInfo.journeyCancelled`
  - `path`: bei der Abfahrt die Via-Namen und dann das Ziel aus `journeyInfo.destination`, bei der Ankunft die Herkunft aus `journeyInfo.origin` und dann die Via-Namen. `BoardBuilder` braucht nur den ersten bzw. letzten Eintrag und die Menge der Halte, nicht die Reihenfolge dazwischen. Die Namen kommen aus `stopPlaces`, ohne doppelte Einträge.
- Trip: `id` ← `journeyId`. `line` ← `transport.line.name`, ohne Linie `category journeyNumber`. `category` ← `transport.category`, `number` ← `journeyNumber`.
- Meldungen: Texte der Meldungs-IDs des Halts, ohne doppelte Texte, in zwei Feldern des Trips:
  - `messages`: Hinweise zu Verspätung, Ausfall und Störung des Zugs, also `risCauseCodes` außer Code `00` und `disruptions` (kurzer Text). Sie erscheinen immer.
  - `extraMessages`: `risQualityDeviations` (z. B. „Ein Wagen fehlt“, „Hohe Auslastung“) und `notes` mit `code` (z. B. „Heute nur einteilig“, „Ohne Halt zwischen …“). Sie erscheinen nur mit „Erweiterte Hinweise“.
  - Immer weg bleiben `notes` mit `disruptionID` (Infrastruktur wie Aufzüge) und `attributes`.
  - `TimetablesClient` liefert `extraMessages` leer, weil Qualitätsmeldungen dort nur als Code ohne Text kommen. Doppelte Texte fallen raus. „Verspätung“ ohne Grund ergänzt weiter `BoardBuilder`.
- `realtime` ist immer `true`, weil Soll und Echtzeit aus derselben Antwort kommen. Scheitert der Aufruf, gibt es eine `ApiException`. 401/403 ergeben eine `ApiAuthException`. Antwortet 404 bei beiden Tafeln, gibt es eine `ApiException` „keine Tafel für Station“ wie heute.
- `searchStations($pattern)`: `GET open-station/v1/netex?stopPlaceName=…&limit=20`. Aus dem NeTEx-XML kommen `StopPlace` → `Name` und `KeyValue` `EVA`. Treffer ohne EVA werden übersprungen. Fehlt das OpenStation-Abo (401/403), meldet das Modul: „Die Bahnhofssuche braucht das Abo OpenStation. Alternativ die EVA-Nummer eintragen.“ Die Tafel selbst funktioniert trotzdem.
- `stationByEva($eva)`: Ersatz für die Suche ohne OpenStation-Abo. Ein `POST /departures` für die EVA, der Name kommt aus `stopPlaces`. Gibt `['eva', 'name']` zurück oder `null`, wenn die EVA unbekannt ist.
- HTTP läuft wie heute über ein injizierbares `$http`, jetzt mit Methode und Body für POST. Darüber laufen die Tests ohne Netz.

### Modul

- Neue Eigenschaft `Api`: 0 = Standard, 1 = OpenBoards, 2 = Timetables. Im Formular steht sie ganz oben als Auswahl „Datenquelle“ mit dem Hinweis auf die Abschaltung Mitte 2027. Die Option 0 zeigt, was der Standard dieser Instanz ist, z. B. „Standard (Timetables)“.
- **Bestandsschutz ohne Selbstkonfiguration:** Das Modul darf seine Eigenschaften nicht selbst setzen (Konventions-Test gegen `IPS_SetProperty`). Darum entscheidet ein Attribut `DefaultApi`, was „Standard“ bedeutet. `ApplyChanges` setzt es einmalig, solange es leer ist: `timetables`, wenn die Instanz schon Daten geladen hat (`FetchedAt > 0`), sonst `openboards`. Das passiert vor dem Zurücksetzen der Trips. Neue Instanzen bekommen so OpenBoards, bestehende behalten Timetables, bis der Nutzer „OpenBoards“ wählt.
- `client(): BoardSource` liefert je nach `Api` den passenden Client.
- **Bahnhofswahl:** Die Suche bleibt wie heute (Feld „Bahnhof suchen“ und Auswahl). Darunter kommt ein Feld „EVA-Nummer“ mit der Schaltfläche „Übernehmen“. Sie ruft bei OpenBoards `stationByEva` auf und trägt Name und EVA als Auswahl ein, wie ein Suchtreffer. Die README empfiehlt, OpenStation zusammen mit OpenBoards zu abonnieren. Die EVA-Eingabe ist nur der Ausweg ohne dieses Abo.
- Neue Eigenschaft `ExtendedMessages` (Checkbox „Erweiterte Hinweise“, Standard aus). `BoardBuilder` hängt mit `settings['extended']` die `extraMessages` an die `messages` der Zeile an.
- „Verbindung testen“ ruft bei OpenBoards eine Abfahrtstafel ab, weil das das Abo ist, auf das es ankommt. Bei Timetables bleibt es wie heute.
- **Richtungsfilter:** `BoardBuilder` vergleicht den „Über Bahnhof“ mit den Halten des Laufwegs normalisiert: Kleinbuchstaben, ohne Leerzeichen und Satzzeichen. `Frankfurt(Main)Hbf` passt dann auf `Frankfurt (Main) Hbf`. Ein gespeicherter Wert überlebt den Wechsel der Datenquelle, ohne dass der Nutzer neu wählt.
- Nach einem Wechsel der Datenquelle verwirft das Modul die geladenen Trips und lädt neu.
- Gruppen: `TrainBadge::group` bekommt optional `type` aus OpenBoards, das bei OpenBoards Vorrang vor den Mustern hat (`HIGH_SPEED_TRAIN`/`INTERCITY_TRAIN` → lang, `REGIONAL_TRAIN`/`INTER_REGIONAL_TRAIN` → regional, `CITY_TRAIN` → S-Bahn). Ohne `type` gelten die bisherigen Muster. Dafür bekommt der Trip ein zusätzliches Feld `type` (bei Timetables `''`).

### Doku

- README und Modul-README: OpenBoards abonnieren, für die Bahnhofssuche zusätzlich OpenStation. Hinweis auf den Parallelbetrieb und auf das Umschalten für Bestandsnutzer. Die Abrufzahl wird angepasst (2 statt 4 Aufrufe).
- `locale.json`: neue Texte.

### Tests (TDD, `../tools/php/php tests/run.php`)

- Synthetische JSON-Fixtures für den fiktiven „WohnortBahnhof“ (EVA 8099001): `ob_departures_wohnort.json` und `ob_arrivals_wohnort.json` mit Verspätung, Gleiswechsel, Ausfall, Fahrtausfall, Meldungen aller Arten und einem Zug ohne Linie. Dazu `openstation_wohnort.xml`. Echte Antworten nur nach `tests/fixtures/raw/`.
- `test_openboards.php`: Zuordnung zum Trip-Format, Zusammenführen über `journeyId`, Laufweg-Reihenfolge, Meldungen, Zeitfenster und Body der Anfrage, Fehlerfälle 401/403/404/5xx, Bahnhofssuche.
- Ein gemeinsamer Vertragstest: Beide Clients liefern für gleichwertige Fixtures gleiche Board-Zeilen aus `BoardBuilder`.
- `test_module.php`: Umschalter, Bestandsschutz, Verwerfen der Trips beim Wechsel.
- `test_board.php`: normalisierter Richtungsvergleich.
- `capture-fixtures.php` bekommt einen OpenBoards-Modus (POST, Zugangsdaten nur aus der Umgebung).

### Nach der Abschaltung (eigenes Release, Mitte 2027)

`TimetablesClient`, `MessageCodes.php`, die Timetables-Fixtures und die Auswahl „Datenquelle“ entfallen. Die Eigenschaft `Api` bleibt registriert, damit gespeicherte Konfigurationen gültig bleiben, und wird ignoriert.

## Ergebnisse aus echten Antworten (2026-11-19)

Abfrage einer S-Bahn- und Regionalstation mit `tests/capture-openboards.php` als IP-Symcon-Skript: 37 Abfahrten und 37 Ankünfte in 4 Stunden.

1. **Laufweg vollständig.** Mit `maxViaStops: 100` kommen alle Zwischenhalte, auch 18 bei einem langen RE. Mit dem Standard 3 kommt nur die Hälfte der Halte (177 gegen 93). Bei der Abfahrt enthält `via` die folgenden Halte, bei der Ankunft die vorherigen. Herkunft und Ziel sind nicht enthalten, sie kommen aus `journeyInfo`. Ein Zug ohne Zwischenhalt hat ein leeres `via`. Bei der Ankunft stehen die Halte ab dem Heimatbahnhof rückwärts. Das Design nutzt die Reihenfolge darum nicht: Herkunft und Ziel kommen aus `journeyInfo`, der Richtungsfilter prüft nur, ob der Halt enthalten ist.
2. **`line.name`** ist kompakt wie bei Timetables (`S7`, `RE30`). **`category` ist der Betreiber-Code** (z. B. `SBH`, `ENO`, `WFB`), nicht die Gattung. Die Gruppe kommt darum aus `type`. Die bisherige Betreiber-Anzeige (`operator`, wenn die Linie nicht mit der Kategorie beginnt) funktioniert weiter. Einen ICE ohne Linie enthielt die Stichprobe nicht, der Rückfall `category journeyNumber` bleibt.
3. **Zeitfenster filtert nach bester Zeit.** Ein Zug, geplant 22 Minuten vor Fensterbeginn und 6 Minuten danach erwartet, war im Fenster ab jetzt enthalten. Die Stunde Vorlauf für Verspätete entfällt, das Fenster beginnt bei `$now`.
4. **Zugangsdaten:** Die bestehende Anwendung mit zusätzlichem OpenBoards-Abo funktioniert mit denselben Client-ID und API-Key. Bestandsnutzer müssen nur abonnieren und umschalten.
5. **OpenStation:** 401 ohne eigenes Abo. Format und Kosten prüfe ich, sobald es abonniert ist. Bis dahin beruht der Parser auf der Spezifikation und dem Beispiel im OpenAPI-Dokument.
6. **Meldungen:** `attributes` (z. B. „Fahrradmitnahme begrenzt möglich“, „Rollstuhlstellplatz“) hängen an fast jedem Zug und bleiben weg. `risQualityDeviations` liefern nützliche Hinweise wie „Ein Wagen fehlt“ oder „Hohe Auslastung“. Es gibt zwei Arten von `notes`. Störungshinweise zur Infrastruktur haben `disruptionID` und `disruptionCommunicationID`, z. B. ein Aufzug außer Betrieb. Sie hingen an etwa der Hälfte der Züge. Zugbezogene Hinweise haben einen `code` (z. B. `1000`: „Heute nur einteilig“, „Ohne Halt zwischen …“). Die Reihenfolge der Via-Halte entspricht `displayPriority` (1, 2, 3 …).
7. Abfahrt und Ankunft derselben Fahrt haben dieselbe `journeyId`. Das Zusammenführen funktioniert.

## Nicht Teil dieser Umstellung

Störungen des Bahnhofs, Ersatzverkehr, Linienfarben, Barrierefreiheit, mehrsprachige Texte, Tafeln mit benachbarten Haltestellen.
