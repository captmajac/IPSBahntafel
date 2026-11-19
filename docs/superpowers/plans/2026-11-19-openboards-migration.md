# Bahntafel: Umstellung auf OpenBoards – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Bahntafel holt ihre Daten wahlweise aus OpenBoards (Standard für neue Instanzen) oder Timetables (Standard für bestehende Instanzen). Die Tafel zeigt mit beiden Quellen dasselbe.

**Architecture:** Neue IPS-freie Schnittstelle `BoardSource` in `libs/` mit zwei Implementierungen: `TimetablesClient` (bestehend) und `OpenBoardsClient` (neu, inklusive Bahnhofssuche über OpenStation). Beide liefern dasselbe Trip-Format, ergänzt um `type` und `extraMessages`. `module.php` wählt den Client über die Eigenschaft `Api` und das Attribut `DefaultApi`. `BoardBuilder` vergleicht Stationsnamen normalisiert und hängt erweiterte Hinweise nur auf Wunsch an.

**Tech Stack:** PHP 8.3 (`../tools/php/php`), IP-Symcon 9 Modul-SDK, `json_decode` für OpenBoards, DOMDocument für Timetables und NeTEx, curl. Eigener Runner `tests/run.php`, Stubs in `tests/stubs.php`.

**Spec:** `docs/superpowers/specs/2026-11-19-openboards-migration-design.md`. Vor jeder Aufgabe lesen, besonders „OpenBoards in Kürze“, „Design“ und „Ergebnisse aus echten Antworten“.

## Nachtrag 2026-11-19: nur noch OpenBoards

Nach Task 6 hat der User entschieden, Timetables ganz zu streichen (Spec: Entscheidung B). Damit entfallen aus Task 1 `TimetablesClient`, aus Task 5 die Eigenschaft `Api`, das Attribut `DefaultApi`, der Bestandsschutz und die zugehörigen Tests, aus Task 6 der Abschnitt zum Umstieg. Die OpenBoards-Fixtures bilden die früheren Timetables-Fixtures nach (gleiche Züge, Zeiten und IDs), damit die Modul-Tests ihre Erwartungen behalten.

## Global Constraints

- Tests: `../tools/php/php tests/run.php` aus dem Repo-Root (im Worktree: `/Users/MArC/Documents/Dev/claude/tools/php/php tests/run.php`). Alle Tests grün nach jeder Aufgabe. Der Runner macht Warnungen und Notices zu Fehlern.
- OpenBoards-Basis `https://apis.deutschebahn.com/db-api-marketplace/apis/open-boards/v1/`, Endpunkte `departures` und `arrivals`, `POST` mit `Content-Type: application/json` und `Accept: application/json`.
- OpenStation-Basis `https://apis.deutschebahn.com/db-api-marketplace/apis/open-station/v1/`, `GET netex?stopPlaceName=…&limit=20`, `Accept: application/xml`.
- Header immer `DB-Client-Id` und `DB-Api-Key`, Statuscodes wie bisher: 401/403 → `ApiAuthException`, sonst nicht 2xx → `ApiException`.
- Request-Body für beide Tafeln: `stopPlaceOrStation: {evaNumbers: [$eva], boardConfigurations: ["NONE"]}`, `timeWindow: {timeStart, timeEnd}` als ISO 8601 mit Offset in `Europe/Berlin`, `maxViaStops: 100`, `maxEntriesPerTransportType: 50`, `languages: ["deu"]`.
- Niemals echte Zugangsdaten, den echten Heimatbahnhof oder echte API-Antworten committen. Fixtures sind synthetisch für „WohnortBahnhof“ (EVA 8099001) mit erfundenen Nachbarn. Echte Antworten nur nach `tests/fixtures/raw/`.
- Das Modul setzt keine eigenen Eigenschaften (`IPS_SetProperty`, `IPS_ApplyChanges` verboten, Konventions-Test). Keine neuen öffentlichen Methoden. Keine automatischen Namens- oder Icon-Änderungen.
- Shared libs ohne `IPS_`-Funktionen (Konventions-Test).
- Code, Kommentare und Formular-Texte auf Englisch. Jeder Text aus Formular und `->Translate("…")` braucht eine deutsche Übersetzung in `Bahntafel/locale.json`, unbenutzte Übersetzungen fliegen raus (Konventions-Tests).
- Commits englisch, kurz, Präfix `Libs:`, `Bahntafel:`, `Tests:` oder `Docs:`, Ende mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Repo-lokale noreply-Adresse. Kein Push ohne Rückfrage.

## Review Focus

1. **Gespeicherte Trips aus der Vorversion** (Attribut `Trips` ohne `type` und `extraMessages`) werden nach dem Modul-Update gerendert, bevor neu geladen wird. Erwartet: Die Tafel erscheint ohne Warnung. → Test in Task 2.
2. **OpenBoards-Eintrag ohne optionale Felder** (kein `platform`, kein `platformSchedule`, kein `line`, kein `via`, keine `messages`, EVA fehlt in `stopPlaces`). Erwartet: keine Warnung, Gleis `''`, Linie `category journeyNumber`, Name fällt auf die EVA zurück. → Test in Task 3.
3. **Fehler nur bei einer Tafel:** `arrivals` liefert 500, `departures` 200. Erwartet: `ApiException`, keine halbe Tafel. 404 bei einer Tafel ist dagegen leer und normal, 404 bei beiden ist eine `ApiException`. Ungültiges JSON ist eine `ApiException`. → Tests in Task 3.
4. **„Über Bahnhof“ aus Timetables-Zeiten** in anderer Schreibweise (`Großstadt(Main)Hbf` gegen `Großstadt (Main) Hbf`). Erwartet: Der Filter greift weiter. → Test in Task 2.
5. **Bestandsinstanz mit geänderter Station:** `FetchedAt > 0`, dann neue Station gewählt. Erwartet: `DefaultApi` wird vor dem Zurücksetzen der Trips festgelegt und bleibt `timetables`. → Test in Task 5.

---

### Task 1: Gemeinsame Schnittstelle und neutrale Ausnahmen

**Files:**
- Create: `libs/BoardSource.php`
- Modify: `libs/TimetablesClient.php`, `Bahntafel/module.php` (nur `require_once`, Klassennamen der Ausnahmen), `tests/helpers.php`, `tests/test_client.php`, `tests/test_module.php` (Klassennamen)
- Test: `tests/test_client.php`

**Interfaces:**
- Produces:
  - `class ApiException extends Exception {}` und `class ApiAuthException extends ApiException {}` in `libs/BoardSource.php`
  - `interface BoardSource { public function searchStations(string $pattern): array; public function stationByEva(string $eva): ?array; public function fetchTrips(string $eva, int $now, int $ahead = 1): array; }`. Rückgaben: Suche `[['eva' => string, 'name' => string], …]`, `stationByEva` `['eva', 'name']` oder `null`, `fetchTrips` `['trips' => Trip[], 'realtime' => bool]`.
  - Trip-Format wie im Kopfkommentar von `TimetablesClient`, ergänzt um `'type' => string` (OpenBoards-`type`, bei Timetables `''`) und `'extraMessages' => string[]` (bei Timetables `[]`). Der Kopfkommentar wandert nach `BoardSource.php`.
  - HTTP-Callable: `fn (string $url, array $headers, ?string $body = null): array{status: int, body: string}`. `null` heißt GET.
- `TimetablesClient implements BoardSource`, `require_once __DIR__ . '/BoardSource.php';` am Dateianfang. `TimetablesException` und `TimetablesAuthException` entfallen überall, auch in Tests.
- `TimetablesClient::stationByEva($eva)`: erster Treffer von `searchStations($eva)` mit gleicher EVA, sonst `null`.

- [ ] **Step 1: Tests schreiben**
  - `Client: Timetables is a BoardSource`: `new TimetablesClient('id', 'key', wohnortHttp()) instanceof BoardSource`.
  - `Client: Timetables trips have type and extraMessages`: jeder Trip aus `parsePlan(fixture('plan_wohnort_18.xml'))` hat `type === ''` und `extraMessages === []`.
  - `Client: Timetables stationByEva`: `stationByEva('8099001') === ['eva' => '8099001', 'name' => 'WohnortBahnhof']`, für `'8099999'` (404) `null`.
  - Bestehende Tests: `TimetablesAuthException` → `ApiAuthException`, `TimetablesException` → `ApiException`.
  - `fakeHttp` nimmt den dritten Parameter `?string $body = null` und speichert `[$url, $headers, $body]` in `TestRegistry::$calls`.
- [ ] **Step 2:** Tests laufen lassen. Erwartet: FAIL, `BoardSource` unbekannt.
- [ ] **Step 3:** Umsetzen wie unter Interfaces. `curl` in `TimetablesClient` bleibt GET-only, der Parameter `$body` wird dort nicht gebraucht.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün.
- [ ] **Step 5:** Commit `Libs: BoardSource interface and neutral API exceptions`.

### Task 2: BoardBuilder und TrainBadge für beide Quellen

**Files:**
- Modify: `libs/BoardBuilder.php`, `libs/TrainBadge.php`
- Test: `tests/test_board.php`, `tests/test_badge.php`

**Interfaces:**
- Consumes: Trip-Format aus Task 1
- Produces:
  - `TrainBadge::group(string $line, string $category, string $type = ''): string`. Ein bekannter `type` hat Vorrang: `HIGH_SPEED_TRAIN`, `INTERCITY_TRAIN` → `LONG`; `REGIONAL_TRAIN`, `INTER_REGIONAL_TRAIN` → `REGIONAL`; `CITY_TRAIN` → `SBAHN`; `BUS` → `BUS`; `TRAM` → `TRAM`. Leerer oder anderer `type` → die bisherigen Muster.
  - `BoardBuilder::build` liest zusätzlich `$settings['extended']` (bool, fehlt → `false`). Mit `true` kommen `extraMessages` nach den `messages` dazu, ohne doppelte Texte. Fehlende Schlüssel `type` und `extraMessages` im Trip gelten als `''` und `[]`.
  - `BoardBuilder::sameStation(string $a, string $b): bool` (public static): Vergleich nach `mb_strtolower` und Entfernen aller Zeichen außer Buchstaben und Ziffern (`/[^\p{L}\p{N}]/u`). Der Richtungsfilter nutzt ihn statt `in_array`.
- „Verspätung“ ohne Grund: Es zählen nur `messages`, nicht `extraMessages`. Ein verspäteter Zug mit nur „Ein Wagen fehlt“ zeigt also „Verspätung“ und, mit `extended`, zusätzlich „Ein Wagen fehlt“.

- [ ] **Step 1: Tests schreiben**
  - `Badge: type wins over the line`: `group('RE30', 'ENO', 'REGIONAL_TRAIN') === TrainBadge::REGIONAL`, `group('X1', 'ABC', 'CITY_TRAIN') === TrainBadge::SBAHN`, `group('ICE 123', 'DB', '') === TrainBadge::LONG`, `group('Foo', 'ABC', 'UNKNOWN') === TrainBadge::OTHER`.
  - `Board: route station matches in another spelling`: Trip mit Abfahrts-`path` `['Großstadt (Main) Hbf', 'Grenzstadt']`, `route` `'Großstadt(Main)Hbf'` → Zeile mit `offRoute === false`. Mit `route` `'Großstadt Süd'` → `offRoute === true`.
  - `Board: extended messages only when enabled`: Trip mit `messages ['Bauarbeiten']`, `extraMessages ['Ein Wagen fehlt', 'Bauarbeiten']` → ohne `extended` `['Bauarbeiten']`, mit `extended` `['Bauarbeiten', 'Ein Wagen fehlt']`.
  - `Board: delay reason ignores extra messages`: verspäteter Trip mit `messages []`, `extraMessages ['Ein Wagen fehlt']`, `extended true` → `['Verspätung', 'Ein Wagen fehlt']`.
  - `Board: trips stored by the previous version`: Trip ohne die Schlüssel `type` und `extraMessages` → `build` liefert die Zeile ohne Fehler (Review Focus 1).
  - `Board: group from type`: Trip mit `line 'X1'`, `category 'ABC'`, `type 'CITY_TRAIN'`, `groups ['sbahn']` → Zeile vorhanden.
- [ ] **Step 2:** Tests laufen lassen. Erwartet: FAIL bei den neuen Tests.
- [ ] **Step 3:** Umsetzen. `build` ruft `TrainBadge::group($trip['line'], $trip['category'], $trip['type'] ?? '')`.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün.
- [ ] **Step 5:** Commit `Libs: board accepts OpenBoards trips: type, extended messages, route name spelling`.

### Task 3: OpenBoardsClient – Tafeln abrufen und umsetzen

**Files:**
- Create: `libs/OpenBoardsClient.php`, `tests/fixtures/ob_departures_wohnort.json`, `tests/fixtures/ob_arrivals_wohnort.json`, `tests/test_openboards.php`
- Modify: `tests/helpers.php` (`openBoardsHttp()`), `tests/fixtures/README.md`

**Interfaces:**
- Consumes: `BoardSource`, `ApiException`, `ApiAuthException` (Task 1)
- Produces:
  - `class OpenBoardsClient implements BoardSource`, Konstruktor `(string $clientId, string $apiKey, ?callable $http = null)`, Konstanten `BASE_URL`, `STATIONS_URL`, `TIMEZONE = 'Europe/Berlin'`.
  - `fetchTrips(string $eva, int $now, int $ahead = 1): array`: Fenster von `$now` bis zum Beginn der Stunde nach `$now` plus `$ahead` Stunden. `realtime` ist immer `true`.
  - `public static function parseBoard(string $json, string $part): array` mit `$part` `'dep'` oder `'arr'`. Liefert `journeyId => Trip`, bei dem nur `$part` gefüllt ist und der andere Teil `null` ist.
  - `public static function mergeBoards(array $departures, array $arrivals): array`: Trips mit gleicher `journeyId` werden zu einem Trip mit `dep` und `arr`. `messages` und `extraMessages` werden vereinigt, ohne doppelte Texte. Ergebnis ist eine Liste.
  - `stationByEva` und `searchStations` kommen in Task 4. Bis dahin werfen sie `ApiException('not implemented')`.
- Zuordnung pro Eintrag (Spec, Abschnitt „OpenBoardsClient“):
  - `planned` ← `timeSchedule`, `changed` ← `time` wenn `timeType !== 'SCHEDULE'`, sonst `null`
  - `platform` ← `platformSchedule.name ?? ''`, `changedPlatform` ← `platform.name ?? null`
  - `cancelled` ← `cancelled || journeyInfo.journeyCancelled`
  - `path`: bei `dep` die Via-Namen plus Ziel, bei `arr` Herkunft plus Via-Namen. Der Name kommt aus `stopPlaces[].localizedNames[0].name`, ohne Eintrag die EVA. Doppelte Namen fallen raus.
  - `id` ← `journeyId`, `line` ← `transport.line.name`, ohne Linie `trim(category . ' ' . journeyNumber)`
  - `category` ← `transport.category`, `number` ← `(string) journeyNumber`, `type` ← `transport.type`
  - `messages`: `risCauseCodes` mit `code !== '00'` und `disruptions`, jeweils `descriptions[0].text`
  - `extraMessages`: `risQualityDeviations` und `notes` mit `code`, ohne `disruptionID`
  - Ignoriert werden `attributes` und `notes` mit `disruptionID`.
- HTTP-Verhalten:
  - 404 einer Tafel → diese Tafel ist leer
  - 404 bei beiden → `ApiException("DB API has no board for station $eva")`
  - 401/403 → `ApiAuthException`
  - andere Nicht-2xx → `ApiException`
  - ungültiges JSON → `ApiException('Invalid JSON from the DB API')`
- `helpers.php`: `openBoardsRoutes(): array` = `['/open-boards/v1/departures' => [200, fixture('ob_departures_wohnort.json')], '/open-boards/v1/arrivals' => [200, fixture('ob_arrivals_wohnort.json')]]` und `openBoardsHttp(): callable` = `fakeHttp(openBoardsRoutes())`. Task 4 ergänzt die Suche in `openBoardsRoutes()`.

**Fixtures** (synthetisch, 04.11.2026, Zeiten mit `+01:00`, Nachbar-EVAs 8099002 und folgende, alle in `stopPlaces` außer einer):

| Fahrt (`journeyId`) | Tafel | Inhalt |
|---|---|---|
| `ob-re3` RE3, `category` und `journeyNumber` wie `-7004-2611041819-4` in `plan_wohnort_18.xml` | beide | Abfahrt geplant 18:19, `time` 18:31 `PREVIEW`, Gleis 2 → 3, Via und Ziel wie das `ppth` der Abfahrt im Timetables-Fixture. Ankunft entsprechend dessen `ar`. `risCauseCodes` 43 „Verspätung eines vorausfahrenden Zuges“. |
| `ob-re7` RE7 | nur dep | 18:06, `cancelled: true`, `disruptions` „Bauarbeiten zwischen WohnortBahnhof und Löwenstadt“ |
| `ob-s1` S1, `type CITY_TRAIN` | beide | 18:06, `time` = `timeSchedule`, `timeType REAL` |
| `ob-re6` RE6 | dep | 18:54, Gleis 1, Ziel Grenzstadt über Großstadt Hbf. `risQualityDeviations` 85 „Ein Wagen fehlt“, `notes` mit `code` 1000 „Heute nur einteilig.“, `notes` mit `disruptionID` „Aufzug Gleis 1 außer Betrieb.“, `attributes` FK, `risCauseCodes` 00 |
| `ob-ic` ohne `line`, `category DB`, `journeyNumber 2000`, `type INTERCITY_TRAIN` | dep | 19:12, kein `platformSchedule`, kein `platform`, kein `via`, keine `messages` |
| `ob-re7b` RE7 | nur arr | 19:20, endet hier, `journeyInfo.journeyCancelled: true`, Herkunft mit EVA ohne `stopPlaces`-Eintrag |

- [ ] **Step 1: Tests in `tests/test_openboards.php` schreiben**
  - `OpenBoards: departure fields`: `ob-re3` dep: `planned === ts('2611041819')`, `changed === ts('2611041831')`, `platform '2'`, `changedPlatform '3'`, `line 'RE3'`, `type 'REGIONAL_TRAIN'`, `messages ['Verspätung eines vorausfahrenden Zuges']`.
  - `OpenBoards: on time is not changed`: `ob-s1` dep `changed === null`.
  - `OpenBoards: cancelled stop and cancelled journey`: `ob-re7` dep `cancelled true`, `messages ['Bauarbeiten zwischen WohnortBahnhof und Löwenstadt']`. `ob-re7b` arr `cancelled true`, `dep null`.
  - `OpenBoards: path order and names`: `end(ob-re6 dep path) === 'Grenzstadt'`, `in_array('Großstadt Hbf', …)`. `ob-re3 arr path[0]` ist die Herkunft. Bei `ob-re7b` ist `path[0]` die EVA ohne Namen (Review Focus 2).
  - `OpenBoards: messages and extra messages`: `ob-re6` `messages []`, `extraMessages ['Ein Wagen fehlt', 'Heute nur einteilig.']`, der Aufzug und FK fehlen.
  - `OpenBoards: entry without line, platform, via and messages`: `ob-ic` `line 'DB 2000'`, `platform ''`, `changedPlatform null`, `path ['<Ziel>']`, `messages []` (Review Focus 2).
  - `OpenBoards: departures and arrivals merge by journey`: `fetchTrips('8099001', ts('2611041800'))` mit `openBoardsHttp()`. `ob-re3` hat `dep` und `arr`, `ob-re7` nur `dep`. Keine Fahrt doppelt.
  - `OpenBoards: request`: zwei Aufrufe auf `…/departures` und `…/arrivals`. Header enthalten `DB-Client-Id: id`, `DB-Api-Key: key`, `Content-Type: application/json`. Body-JSON: `evaNumbers ['8099001']`, `boardConfigurations ['NONE']`, `maxViaStops 100`, `timeStart '2026-11-04T18:00:00+01:00'`, mit `$ahead = 1` `timeEnd '2026-11-04T20:00:00+01:00'`.
  - `OpenBoards: errors`: 401 → `ApiAuthException`. 500 bei `arrivals` → `ApiException` (Review Focus 3). 404 bei einer Tafel → Trips der anderen. 404 bei beiden → `ApiException`. Body `'nope'` → `ApiException`.
  - `OpenBoards: rows like Timetables`: `BoardBuilder::build` mit dep-Einstellungen über den Trip `ob-re3` und über `-7004-2611041819-4` aus `TimetablesClient::merge(parsePlan(18), parseChanges(fchg))`. Gleich sind `line`, `badge`, `time`, `delayed`, `platform`, `platformChanged`, `cancelled`, `direction`, `minutesLeft`, `messages`.
- [ ] **Step 2:** Tests laufen lassen. Erwartet: FAIL, `OpenBoardsClient` unbekannt.
- [ ] **Step 3:** Fixtures anlegen und den Client umsetzen. Die Zeit liest `new DateTime($iso)`, die Zeitfenster schreibt `DateTime::format('c')` mit Zeitzone `Europe/Berlin`. `curl` wie in `TimetablesClient`, mit `CURLOPT_POST` und `CURLOPT_POSTFIELDS`, wenn `$body !== null`.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün.
- [ ] **Step 5:** `tests/fixtures/README.md` um die OpenBoards-Fixtures und die Erkenntnisse aus den echten Antworten ergänzen, ohne Bahnhofsnamen. Commit `Libs: OpenBoards client for departure and arrival boards`.

### Task 4: Bahnhofssuche über OpenStation und EVA

**Files:**
- Modify: `libs/OpenBoardsClient.php`, `tests/helpers.php`, `tests/test_openboards.php`
- Create: `tests/fixtures/openstation_wohnort.xml`

**Interfaces:**
- Consumes: `OpenBoardsClient` (Task 3)
- Produces:
  - `searchStations(string $pattern): array`: `GET STATIONS_URL . 'netex?' . http_build_query(['stopPlaceName' => $pattern, 'limit' => 20])`. Pro `StopPlace` (Namespace-unabhängig über `getElementsByTagNameNS('*', 'StopPlace')`) wird das direkte Kind `Name` gelesen und die `KeyValue` mit `Key` `EVA`. Ohne EVA wird übersprungen, doppelte EVAs ebenso. 401/403 → `ApiAuthException`, keine Treffer → `[]`.
  - `stationByEva(string $eva): ?array`: `POST departures` für `$eva`, Fenster jetzt bis jetzt + 30 min (Zeit aus `time()`, Parameter `?int $now = null` für Tests erlaubt). Der Name kommt aus `stopPlaces` mit dieser `evaNumber`. 404 oder kein passender Eintrag → `null`.
- `openBoardsRoutes()` ergänzt `'netex?stopPlaceName=WohnortBahnhof&limit=20' => [200, fixture('openstation_wohnort.xml')]`. `fakeHttp` vergleicht per `str_ends_with`, darum passt der Suffix mit Query.

**Fixture** `openstation_wohnort.xml`: NeTEx-Rahmen mit Namespace `http://www.netex.org.uk/netex`. Drei `StopPlace`: „WohnortBahnhof“ (EVA 8099001), „WohnortBahnhof Nord“ (EVA 8099010) und einer ohne `KeyValue` EVA. Den Aufbau von `StopPlace`, `Name` und `keyList/KeyValue` zuerst aus dem Beispiel im OpenStation-OpenAPI-Dokument übernehmen. Sobald der User OpenStation abonniert hat, liefert `tests/capture-openboards.php` (als IP-Symcon-Skript) eine echte Antwort. Weicht sie ab, wird das Fixture daran angepasst.

- [ ] **Step 1: Tests schreiben**
  - `OpenStation: search gives eva and name`: `searchStations('WohnortBahnhof') === [['eva' => '8099001', 'name' => 'WohnortBahnhof'], ['eva' => '8099010', 'name' => 'WohnortBahnhof Nord']]`. Der Aufruf geht an `…/open-station/v1/netex?stopPlaceName=WohnortBahnhof&limit=20` mit GET (`$body === null`).
  - `OpenStation: without subscription`: 401 → `ApiAuthException`.
  - `OpenBoards: station by eva`: `stationByEva('8099001') === ['eva' => '8099001', 'name' => 'WohnortBahnhof']`, 404 → `null`.
- [ ] **Step 2:** Tests laufen lassen. Erwartet: FAIL.
- [ ] **Step 3:** Umsetzen.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün.
- [ ] **Step 5:** Commit `Libs: station search through OpenStation and by EVA`.

### Task 5: Modul – Datenquelle, Bestandsschutz, erweiterte Hinweise, EVA-Eingabe

**Files:**
- Modify: `Bahntafel/module.php`, `Bahntafel/form.json`, `Bahntafel/locale.json`, `tests/helpers.php`, `tests/test_module.php`

**Interfaces:**
- Consumes: `BoardSource`, `TimetablesClient`, `OpenBoardsClient`, `ApiException`, `ApiAuthException`, `BoardBuilder` mit `extended`
- Produces (alles `private` oder `protected`, keine neuen öffentlichen Methoden):
  - Eigenschaften: `Api` (int, 0 = Standard, 1 = OpenBoards, 2 = Timetables, Standard 0), `ExtendedMessages` (bool, Standard `false`)
  - Attribut `DefaultApi` (string, `''` | `'openboards'` | `'timetables'`)
  - `private function api(): string` → `'openboards'` oder `'timetables'`: `Api` 1 bzw. 2, bei 0 das Attribut `DefaultApi`
  - `protected function http(): ?callable { return null; }`. `TestBahntafel` überschreibt `http()` mit `TestRegistry::$http` statt `client()`.
  - `protected function client(): BoardSource` erzeugt je nach `api()` den passenden Client mit `$this->http()`.
  - neuer `RequestAction`-Ident `UseEva`
- Ablauf in `ApplyChanges`, Reihenfolge wichtig (Review Focus 5):
  1. Ist `DefaultApi` leer, wird es auf `FetchedAt > 0 ? 'timetables' : 'openboards'` gesetzt.
  2. Danach folgt der bestehende Vergleich der Trips. Der Schlüssel in `TripsEva` wird `api() . ':' . $eva`, damit ein Wechsel der Datenquelle die Trips verwirft.
- `rows()` übergibt `'extended' => $this->ReadPropertyBoolean('ExtendedMessages')`.
- `testConnection()` nutzt `stationByEva($this->station()['eva'] ?? '8000105')` und meldet `sprintf(Translate("OK: %s"), name)`. Bei `null` kommt `Translate("No station found")`. Der Text „OK: %d station(s) found“ entfällt.
- `searchStation()`: `ApiAuthException` bei `api() === 'openboards'` → Popup `Translate("The station search needs a subscription to the API OpenStation. Alternatively enter the EVA number.")`. Sonst wie bisher `errorText()`.
- `useEva(string $eva)`: Leer oder nicht 7 Ziffern → Popup `Translate("Please enter a 7-digit EVA number")`. Sonst `stationByEva`. Bei Treffer werden die `Station`-Optionen gesetzt wie in `searchStation`: aktuelle Station plus Treffer. Bei `null` kommt Popup „No station found“.
- `GetConfigurationForm()`: Die Option 0 der Auswahl `Api` heißt `sprintf(Translate("Default (%s)"), api() === 'timetables' ? 'Timetables' : 'OpenBoards')` (der Standard ergibt sich aus `DefaultApi`).
- Klassenkommentar: „from the DB OpenBoards or Timetables API“. `nextHourStart` nutzt `OpenBoardsClient::TIMEZONE`.
- `form.json`:
  - oben Select `Api` (Caption `Data source`, Optionen Standard / `OpenBoards` / `Timetables (shut down in mid-2027)`)
  - Label `Access to the DB API Marketplace (APIs "OpenBoards" and "OpenStation"; Timetables only for existing subscriptions)`
  - nach den Zug-Checkboxen: CheckBox `ExtendedMessages` mit Caption `Extended notes (e.g. missing coach, high occupancy)`
  - in `actions` nach den Such-Elementen: ValidationTextBox `Eva` (Caption `EVA number`) und Button `Use EVA number` mit `IPS_RequestAction($id, 'UseEva', $Eva);`
- `locale.json`: deutsche Texte für alle neuen Captions und `Translate`-Texte, z. B. „Datenquelle“, „Standard (%s)“, „Erweiterte Hinweise (z. B. fehlender Wagen, hohe Auslastung)“, „EVA-Nummer“, „EVA-Nummer übernehmen“, „OK: %s“. Den alten Text „OK: %d station(s) found“ entfernen.

- [ ] **Step 1: Tests schreiben**
  - `makeBoard()` setzt standardmäßig `'Api' => 2`, damit die bestehenden Modul-Tests bei Timetables bleiben. `wohnortHttp()` übergibt `fakeHttp` zusätzlich `openBoardsRoutes()`.
  - `Module: new instance uses OpenBoards`: `makeBoard(['Api' => 0])` → `DefaultApi === 'openboards'`, Aufrufe gehen an `/open-boards/v1/`.
  - `Module: existing instance keeps Timetables`: `Create()`, Attribute `FetchedAt` auf einen Wert > 0 und `TripsEva` auf `'8099001'` setzen (alter Schlüssel), Eigenschaften `Api 0`, dann `ApplyChanges()` → `DefaultApi === 'timetables'`, Aufrufe an `/timetables/v1/`.
  - `Module: existing instance with a new station keeps Timetables` (Review Focus 5): wie oben, aber eine andere `Station` → weiterhin `timetables`.
  - `Module: explicit data source wins`: `DefaultApi 'timetables'` und `Api 1` → OpenBoards. Umgekehrt `DefaultApi 'openboards'` und `Api 2` → Timetables.
  - `Module: switching the data source reloads`: Instanz mit Trips aus Timetables, `Api` auf 1 setzen, `ApplyChanges()` → neue Trips mit `journeyId` aus den OpenBoards-Fixtures.
  - `Module: extended notes setting`: `makeBoard(['Api' => 1, 'ExtendedMessages' => true])` → Zeile RE6 enthält „Ein Wagen fehlt“. Ohne die Einstellung fehlt der Text.
  - `Module: EVA number fills the station list`: `popup` bei ungültiger Eingabe `'12'` mit dem EVA-Hinweis. Bei `'8099001'` enthält `lastFormUpdate($m, 'Station')` die Option mit `{"eva":"8099001","name":"WohnortBahnhof"}`.
  - `Module: search without OpenStation`: OpenStation-Route mit 401 → Popup mit dem OpenStation-Text.
  - `Module: data source option shows the default`: `GetConfigurationForm()`, Option 0 von `Api` enthält bei `DefaultApi 'timetables'` „Timetables“.
  - `Module: test connection names the station`: Popup `OK: WohnortBahnhof` für beide Datenquellen.
- [ ] **Step 2:** Tests laufen lassen. Erwartet: FAIL.
- [ ] **Step 3:** Umsetzen. Alle Stellen, die `TimetablesException` fangen, fangen jetzt `ApiException`.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün, auch Konventions- und Sprach-Tests.
- [ ] **Step 5:** Commit `Bahntafel: data source OpenBoards or Timetables, extended notes, EVA input`.

### Task 6: Doku und Version

**Files:**
- Modify: `README.md`, `Bahntafel/README.md`, `library.json`, `tests/capture-fixtures.php` (nur der Kopfkommentar)

- [ ] **Step 1:** `Bahntafel/README.md`:
  - Zugang: OpenBoards und OpenStation in derselben Anwendung abonnieren, die Schritte wie bisher bei Timetables.
  - Neuer Abschnitt „Umstieg von Timetables“: Zeitplan (Parallelbetrieb, Abschaltung voraussichtlich Mitte 2027), OpenBoards abonnieren, „Datenquelle“ auf OpenBoards stellen. Dieselbe Client-ID und derselbe API-Key funktionieren weiter.
  - Konfigurationstabelle: Datenquelle, Erweiterte Hinweise, EVA-Nummer.
  - Abrufe: mit OpenBoards 2 Aufrufe pro Abruf (Abfahrt und Ankunft), mit Timetables wie bisher.
  - Grenzen: OpenBoards liefert nur Schienenverkehr, Busse und Trams bleiben leer.
- [ ] **Step 2:** `README.md`: Datenquelle OpenBoards statt Timetables nennen.
- [ ] **Step 3:** `library.json`: `version` auf `1.1`. Kopfkommentar von `capture-fixtures.php`: „Timetables only; for OpenBoards see capture-openboards.php“.
- [ ] **Step 4:** Tests laufen lassen. Erwartet: alle grün.
- [ ] **Step 5:** Commit `Docs: OpenBoards, migration from Timetables, version 1.1`.

### Task 7: Abnahme an der echten Instanz (User)

- [ ] **Step 1:** Prüfen, dass `git status` sauber ist und `git grep` weder die echte EVA noch den echten Bahnhofsnamen findet. Der User nennt beides nur im Chat, sie landen nicht im Plan.
- [ ] **Step 2:** Der User aktualisiert das Modul in IP-Symcon. Die bestehende Instanz zeigt „Standard (Timetables)“, die Tafel läuft weiter.
- [ ] **Step 3:** Der User stellt „Datenquelle“ auf OpenBoards. Abfahrt, Ankunft, Richtungsfilter und „Erweiterte Hinweise“ an und aus werden mit der Web-Tafel der DB verglichen.
- [ ] **Step 4:** Mit OpenStation-Abo: „Bahnhof suchen“ prüfen. Ohne: „EVA-Nummer übernehmen“ prüfen.
