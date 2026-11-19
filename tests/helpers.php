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

// WohnortBahnhof responses of the fixtures (18 and 19 o'clock, changes, station search)
function wohnortHttp(): callable
{
    return fakeHttp(openBoardsRoutes() + [
        '/plan/8099001/261104/18' => [200, fixture('plan_wohnort_18.xml')],
        '/plan/8099001/261104/19' => [200, fixture('plan_wohnort_19.xml')],
        '/fchg/8099001'           => [200, fixture('fchg_wohnort.xml')],
        '/station/WohnortBahnhof'         => [200, fixture('station_wohnort.xml')],
        '/station/8099001'        => [200, fixture('station_wohnort.xml')],
    ]);
}

// Module with test clock and fake HTTP (TestRegistry::$http)
class TestBahntafel extends Bahntafel
{
    protected function now(): int { return TestRegistry::$now; }

    protected function http(): ?callable { return TestRegistry::$http; }

    public function testPayload(): array { return $this->payload(); }
}

const WOHNORT = '{"eva":"8099001","name":"WohnortBahnhof"}';

// Instance 2, configured for WohnortBahnhof with Timetables unless $props say otherwise
function makeBoard(array $props = []): TestBahntafel
{
    TestRegistry::$http ??= wohnortHttp();
    $m = new TestBahntafel(2);
    TestRegistry::$objects[2] ??= ['name' => 'Bahntafel', 'type' => 1];  // as Symcon names a new instance
    $m->Create();
    $m->properties = array_merge($m->properties, ['ClientId' => 'id', 'ApiKey' => 'key', 'Station' => WOHNORT, 'Api' => 2], $props);
    $m->ApplyChanges();
    return $m;
}

// Instance 2 as it was before the module update: it has fetched with Timetables, no DefaultApi yet
function legacyBoard(array $props = []): TestBahntafel
{
    TestRegistry::$http ??= wohnortHttp();
    $m = new TestBahntafel(2);
    TestRegistry::$objects[2] ??= ['name' => 'Bahntafel', 'type' => 1];
    $m->attributes = ['FetchedAt' => TestRegistry::$now - 3600, 'TripsEva' => '8099001', 'Trips' => '[]'];
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
