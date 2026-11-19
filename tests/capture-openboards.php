<?php

// IP-Symcon script: checks the DB OpenBoards and OpenStation APIs with the credentials and the
// station of an existing Bahntafel instance. Paste it into a script in IP-Symcon and run it.
// It saves the raw responses in <kernel dir>/bahn-capture/ and prints a short evaluation.
// The credentials are only read from the instance and never printed or saved.

// Bahntafel instance; 0 = the first one found
$instanceId = 0;

const BAHNTAFEL_GUID = '{55B9AC8B-A7F5-4C4F-9086-1DE14EDDF287}';
const BOARDS_URL = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-boards/v1/';
const STATIONS_URL = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-station/v1/';

if ($instanceId === 0) {
    $instanceId = IPS_GetInstanceListByModuleID(BAHNTAFEL_GUID)[0] ?? 0;
}
if ($instanceId === 0) {
    echo "No Bahntafel instance found\n";
    return;
}
$clientId = IPS_GetProperty($instanceId, 'ClientId');
$apiKey = IPS_GetProperty($instanceId, 'ApiKey');
$station = json_decode(IPS_GetProperty($instanceId, 'Station'), true);
if ($clientId === '' || $apiKey === '' || !isset($station['eva'])) {
    echo "Instance $instanceId has no credentials or no station\n";
    return;
}
$eva = $station['eva'];
// first word of the name is enough for a substring search
$search = explode(' ', trim($station['name']))[0];

$now = time();
$tz = new DateTimeZone('Europe/Berlin');
$iso = fn (int $t) => (new DateTime('@' . $t))->setTimezone($tz)->format('c');
$board = fn (array $extra) => array_merge([
    'stopPlaceOrStation'         => ['evaNumbers' => [$eva], 'boardConfigurations' => ['NONE']],
    'timeWindow'                 => ['timeStart' => $iso($now - 3600), 'timeEnd' => $iso($now + 3 * 3600)],
    'maxViaStops'                => 100,
    'maxEntriesPerTransportType' => 50,
    'languages'                  => ['deu'],
], $extra);

// name => [url, JSON body or null for GET, extension]
$requests = [
    'ob_departures'         => [BOARDS_URL . 'departures', $board([]), 'json'],
    'ob_arrivals'           => [BOARDS_URL . 'arrivals', $board([]), 'json'],
    'ob_departures_via3'    => [BOARDS_URL . 'departures', $board(['maxViaStops' => 3]), 'json'],
    'ob_departures_fromnow' => [BOARDS_URL . 'departures', $board(['timeWindow' => ['timeStart' => $iso($now), 'timeEnd' => $iso($now + 3600)]]), 'json'],
    'os_search'             => [STATIONS_URL . 'netex?' . http_build_query(['stopPlaceName' => $search, 'limit' => 20]), null, 'xml'],
];

$dir = IPS_GetKernelDir() . 'bahn-capture';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$stamp = date('Ymd-Hi', $now);
$data = [];
echo "== Requests (station $eva)\n";
foreach ($requests as $name => [$url, $body, $ext]) {
    $headers = ["DB-Client-Id: $clientId", "DB-Api-Key: $apiKey", 'Accept: application/' . $ext];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    file_put_contents("$dir/{$name}_{$eva}_$stamp.$ext", $response);
    printf("%3d  %-22s %6d bytes\n", $status, $name, strlen($response));
    $data[$name] = $status === 200 ? ($ext === 'json' ? json_decode($response, true) : $response) : null;
}
echo "Saved in $dir\n";

// eva => name from the stopPlaces of a board
$names = function (?array $response): array {
    $map = [];
    foreach ($response['stopPlaces'] ?? [] as $stopPlace) {
        $map[$stopPlace['evaNumber']] = $stopPlace['localizedNames'][0]['name'] ?? $stopPlace['evaNumber'];
    }
    return $map;
};
$messageTexts = function (?array $response, array $ids): array {
    $texts = [];
    foreach ($response['messages'] ?? [] as $kind => $list) {
        foreach ($list as $message) {
            if (in_array($message['messageID'], $ids, true)) {
                $code = isset($message['code']) ? ' ' . $message['code'] : '';
                $texts[] = "$kind$code: " . mb_substr($message['descriptions'][0]['text'] ?? '', 0, 60);
            }
        }
    }
    return $texts;
};

foreach (['ob_departures' => 'departures', 'ob_arrivals' => 'arrivals'] as $name => $key) {
    $response = $data[$name];
    if ($response === null) {
        continue;
    }
    $map = $names($response);
    echo "\n== $name: " . count($response[$key]) . " entries\n";
    foreach (array_slice($response[$key], 0, 12) as $entry) {
        $t = $entry['transport'];
        $via = array_map(fn ($v) => $map[$v['evaNumber']] ?? $v['evaNumber'], $t['via'] ?? []);
        $origin = $map[$entry['journeyInfo']['origin']['evaNumber']] ?? '?';
        $destination = $map[$entry['journeyInfo']['destination']['evaNumber']] ?? '?';
        printf(
            "%s -> %s %s [%s] line=%s cat=%s type=%s nr=%s pl=%s/%s%s\n   %s ... %s | via(%d): %s\n",
            substr($entry['timeSchedule'], 11, 5),
            substr($entry['time'], 11, 5),
            $entry['timeType'],
            $entry['journeyId'],
            json_encode($t['line']['name'] ?? null),
            $t['category'],
            $t['type'],
            $t['journeyNumber'],
            $entry['platformSchedule']['name'] ?? '-',
            $entry['platform']['name'] ?? '-',
            !empty($entry['cancelled']) ? ' CANCELLED' : '',
            $origin,
            $destination,
            count($via),
            implode(' | ', $via)
        );
        foreach ($messageTexts($response, $entry['messages'] ?? []) as $text) {
            echo "   msg $text\n";
        }
    }
}

if ($data['ob_departures'] !== null && $data['ob_departures_via3'] !== null) {
    $count = fn (array $r) => array_sum(array_map(fn ($e) => count($e['transport']['via'] ?? []), $r['departures']));
    echo "\n== via stops in total: maxViaStops 100 = " . $count($data['ob_departures']) . ", maxViaStops 3 = " . $count($data['ob_departures_via3']) . "\n";
}

if ($data['ob_departures_fromnow'] !== null) {
    $start = $now;
    $early = array_filter($data['ob_departures_fromnow']['departures'], fn ($e) => strtotime($e['timeSchedule']) < $start);
    echo "\n== window from now: " . count($data['ob_departures_fromnow']['departures']) . " entries, " . count($early) . " of them planned before the start\n";
    foreach ($early as $e) {
        echo '   planned ' . substr($e['timeSchedule'], 11, 5) . ', now ' . substr($e['time'], 11, 5) . ' ' . $e['timeType'] . "\n";
    }
}

if ($data['os_search'] !== null) {
    preg_match_all('/<Key>EVA<\/Key>\s*<Value>(\d+)<\/Value>/', $data['os_search'], $m);
    echo "\n== OpenStation search \"$search\": " . substr_count($data['os_search'], '<StopPlace ') . " stop places, EVA found: " . implode(', ', array_unique($m[1])) . "\n";
}
