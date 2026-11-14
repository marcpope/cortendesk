<?php

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function makeOldAndNewLogs(): void
{
    AuditConnection::create(['rustdesk_id' => '111', 'action' => 'new', 'conn_id' => 1]);
    AlarmLog::create(['rustdesk_id' => '111', 'uuid' => 'u', 'typ' => 0, 'info' => '{}']);
    DB::table('audit_connections')->update(['created_at' => now()->subDays(400)]);
    DB::table('alarm_logs')->update(['created_at' => now()->subDays(400)]);

    AuditConnection::create(['rustdesk_id' => '222', 'action' => 'new', 'conn_id' => 2]);
    AlarmLog::create(['rustdesk_id' => '222', 'uuid' => 'u', 'typ' => 0, 'info' => '{}']);
}

it('prunes rows older than the retention window', function () {
    makeOldAndNewLogs();
    Setting::put('log_retention_days', '365');

    Artisan::call('cortendesk:prune-logs');

    expect(DB::table('audit_connections')->count())->toBe(1)
        ->and(DB::table('alarm_logs')->count())->toBe(1)
        ->and(AuditConnection::first()->rustdesk_id)->toBe('222');
});

it('does nothing when retention is disabled', function () {
    makeOldAndNewLogs();
    Setting::put('log_retention_days', '0');

    Artisan::call('cortendesk:prune-logs');

    expect(DB::table('audit_connections')->count())->toBe(2)
        ->and(Artisan::output())->toContain('disabled');
});

it('honors the --days override', function () {
    makeOldAndNewLogs();
    Setting::put('log_retention_days', '0'); // disabled in settings…

    Artisan::call('cortendesk:prune-logs', ['--days' => 30]); // …but overridden

    expect(DB::table('audit_connections')->count())->toBe(1);
});

it('registers the daily schedule', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
    $match = $events->first(fn ($e) => str_contains($e->command ?? '', 'cortendesk:prune-logs'));
    expect($match)->not->toBeNull();
});
