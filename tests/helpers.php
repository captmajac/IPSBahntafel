<?php

// Shared test helpers

function fixture(string $name): string
{
    return file_get_contents(__DIR__ . "/fixtures/$name");
}

// fake HTTP: path suffix => [status, body]; records the calls
function fakeHttp(array $responses): callable
{
    return function (string $url, array $headers, ?string $body = null) use ($responses): array {
        TestRegistry::$calls[] = [$url, $headers, $body];
        foreach ($responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                return ['status' => $response[0], 'body' => $response[1]];
            }
        }
        return ['status' => 404, 'body' => ''];
    };
}

function ts(string $yymmddhhii): int
{
    return DateTime::createFromFormat('ymdHi', $yymmddhhii, new DateTimeZone('Europe/Berlin'))->getTimestamp();
}

function tripById(array $trips, string $id): array
{
    foreach ($trips as $trip) {
        if ($trip['id'] === $id) {
            return $trip;
        }
    }
    throw new CheckFailed("trip $id missing");
}

// WohnortBahnhof responses of the fixtures (boards 18 to 20 o'clock, station search)
function wohnortHttp(): callable
{
    return fakeHttp(openBoardsRoutes());
}

// the fixtures with one more disruption text on the first departure (RE3 18:01)
function withMessage(string $text): callable
{
    $board = json_decode(fixture('ob_departures_wohnort.json'), true);
    $board['messages']['disruptions'][] = ['messageID' => 999, 'disruptionID' => 'd-x', 'disruptionCommunicationID' => 'dc-x', 'effect' => 'UNKNOWN_EFFECT',
        'descriptions' => [['language' => 'deu', 'text' => $text]]];
    $board['departures'][0]['messages'][] = 999;
    return fakeHttp(['/open-boards/v1/departures' => [200, json_encode($board)]] + openBoardsRoutes());
}

// Module with test clock and fake HTTP (TestRegistry::$http)
class TestBahntafel extends Bahntafel
{
    protected function now(): int { return TestRegistry::$now; }

    protected function http(): ?callable { return TestRegistry::$http; }

    public function testPayload(): array { return $this->payload(); }
}

const WOHNORT = '{"eva":"8099001","name":"WohnortBahnhof"}';

// Instance 2, configured for WohnortBahnhof unless $props say otherwise
function makeBoard(array $props = []): TestBahntafel
{
    TestRegistry::$http ??= wohnortHttp();
    $m = new TestBahntafel(2);
    TestRegistry::$objects[2] ??= ['name' => 'Bahntafel', 'type' => 1];  // as Symcon names a new instance
    $m->Create();
    $m->properties = array_merge($m->properties, ['ClientId' => 'id', 'ApiKey' => 'key', 'Station' => WOHNORT], $props);
    $m->ApplyChanges();
    return $m;
}

// URLs requested so far contain $part
function requested(string $part): bool
{
    return TestRegistry::$calls !== [] && array_filter(TestRegistry::$calls, fn ($c) => str_contains($c[0], $part)) === TestRegistry::$calls;
}

// echo output of an action (popup in the configuration form)
function echoed(callable $action): string
{
    ob_start();
    $action();
    return (string) ob_get_clean();
}

// WohnortBahnhof responses of the OpenBoards fixtures (04.11.2026 18 to 20 o'clock)
function openBoardsRoutes(): array
{
    return [
        '/open-boards/v1/departures' => [200, fixture('ob_departures_wohnort.json')],
        '/open-boards/v1/arrivals'   => [200, fixture('ob_arrivals_wohnort.json')],
        '/open-station/v1/netex?stopPlaceName=WohnortBahnhof&limit=20' => [200, fixture('openstation_wohnort.xml')],
    ];
}

function openBoardsHttp(): callable
{
    return fakeHttp(openBoardsRoutes());
}
