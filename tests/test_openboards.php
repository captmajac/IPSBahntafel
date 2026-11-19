<?php

// OpenBoardsClient: departure and arrival boards, requests, errors, station search

function obTrips(): array
{
    return OpenBoardsClient::mergeBoards(
        OpenBoardsClient::parseBoard(fixture('ob_departures_wohnort.json'), 'dep'),
        OpenBoardsClient::parseBoard(fixture('ob_arrivals_wohnort.json'), 'arr')
    );
}

// one board entry of a small hand-made response, for fields the fixtures always have
function obEntry(array $entry, string $part = 'dep', array $stopPlaces = []): array
{
    $entry += [
        'journeyId'    => 'x',
        'evaNumber'    => '8099001',
        'timeSchedule' => '2026-11-04T18:10:00+01:00',
        'time'         => '2026-11-04T18:10:00+01:00',
        'timeType'     => 'SCHEDULE',
        'journeyInfo'  => ['origin' => ['evaNumber' => '8099001'], 'destination' => ['evaNumber' => '8099002']],
        'transport'    => ['category' => 'DB', 'journeyNumber' => 2000, 'type' => 'INTERCITY_TRAIN'],
    ];
    $places = array_map(fn ($eva, $name) => ['evaNumber' => $eva, 'localizedNames' => [['language' => 'deu', 'name' => $name]]], array_keys($stopPlaces), $stopPlaces);
    $json = json_encode([$part === 'dep' ? 'departures' : 'arrivals' => [$entry], 'stopPlaces' => $places]);
    return OpenBoardsClient::parseBoard($json, $part)[$entry['journeyId']];
}

function obError(callable $http): ?Throwable
{
    try {
        (new OpenBoardsClient('id', 'key', $http))->fetchTrips('8099001', ts('2611041800'));
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

$tests['OpenBoards: departure fields'] = function () {
    $re3 = tripById(obTrips(), '-7004-2611041819-4');
    check($re3['dep']['planned'] === ts('2611041819') && $re3['dep']['changed'] === ts('2611041831'), 'times');
    check($re3['dep']['platform'] === '2' && $re3['dep']['changedPlatform'] === '3', 'platform');
    check($re3['line'] === 'RE3' && $re3['category'] === 'NVA' && $re3['number'] === '83419' && $re3['type'] === 'REGIONAL_TRAIN', 'line');
    check($re3['messages'] === ['Verspätung eines vorausfahrenden Zuges'], json_encode($re3['messages']));
};

$tests['OpenBoards: on time is not delayed'] = function () {
    $s1 = tripById(obTrips(), '-7003-2611041806-6');
    check($s1['dep']['changed'] === $s1['dep']['planned'], 'realtime equals the schedule');
    check(BoardBuilder::build([$s1], ts('2611041800'), settings())[0]['delayed'] === false, 'not delayed');
    check(tripById(obTrips(), '-7001-2611041801-4')['dep']['changed'] === null, 'schedule only: no change');
};

$tests['OpenBoards: cancelled stop and cancelled journey'] = function () {
    $re7 = tripById(obTrips(), '-7002-2611041806-1');
    check($re7['dep']['cancelled'] === true && $re7['arr'] === null, 'RE7 starts here and is cancelled');
    check($re7['messages'] === ['Bauarbeiten zwischen WohnortBahnhof und Löwenstadt'], json_encode($re7['messages']));
    $journey = obEntry(['journeyInfo' => ['origin' => ['evaNumber' => '8099002'], 'destination' => ['evaNumber' => '8099001'], 'journeyCancelled' => true]], 'arr');
    check($journey['arr']['cancelled'] === true, 'whole journey cancelled');
};

$tests['OpenBoards: path order and names'] = function () {
    $trips = obTrips();
    $re6 = tripById($trips, '-7008-2611041854-3');
    check(end($re6['dep']['path']) === 'Grenzstadt' && in_array('Großstadt Hbf', $re6['dep']['path'], true), 'dep path');
    $re3 = tripById($trips, '-7004-2611041819-4');
    check($re3['dep']['path'] === ['Feldheim', 'Waldheim', 'Kreisstadt'], json_encode($re3['dep']['path']));
    check($re3['arr']['path'] === ['Großstadt Hbf'], json_encode($re3['arr']['path']));
    $unknown = obEntry(['journeyInfo' => ['origin' => ['evaNumber' => '8099099'], 'destination' => ['evaNumber' => '8099001']],
        'transport' => ['category' => 'NVB', 'journeyNumber' => 1, 'via' => [['evaNumber' => '8099002', 'displayPriority' => 1]]]], 'arr', ['8099002' => 'Großstadt Hbf']);
    check($unknown['arr']['path'] === ['8099099', 'Großstadt Hbf'], 'unknown origin keeps its EVA');
};

$tests['OpenBoards: messages and extra messages'] = function () {
    $re6 = tripById(obTrips(), '-7008-2611041854-3');
    check($re6['messages'] === [], json_encode($re6['messages']));
    check($re6['extraMessages'] === ['Ein Wagen fehlt', 'Heute nur einteilig.'], json_encode($re6['extraMessages']));
};

$tests['OpenBoards: entry without line, platform, via and messages'] = function () {
    $ic = obEntry([]);
    check($ic['line'] === 'DB 2000', $ic['line']);
    check($ic['dep']['platform'] === '' && $ic['dep']['changedPlatform'] === null, 'platform');
    check($ic['dep']['path'] === ['8099002'] && $ic['messages'] === [] && $ic['extraMessages'] === [], 'path and messages');
    check(tripById(obTrips(), '-7102-2611041912-1')['line'] === 'IC 2000', 'IC of the fixtures has no line');
};

$tests['OpenBoards: departures and arrivals merge by journey'] = function () {
    $result = (new OpenBoardsClient('id', 'key', openBoardsHttp()))->fetchTrips('8099001', ts('2611041800'));
    check($result['realtime'] === true, 'realtime');
    $ids = array_column($result['trips'], 'id');
    check(count($ids) === count(array_unique($ids)) && count($ids) === 15, json_encode($ids));
    $re3 = tripById($result['trips'], '-7004-2611041819-4');
    check($re3['dep'] !== null && $re3['arr'] !== null, 'RE3 both');
};

$tests['OpenBoards: request'] = function () {
    (new OpenBoardsClient('id', 'key', openBoardsHttp()))->fetchTrips('8099001', ts('2611041800'));
    check(count(TestRegistry::$calls) === 2, 'two calls');
    check(str_ends_with(TestRegistry::$calls[0][0], '/open-boards/v1/departures') && str_ends_with(TestRegistry::$calls[1][0], '/open-boards/v1/arrivals'), 'urls');
    foreach (['DB-Client-Id: id', 'DB-Api-Key: key', 'Content-Type: application/json'] as $header) {
        check(in_array($header, TestRegistry::$calls[0][1], true), $header);
    }
    $body = json_decode(TestRegistry::$calls[0][2], true);
    check($body['stopPlaceOrStation'] === ['evaNumbers' => ['8099001'], 'boardConfigurations' => ['NONE']], 'station');
    check($body['maxViaStops'] === 100 && $body['maxEntriesPerTransportType'] === 50 && $body['languages'] === ['deu'], 'limits');
    check($body['timeWindow'] === ['timeStart' => '2026-11-04T18:00:00+01:00', 'timeEnd' => '2026-11-04T20:00:00+01:00'], json_encode($body['timeWindow']));
};

$tests['OpenBoards: errors'] = function () {
    check(obError(fakeHttp(['/departures' => [401, '']])) instanceof ApiAuthException, '401');
    $e = obError(fakeHttp(['/departures' => [200, fixture('ob_departures_wohnort.json')], '/arrivals' => [500, '']]));
    check($e instanceof ApiException && !$e instanceof ApiAuthException, 'one board fails');
    $result = (new OpenBoardsClient('id', 'key', fakeHttp(['/departures' => [200, fixture('ob_departures_wohnort.json')]])))->fetchTrips('8099001', ts('2611041800'));
    check(count($result['trips']) === 14, 'one board missing is empty');
    check(obError(fakeHttp([])) instanceof ApiException, 'no board at all');
    check(obError(fakeHttp(['/departures' => [200, 'nope'], '/arrivals' => [200, 'nope']])) instanceof ApiException, 'invalid JSON');
};

$tests['OpenStation: search gives eva and name'] = function () {
    $stations = (new OpenBoardsClient('id', 'key', openBoardsHttp()))->searchStations('WohnortBahnhof');
    check($stations === [['eva' => '8099001', 'name' => 'WohnortBahnhof'], ['eva' => '8099010', 'name' => 'WohnortBahnhof Nord']], json_encode($stations));
    check(str_ends_with(TestRegistry::$calls[0][0], '/open-station/v1/netex?stopPlaceName=WohnortBahnhof&limit=20'), TestRegistry::$calls[0][0]);
    check(TestRegistry::$calls[0][2] === null, 'GET');
    check((new OpenBoardsClient('id', 'key', openBoardsHttp()))->searchStations('Nirgendwo') === [], 'nothing found');
};

$tests['OpenStation: without subscription'] = function () {
    try {
        (new OpenBoardsClient('id', 'key', fakeHttp(['limit=20' => [401, '']])))->searchStations('WohnortBahnhof');
        check(false, 'no exception');
    } catch (ApiAuthException $e) {
    }
};

$tests['OpenBoards: station by eva'] = function () {
    $client = new OpenBoardsClient('id', 'key', openBoardsHttp());
    check($client->stationByEva('8099001', ts('2611041800')) === ['eva' => '8099001', 'name' => 'WohnortBahnhof'], 'found');
    // the longest window the API allows: a quiet station may have no train in the next half hour
    check(json_decode(TestRegistry::$calls[0][2], true)['timeWindow'] === ['timeStart' => '2026-11-04T18:00:00+01:00', 'timeEnd' => '2026-11-05T02:00:00+01:00'], 'window');
    check((new OpenBoardsClient('id', 'key', fakeHttp([])))->stationByEva('8099999') === null, 'unknown');
};

$tests['OpenBoards: station by eva from the arrivals when nothing departs'] = function () {
    $client = new OpenBoardsClient('id', 'key', fakeHttp(['/arrivals' => [200, fixture('ob_arrivals_wohnort.json')]]));
    check($client->stationByEva('8099001', ts('2611041800')) === ['eva' => '8099001', 'name' => 'WohnortBahnhof'], 'terminus');
};
