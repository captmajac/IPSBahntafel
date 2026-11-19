<?php

declare(strict_types=1);

require_once __DIR__ . '/BoardSource.php';

// DB OpenBoards API (departure and arrival boards as JSON) and DB OpenStation (station search, NeTEx).
// Trip format see BoardSource.
class OpenBoardsClient implements BoardSource
{
    const BASE_URL = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-boards/v1/';
    const STATIONS_URL = 'https://apis.deutschebahn.com/db-api-marketplace/apis/open-station/v1/';
    const TIMEZONE = 'Europe/Berlin';

    private $http;

    public function __construct(private string $clientId, private string $apiKey, ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'curl'];
    }

    // OpenStation, a subscription of its own: substring match on the name, EVA from the keyList
    public function searchStations(string $pattern): array
    {
        $xml = $this->request(self::STATIONS_URL . 'netex?' . http_build_query(['stopPlaceName' => $pattern, 'limit' => 20]), 'application/xml');
        if ($xml === null) {
            return [];
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new ApiException('Invalid XML from the DB API');
        }
        $stations = [];
        foreach ($doc->getElementsByTagNameNS('*', 'StopPlace') as $stopPlace) {
            $eva = '';
            $name = '';
            // direct children only: quays have names and keys of their own
            foreach ($stopPlace->childNodes as $child) {
                if ($child->localName === 'Name') {
                    $name = trim($child->textContent);
                } elseif ($child->localName === 'keyList') {
                    foreach ($child->getElementsByTagNameNS('*', 'KeyValue') as $keyValue) {
                        $key = $keyValue->getElementsByTagNameNS('*', 'Key')->item(0);
                        $value = $keyValue->getElementsByTagNameNS('*', 'Value')->item(0);
                        if ($key && $value && trim($key->textContent) === 'EVA') {
                            $eva = trim($value->textContent);
                        }
                    }
                }
            }
            if ($eva !== '' && $name !== '' && !isset($stations[$eva])) {
                $stations[$eva] = ['eva' => $eva, 'name' => $name];
            }
        }
        return array_values($stations);
    }

    // name from the stopPlaces of a board; works without OpenStation. The longest window the API allows (8 h),
    // arrivals too for a terminus: a quiet station may have no departure soon
    public function stationByEva(string $eva, ?int $now = null): ?array
    {
        $now ??= time();
        $tz = new DateTimeZone(self::TIMEZONE);
        $window = ['timeStart' => (new DateTime('@' . $now))->setTimezone($tz)->format('c'), 'timeEnd' => (new DateTime('@' . ($now + 8 * 3600)))->setTimezone($tz)->format('c')];
        foreach (['departures', 'arrivals'] as $endpoint) {
            $json = $this->board($endpoint, $eva, $window);
            foreach ($json === null ? [] : self::decode($json)['stopPlaces'] ?? [] as $stopPlace) {
                if (($stopPlace['evaNumber'] ?? '') === $eva) {
                    return ['eva' => $eva, 'name' => $stopPlace['localizedNames'][0]['name'] ?? $eva];
                }
            }
        }
        return null;
    }

    // the window filters by the best known time, so delayed trains planned before now are included
    public function fetchTrips(string $eva, int $now, int $ahead = 1): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $end = (new DateTime('@' . $now))->setTimezone($tz);
        $end->setTime((int) $end->format('H'), 0);
        $end->modify('+' . ($ahead + 1) . ' hour');
        $window = ['timeStart' => (new DateTime('@' . $now))->setTimezone($tz)->format('c'), 'timeEnd' => $end->format('c')];
        $departures = $this->board('departures', $eva, $window);
        $arrivals = $this->board('arrivals', $eva, $window);
        // one empty board is normal (a terminus has no departures), none at all means a wrong station
        if ($departures === null && $arrivals === null) {
            throw new ApiException("DB API has no board for station $eva");
        }
        $trips = self::mergeBoards(
            $departures === null ? [] : self::parseBoard($departures, 'dep'),
            $arrivals === null ? [] : self::parseBoard($arrivals, 'arr')
        );
        return ['trips' => $trips, 'realtime' => true];
    }

    // journeyId => Trip with only $part ('dep' or 'arr') set
    public static function parseBoard(string $json, string $part): array
    {
        $data = self::decode($json);
        $names = [];
        foreach ($data['stopPlaces'] ?? [] as $stopPlace) {
            $names[$stopPlace['evaNumber']] = $stopPlace['localizedNames'][0]['name'] ?? $stopPlace['evaNumber'];
        }
        $messages = self::messageIndex($data['messages'] ?? []);
        $trips = [];
        foreach ($data[$part === 'dep' ? 'departures' : 'arrivals'] ?? [] as $entry) {
            $transport = $entry['transport'];
            $journey = $entry['journeyInfo'];
            $name = fn (string $eva) => $names[$eva] ?? $eva;
            $via = array_map(fn (array $stop) => $name($stop['evaNumber']), $transport['via'] ?? []);
            $path = $part === 'dep'
                ? array_merge($via, [$name($journey['destination']['evaNumber'])])
                : array_merge([$name($journey['origin']['evaNumber'])], $via);
            $selected = ['messages' => [], 'extraMessages' => []];
            foreach ($entry['messages'] ?? [] as $id) {
                if (isset($messages[$id])) {
                    $selected[$messages[$id][0]][] = $messages[$id][1];
                }
            }
            $category = (string) ($transport['category'] ?? '');
            $number = (string) ($transport['journeyNumber'] ?? '');
            $trip = [
                'id'            => $entry['journeyId'],
                'line'          => $transport['line']['name'] ?? trim("$category $number"),
                'category'      => $category,
                'number'        => $number,
                'type'          => (string) ($transport['type'] ?? ''),
                'dep'           => null,
                'arr'           => null,
                'messages'      => array_values(array_unique($selected['messages'])),
                'extraMessages' => array_values(array_unique($selected['extraMessages'])),
            ];
            $trip[$part] = [
                'planned'         => self::time($entry['timeSchedule']),
                'changed'         => ($entry['timeType'] ?? 'SCHEDULE') === 'SCHEDULE' ? null : self::time($entry['time']),
                'platform'        => (string) ($entry['platformSchedule']['name'] ?? ''),
                'changedPlatform' => isset($entry['platform']['name']) ? (string) $entry['platform']['name'] : null,
                'path'            => array_values(array_unique($path)),
                'cancelled'       => ($entry['cancelled'] ?? false) || ($journey['journeyCancelled'] ?? false),
            ];
            $trips[$trip['id']] = $trip;
        }
        return $trips;
    }

    // one trip per journey with departure and arrival
    public static function mergeBoards(array $departures, array $arrivals): array
    {
        foreach ($arrivals as $id => $arrival) {
            if (!isset($departures[$id])) {
                $departures[$id] = $arrival;
                continue;
            }
            $departures[$id]['arr'] = $arrival['arr'];
            foreach (['messages', 'extraMessages'] as $key) {
                $departures[$id][$key] = array_values(array_unique(array_merge($departures[$id][$key], $arrival[$key])));
            }
        }
        return array_values($departures);
    }

    // messageID => ['messages' | 'extraMessages', text]; attributes and infrastructure notes are left out
    private static function messageIndex(array $messages): array
    {
        $index = [];
        $add = function (array $message, string $target) use (&$index) {
            $text = trim((string) ($message['descriptions'][0]['text'] ?? ''));
            if ($text !== '') {
                $index[$message['messageID']] = [$target, $text];
            }
        };
        foreach ($messages['risCauseCodes'] ?? [] as $m) {
            if (($m['code'] ?? '') !== '00') {
                $add($m, 'messages');
            }
        }
        foreach ($messages['disruptions'] ?? [] as $m) {
            $add($m, 'messages');
        }
        foreach ($messages['risQualityDeviations'] ?? [] as $m) {
            $add($m, 'extraMessages');
        }
        foreach ($messages['notes'] ?? [] as $m) {
            if (isset($m['code']) && !isset($m['disruptionID'])) {
                $add($m, 'extraMessages');
            }
        }
        return $index;
    }

    private static function time(string $value): int
    {
        return (new DateTime($value))->getTimestamp();
    }

    private static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ApiException('Invalid JSON from the DB API');
        }
        return $data;
    }

    // body of one board; null for 404
    private function board(string $endpoint, string $eva, array $window): ?string
    {
        $body = json_encode([
            'stopPlaceOrStation'         => ['evaNumbers' => [$eva], 'boardConfigurations' => ['NONE']],
            'timeWindow'                 => $window,
            'maxViaStops'                => 100,
            'maxEntriesPerTransportType' => 50,
            'languages'                  => ['deu'],
        ]);
        return $this->request(self::BASE_URL . $endpoint, 'application/json', $body);
    }

    // body; null for 404
    private function request(string $url, string $accept, ?string $body = null): ?string
    {
        $headers = ["DB-Client-Id: {$this->clientId}", "DB-Api-Key: {$this->apiKey}", "Accept: $accept"];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $response = ($this->http)($url, $headers, $body);
        $status = (int) $response['status'];
        if ($status === 401 || $status === 403) {
            throw new ApiAuthException("DB API rejected the credentials (HTTP $status)");
        }
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException("DB API request failed (HTTP $status): " . parse_url($url, PHP_URL_PATH));
        }
        return (string) $response['body'];
    }

    private static function curl(string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = $response === false ? 0 : curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $response];
    }
}
