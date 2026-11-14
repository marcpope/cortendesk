<?php

use App\Services\FleetDiagnostics;
use Illuminate\Support\Facades\Cache;

/*
 * The scheduler heartbeat is the only signal the diagnostics page has that
 * cron is actually running, so a missing or malformed one must read as stale
 * rather than as healthy.
 */

it('scheduler heartbeat command updates the cache', function () {
    Cache::forget('cortendesk:diagnostics:scheduler-heartbeat');

    $this->artisan('cortendesk:diagnostics-heartbeat')->assertExitCode(0);

    $this->assertNotNull(Cache::get('cortendesk:diagnostics:scheduler-heartbeat'));
});

it('invalid scheduler heartbeat is treated as stale', function () {
    Cache::put('cortendesk:diagnostics:scheduler-heartbeat', 'not-a-date');

    $report = app(FleetDiagnostics::class)->report();

    $this->assertFalse($report['scheduler']['ok']);
    $this->assertNull($report['scheduler']['last_seen_at']);
});
