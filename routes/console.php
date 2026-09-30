<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel installs signal handlers to release a task's lock whenever the pcntl
// extension is loaded, even when pcntl_signal is in disable_functions (a
// common hosting-panel default). The call then throws after the lock is taken
// and before anything releases it, so the task never runs again until the
// lock expires (#70). Only ask for the handlers when they can be installed,
// and keep lock expiry short so a lock stranded any other way clears itself.
$releaseOnSignals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');

// Retention: prune old audit/log rows nightly (no-op when retention is 0).
Schedule::command('cortendesk:prune-logs')->dailyAt('04:10');

// Hygiene: dead invitation links and expired device trust (PLAN D1). Separate
// from prune-logs, which no-ops when log retention is 0.
Schedule::command('cortendesk:prune-invitations')->dailyAt('04:20');

// Sessions on devices that have gone silent (issue #10). Frequent, not nightly:
// this drives what the dashboard reports as happening right now.
Schedule::command('cortendesk:close-stale-sessions')->everyFiveMinutes()->withoutOverlapping(30, $releaseOnSignals);

// Presence transitions for configurable Apprise notifications.
Schedule::command('cortendesk:check-device-notifications')->everyMinute()->withoutOverlapping(10, $releaseOnSignals);

// Proves the scheduler is alive, for the diagnostics page.
Schedule::command('cortendesk:diagnostics-heartbeat')->everyMinute()->withoutOverlapping(10, $releaseOnSignals);
