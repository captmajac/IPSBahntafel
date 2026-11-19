<?php

// Saves real DB OpenBoards and OpenStation API responses for checking the migration.
// Usage: DB_CLIENT_ID=... DB_API_KEY=... ../tools/php/php tests/capture-openboards.php <eva> [station name]
// The credentials are only read from the environment and never written to a file.
// The responses go to tests/fixtures/raw/ (gitignored), they are not test fixtures themselves.

date_default_timezone_set('Europe/Berlin');

$eva = $argv[1] ?? '';
$search = $argv[2] ?? '';
$clientId = getenv('DB_CLIENT_ID') ?: '';
$apiKey = getenv('DB_API_KEY') ?: '';
if (!preg_match('/^\d{7}$/', $eva) || $clientId === '' || $apiKey === '') {
    fwrite(STDERR, "Usage: DB_CLIENT_ID=... DB_API_KEY=... php tests/capture-openboards.php <eva> [station name]\n");
    exit(1);
}

$boards = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-boards/v1/';
$stations = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-station/v1/';
$now = time();
$window = ['timeStart' => date('c', $now - 3600), 'timeEnd' => date('c', $now + 3 * 3600)];
$board = fn (array $extra) => array_merge([
    'stopPlaceOrStation'         => ['evaNumbers' => [$eva], 'boardConfigurations' => ['NONE']],
    'timeWindow'                 => $window,
    'maxViaStops'                => 100,
    'maxEntriesPerTransportType' => 50,
    'languages'                  => ['deu'],
], $extra);

// name => [url, JSON body or null for GET, extension]
$requests = [
    // full route?
    'ob_departures' => [$boards . 'departures', $board([]), 'json'],
    'ob_arrivals'   => [$boards . 'arrivals', $board([]), 'json'],
    // default via stops, for comparing with the full route
    'ob_departures_via3' => [$boards . 'departures', $board(['maxViaStops' => 3]), 'json'],
    // window from now: are trains planned before now but delayed past it included?
    'ob_departures_fromnow' => [$boards . 'departures', $board(['timeWindow' => ['timeStart' => date('c', $now), 'timeEnd' => date('c', $now + 3600)]]), 'json'],
];
if ($search !== '') {
    $requests['os_search'] = [$stations . 'netex?' . http_build_query(['stopPlaceName' => $search, 'limit' => 20]), null, 'xml'];
}

$dir = __DIR__ . '/fixtures/raw';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$stamp = date('Ymd-Hi', $now);
foreach ($requests as $name => [$url, $body, $ext]) {
    $headers = ["DB-Client-Id: $clientId", "DB-Api-Key: $apiKey", 'Accept: application/' . $ext];
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $file = "$dir/{$name}_{$eva}_$stamp.$ext";
    file_put_contents($file, (string) $response);
    echo "$status  $name  →  " . basename($file) . "\n";
}
