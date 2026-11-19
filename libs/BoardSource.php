<?php

declare(strict_types=1);

class ApiException extends Exception {}

class ApiAuthException extends ApiException {}

// Source of the trips of one station: DB OpenBoards or DB Timetables.
// Trip: ['id', 'line', 'category', 'number', 'type' (OpenBoards transport type or ''),
//        'dep' => ?Stop, 'arr' => ?Stop, 'messages' => string[] (delay, cancellation, disruption),
//        'extraMessages' => string[] (quality and notes, only shown on request)]
// Stop: ['planned' => int, 'changed' => ?int, 'platform' => string, 'changedPlatform' => ?string,
//        'path' => string[] (dep: following stations up to the destination, arr: origin and previous stations),
//        'cancelled' => bool]
// HTTP callable: fn (string $url, array $headers, ?string $body = null): array{status: int, body: string}; null body is GET
interface BoardSource
{
    // [['eva' => string, 'name' => string], ...]; unknown pattern gives []
    public function searchStations(string $pattern): array;

    // ['eva' => string, 'name' => string] or null for an unknown EVA number
    public function stationByEva(string $eva): ?array;

    // ['trips' => Trip[], 'realtime' => bool] from now up to $ahead hours after the current one
    public function fetchTrips(string $eva, int $now, int $ahead = 1): array;
}
