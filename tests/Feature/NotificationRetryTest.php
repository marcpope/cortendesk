<?php

use App\Livewire\SettingsPage;
use App\Models\Device;
use App\Models\DevicePresenceNotificationState;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppriseNotifications;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Notification retries (issue #87). A timeout on the "back online" alert was
 * final; now temporary failures are retried by the scheduler, bounded in
 * count, age and run time, and dropped when a newer event makes them stale.
 */

/** @param list<string> $events */
function retryApprise(array $events): void
{
    Cache::clear();
    Setting::put('online_window', '60');
    Setting::put('apprise_enabled', '1');
    Setting::put('apprise_endpoint', Crypt::encryptString('https://apprise.test'));
    Setting::put('apprise_delivery_mode', 'config');
    Setting::put('apprise_config_key', Crypt::encryptString('alerts'));
    Setting::put('apprise_cooldown_minutes', '0');
    Setting::put('apprise_offline_grace_minutes', '0');

    foreach (array_keys(AppriseNotifications::EVENTS) as $event) {
        $suffix = str_replace('.', '_', $event);
        Setting::put('apprise_event_'.$suffix, in_array($suffix, $events, true) ? '1' : '0');
    }
}

/**
 * One fake for the whole test: Http::fake() stubs stack and the first match
 * wins, so switching responses means changing what this one returns.
 * 'timeout' fails the connection, an int is an HTTP status, 'ok' is 200.
 *
 * @param  list<string|int>  $plan  responses in order; the last one repeats
 */
function apprisePlan(array $plan): void
{
    $GLOBALS['apprisePlan'] = $plan;
}

/** @return list<array{timeout: int, connect: int, body: string, type: string}> */
function appriseCalls(): array
{
    return $GLOBALS['appriseCalls'];
}

function fakeApprise(): void
{
    $GLOBALS['appriseCalls'] = [];
    $GLOBALS['apprisePlan'] = ['ok'];

    Http::fake(function (Request $request, array $options) {
        $GLOBALS['appriseCalls'][] = [
            'timeout' => $options['timeout'],
            'connect' => $options['connect_timeout'],
            'body' => $request['body'],
            'type' => $request['type'],
        ];
        $next = count($GLOBALS['apprisePlan']) > 1 ? array_shift($GLOBALS['apprisePlan']) : $GLOBALS['apprisePlan'][0];

        return match (true) {
            $next === 'timeout' => Http::failedConnection('cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received')($request),
            is_int($next) => Http::response(['error' => 'nope'], $next),
            default => Http::response(['result' => 'ok']),
        };
    });
}

function runSweep(): void
{
    test()->artisan('cortendesk:check-device-notifications')->assertSuccessful();
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 12:00:10');
    fakeApprise();
});

it('retries a timed-out delivery from the scheduler until it is sent', function () {
    retryApprise(['security_alarm']);
    apprisePlan(['timeout', 'ok']);

    $first = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Device 1 reported an alarm.', 'alarm:1');

    expect($first->status)->toBe('failed')
        ->and($first->attempts)->toBe(1)
        ->and($first->next_retry_at?->toDateTimeString())->toBe('2026-10-07 12:01:00')
        ->and($first->error)->toContain('cURL error 28');

    runSweep();
    expect(appriseCalls())->toHaveCount(1);

    Carbon::setTestNow('2026-10-07 12:01:00');
    runSweep();

    $row = $first->fresh();
    expect($row->status)->toBe('sent')
        ->and($row->attempts)->toBe(2)
        ->and($row->next_retry_at)->toBeNull()
        ->and($row->error)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and(array_column(appriseCalls(), 'timeout'))->toBe([3, 15])
        ->and(appriseCalls()[1]['body'])->toBe('Device 1 reported an alarm.')
        ->and(appriseCalls()[1]['type'])->toBe('failure')
        ->and(NotificationDelivery::query()->count())->toBe(1);
});

it('retries HTTP 429, 424 and 5xx but not other 4xx', function (int $status, bool $retried) {
    retryApprise(['security_alarm']);
    apprisePlan([$status]);

    $row = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:'.$status);

    expect($row->status)->toBe('failed')
        ->and($row->next_retry_at !== null)->toBe($retried)
        ->and($row->body !== null)->toBe($retried);

    Carbon::setTestNow('2026-10-07 12:02:00');
    runSweep();

    expect(appriseCalls())->toHaveCount($retried ? 2 : 1)
        ->and($row->fresh()->attempts)->toBe($retried ? 2 : 1);
})->with([
    '400' => [400, false],
    '401' => [401, false],
    '404' => [404, false],
    '429' => [429, true],
    '424' => [424, true],
    '500' => [500, true],
    '503' => [503, true],
]);

it('stops after three attempts', function () {
    retryApprise(['security_alarm']);
    apprisePlan([502]);

    $row = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:9');

    Carbon::setTestNow('2026-10-07 12:01:00');
    runSweep();
    expect($row->fresh()->attempts)->toBe(2)
        ->and($row->fresh()->next_retry_at->toDateTimeString())->toBe('2026-10-07 12:06:00');

    Carbon::setTestNow('2026-10-07 12:06:00');
    runSweep();
    Carbon::setTestNow('2026-10-07 12:20:00');
    runSweep();

    $row = $row->fresh();
    expect($row->attempts)->toBe(3)
        ->and($row->status)->toBe('failed')
        ->and($row->next_retry_at)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and(appriseCalls())->toHaveCount(3);
});

it('does not retry a failure older than thirty minutes', function () {
    retryApprise(['security_alarm']);
    apprisePlan([500]);

    $row = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:2');

    Carbon::setTestNow('2026-10-07 12:31:00');
    runSweep();

    expect(appriseCalls())->toHaveCount(1)
        ->and($row->fresh()->next_retry_at)->toBeNull()
        ->and($row->fresh()->body)->toBeNull();
});

it('does not retry the settings test', function () {
    retryApprise([]);
    apprisePlan(['timeout']);

    $row = app(AppriseNotifications::class)->test();

    expect($row->status)->toBe('failed')->and($row->next_retry_at)->toBeNull();
});

it('does not retry an event that was switched off', function () {
    retryApprise(['security_alarm']);
    apprisePlan([500]);
    $row = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:3');

    Setting::put('apprise_event_security_alarm', '0');
    Carbon::setTestNow('2026-10-07 12:01:00');
    runSweep();

    expect(appriseCalls())->toHaveCount(1)
        ->and($row->fresh()->status)->toBe('failed')
        ->and($row->fresh()->next_retry_at)->toBeNull();
});

it('drops an offline retry once the device is back online', function () {
    retryApprise(['device_offline', 'device_online']);
    apprisePlan(['timeout', 'ok']);
    $device = Device::create([
        'rustdesk_id' => '111222333',
        'uuid' => 'u-1',
        'hostname' => 'Workstation',
        'status' => Device::STATUS_ACTIVE,
        'last_online_at' => now()->subMinutes(5),
    ]);

    runSweep();
    $offline = NotificationDelivery::query()->where('event', 'device.offline')->sole();
    expect($offline->retryPending())->toBeTrue();

    Carbon::setTestNow('2026-10-07 12:01:00');
    $device->update(['last_online_at' => now()]);
    runSweep();

    expect(appriseCalls())->toHaveCount(1)
        ->and($offline->fresh()->status)->toBe('superseded')
        ->and($offline->fresh()->next_retry_at)->toBeNull()
        ->and($offline->fresh()->body)->toBeNull();
});

it('drops a retry when a newer delivery for the same subject exists', function () {
    retryApprise(['console_login_failed']);
    apprisePlan([500, 500, 'ok']);

    $notifications = app(AppriseNotifications::class);
    $old = $notifications->send('console.login_failed', 'Failed console login', 'First.', 'web-login:abc');
    Carbon::setTestNow('2026-10-07 12:00:40');
    $new = $notifications->send('console.login_failed', 'Failed console login', 'Second.', 'web-login:abc');

    Carbon::setTestNow('2026-10-07 12:01:00');
    runSweep();

    expect($old->fresh()->status)->toBe('superseded')
        ->and($new->fresh()->status)->toBe('sent')
        ->and($new->fresh()->attempts)->toBe(2)
        ->and(array_column(appriseCalls(), 'body'))->toBe(['First.', 'Second.', 'Second.']);
});

it('retries a recovery that timed out on the heartbeat path', function () {
    retryApprise(['device_offline', 'device_online']);
    $device = Device::create([
        'rustdesk_id' => '444555666',
        'uuid' => 'u-4',
        'hostname' => 'Laptop',
        'status' => Device::STATUS_ACTIVE,
        'last_online_at' => now()->subMinutes(5),
    ]);
    runSweep();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();

    // The heartbeat consumes the recovery marker and sends after the response.
    apprisePlan(['timeout', 'ok']);
    $this->postJson('/api/heartbeat', ['id' => '444555666', 'uuid' => 'u-4'])->assertOk();

    $online = NotificationDelivery::query()->where('event', 'device.online')->sole();
    expect($online->retryPending())->toBeTrue()
        ->and(appriseCalls()[1]['timeout'])->toBe(3);

    Carbon::setTestNow('2026-10-07 12:01:00');
    $device->update(['last_online_at' => now()]);
    runSweep();

    expect($online->fresh()->status)->toBe('sent')
        ->and($online->fresh()->attempts)->toBe(2)
        ->and(appriseCalls()[2]['type'])->toBe('success');
});

it('records the outage when an offline alert gets through on a retry', function () {
    retryApprise(['device_offline', 'device_online']);
    Setting::put('apprise_cooldown_minutes', '15');
    apprisePlan(['timeout', 'ok']);
    $device = Device::create([
        'rustdesk_id' => '777888999',
        'uuid' => 'u-7',
        'hostname' => 'Server',
        'status' => Device::STATUS_ACTIVE,
        'last_online_at' => now()->subMinutes(5),
    ]);

    runSweep();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();

    Carbon::setTestNow('2026-10-07 12:01:00');
    runSweep();

    expect(NotificationDelivery::query()->where('event', 'device.offline')->sole()->status)->toBe('sent')
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();

    // Past the cooldown the sweep must not send the offline alert again.
    Carbon::setTestNow('2026-10-07 12:20:00');
    runSweep();
    expect(NotificationDelivery::query()->where('event', 'device.offline')->count())->toBe(1);

    $device->update(['last_online_at' => now()]);
    runSweep();
    expect(NotificationDelivery::query()->where('event', 'device.online')->sole()->status)->toBe('sent');
});

it('keeps the three-second timeout on request paths and uses the longer one in the scheduler', function () {
    retryApprise(['security_alarm', 'device_offline']);

    app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:5');

    Device::create([
        'rustdesk_id' => '123123123',
        'uuid' => 'u-123',
        'status' => Device::STATUS_ACTIVE,
        'last_online_at' => now()->subMinutes(5),
    ]);
    runSweep();

    config(['cortendesk.notifications.scheduled_timeout' => 25]);
    app(AppriseNotifications::class)->forScheduler()->send('security.alarm', 'Security alarm', 'Body', 'alarm:6');

    expect(array_map(fn ($call) => [$call['timeout'], $call['connect']], appriseCalls()))
        ->toBe([[3, 1], [15, 3], [25, 3]]);
});

it('falls back to the short timeout for the rest of a scheduled run after one stall', function () {
    retryApprise(['device_offline']);
    apprisePlan(['timeout']);
    foreach (['900000001', '900000002', '900000003'] as $id) {
        Device::create(['rustdesk_id' => $id, 'uuid' => 'u-'.$id, 'status' => Device::STATUS_ACTIVE, 'last_online_at' => now()->subMinutes(5)]);
    }

    runSweep();

    expect(array_column(appriseCalls(), 'timeout'))->toBe([15, 3, 3]);
});

it('caps a retry run and stops it at the first connection failure', function () {
    retryApprise(['security_alarm']);
    apprisePlan([500]);
    $notifications = app(AppriseNotifications::class);
    foreach (range(1, 12) as $i) {
        $notifications->send('security.alarm', 'Security alarm', 'Body '.$i, 'alarm:'.$i);
    }

    Carbon::setTestNow('2026-10-07 12:01:00');
    expect($notifications->forScheduler()->retryDue())->toHaveCount(10);

    apprisePlan(['timeout']);
    expect($notifications->forScheduler()->retryDue())->toHaveCount(1)
        ->and(NotificationDelivery::query()->whereNotNull('next_retry_at')->count())->toBe(12);
});

it('shows attempts and pending retries under recent deliveries', function () {
    $admin = User::factory()->admin()->create();
    NotificationDelivery::create(['event' => 'device.online', 'subject' => 'device:1', 'status' => 'failed', 'attempts' => 2, 'next_retry_at' => now()->addMinutes(5), 'title' => 'Device recovered', 'error' => 'cURL error 28: Operation timed out']);
    NotificationDelivery::create(['event' => 'security.alarm', 'subject' => 'alarm:1', 'status' => 'sent', 'attempts' => 3, 'title' => 'Security alarm']);
    NotificationDelivery::create(['event' => 'device.offline', 'subject' => 'device:2', 'status' => 'superseded', 'attempts' => 1, 'title' => 'Device offline']);

    $html = Livewire::actingAs($admin)->test(SettingsPage::class)->html();

    expect($html)->toContain('Attempt 2 of 3 · next retry')
        ->toContain('retrying')
        ->toContain('Sent on attempt 3 of 3')
        ->toContain('retry dropped, a newer event replaced it')
        ->toContain('is retried by the scheduler');
});
