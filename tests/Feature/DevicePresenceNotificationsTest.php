<?php

use App\Console\Commands\CheckDeviceNotifications;
use App\Livewire\SettingsPage;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DevicePresenceNotificationState;
use App\Models\DevicePresenceSnooze;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppriseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Setting::put('online_window', '60');
    Setting::put('apprise_offline_grace_minutes', '10');
    Setting::put('apprise_enabled', '1');
    Setting::put('apprise_event_device_offline', '1');
    Setting::put('apprise_event_device_online', '1');
    Setting::put('apprise_delivery_mode', 'config');
    Setting::put('apprise_endpoint', Crypt::encryptString('https://apprise.test'));
    Setting::put('apprise_config_key', Crypt::encryptString('alerts'));
    Setting::put('apprise_cooldown_minutes', '0');

    Http::fake(['https://apprise.test/notify/alerts' => Http::response([], 200)]);
});

function presenceDevice(array $attributes = []): Device
{
    return Device::query()->create(array_merge([
        'rustdesk_id' => 'device-'.uniqid(),
        'uuid' => 'uuid-'.uniqid(),
        'status' => Device::STATUS_ACTIVE,
        'hostname' => 'Test device',
        'last_online_at' => now()->subMinutes(11),
    ], $attributes));
}

test('offline delivery waits for the configured grace period before recording the outage', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['last_online_at' => now()->subMinutes(9)->subSeconds(60)]);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertNothingSent();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();

    Carbon::setTestNow(now()->addMinutes(2));
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();
});

test('an active group snooze suppresses presence notifications and expires at its configured time', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $group = DeviceGroup::query()->create(['name' => 'Planned maintenance']);
    $device = presenceDevice(['device_group_id' => $group->id]);

    DevicePresenceSnooze::snoozeGroup($group, now()->addHours(2));
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertNothingSent();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();

    Carbon::setTestNow(now()->addHours(2)->addSecond());
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
});

test('recovery is sent only for an outage whose offline delivery succeeded', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $delivered = presenceDevice(['rustdesk_id' => 'delivered-outage']);
    $undelivered = presenceDevice(['rustdesk_id' => 'undelivered-outage']);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();
    expect(DevicePresenceNotificationState::query()->where('device_id', $delivered->id)->value('offline_notified_at'))->not->toBeNull();

    DevicePresenceNotificationState::query()->where('device_id', $undelivered->id)->delete();
    $delivered->update(['last_online_at' => now()]);
    $undelivered->update(['last_online_at' => now()]);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(3);
    expect(DevicePresenceNotificationState::query()->whereIn('device_id', [$delivered->id, $undelivered->id])->exists())->toBeFalse();
});

test('a recovery marker has exactly one atomic consumer', function (): void {
    $device = presenceDevice();
    DevicePresenceNotificationState::query()->create([
        'device_id' => $device->id,
        'offline_notified_at' => now(),
    ]);

    expect(DevicePresenceNotificationState::consumeFor($device))->toBeTrue()
        ->and(DevicePresenceNotificationState::consumeFor($device))->toBeFalse()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

test('only one contender can claim an offline delivery and only its token can mark it delivered', function (): void {
    $device = presenceDevice();

    $firstClaim = DevicePresenceNotificationState::claimOfflineFor($device);
    $secondClaim = DevicePresenceNotificationState::claimOfflineFor($device);

    expect($firstClaim)->toBeString()->not->toBe('')
        ->and($secondClaim)->toBeNull()
        ->and(DevicePresenceNotificationState::markDelivered($device, $firstClaim))->toBeTrue()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();
});

test('a stale pending offline claim can be reclaimed but a fresh claim cannot', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice();
    $staleToken = DevicePresenceNotificationState::claimOfflineFor($device);

    expect(DevicePresenceNotificationState::claimOfflineFor($device))->toBeNull();

    Carbon::setTestNow(now()->addSeconds(DevicePresenceNotificationState::CLAIM_TIMEOUT_SECONDS + 1));
    $replacement = DevicePresenceNotificationState::claimOfflineFor($device);

    expect($replacement)->toBeString()->not->toBe($staleToken)
        ->and(DevicePresenceNotificationState::markDelivered($device, $staleToken))->toBeFalse()
        ->and(DevicePresenceNotificationState::markDelivered($device, $replacement))->toBeTrue();
});

test('an offline device with a legacy cache marker is migrated without a duplicate offline alert', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['rustdesk_id' => 'legacy-offline']);
    Cache::put('apprise:device-offline:'.sha1($device->rustdesk_id), true, now()->addDays(30));

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertNothingSent();
    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull();
});

test('an online device atomically consumes a legacy marker and sends at most one recovery', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['rustdesk_id' => 'legacy-recovered', 'last_online_at' => now()]);
    $key = 'apprise:device-offline:'.sha1($device->rustdesk_id);
    Cache::put($key, true, now()->addDays(30));

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect(Cache::has($key))->toBeFalse();
});

test('a stale legacy marker paired with delivered state cannot send a second recovery', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['rustdesk_id' => 'legacy-and-durable-recovered', 'last_online_at' => now()]);
    $key = 'apprise:device-offline:'.sha1($device->rustdesk_id);
    DevicePresenceNotificationState::recordLegacyOffline($device);
    Cache::put($key, true, now()->addDays(30));

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();
    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    Http::assertSentCount(1);
    expect(Cache::has($key))->toBeFalse()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

test('a legacy marker claim makes a concurrent recovery lose without resurrecting durable state', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['rustdesk_id' => 'legacy-offline-online-race']);
    $key = 'apprise:device-offline:'.sha1($device->rustdesk_id);
    Cache::put($key, true, now()->addDays(30));

    $sweepClaim = DevicePresenceNotificationState::claimLegacyMarkerFor($device);

    expect($sweepClaim)->toBeString()->not->toBe('')
        ->and(DevicePresenceNotificationState::claimLegacyMarkerFor($device))->toBeNull();

    // The online heartbeat loses the sweep's shared claim and must not send a
    // recovery. The competing sweep also cannot resurrect a durable marker.
    CheckDeviceNotifications::reportOnline($device, app(AppriseNotifications::class));
    Http::assertNothingSent();

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    expect(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse()
        ->and(DevicePresenceNotificationState::convertClaimedLegacyMarkerFor($device, $sweepClaim))->toBeTrue();

    expect(Cache::has($key))->toBeFalse()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->value('offline_notified_at'))->not->toBeNull()
        ->and(DevicePresenceNotificationState::claimLegacyMarkerFor($device))->toBeFalse()
        ->and(DevicePresenceNotificationState::consumeFor($device))->toBeTrue()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

test('an expired legacy claim can be recovered only by its replacement token', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice(['rustdesk_id' => 'legacy-claim-expiry']);
    Cache::put('apprise:device-offline:'.sha1($device->rustdesk_id), true, now()->addDays(30));

    $expired = DevicePresenceNotificationState::claimLegacyMarkerFor($device);
    Carbon::setTestNow(now()->addSeconds(DevicePresenceNotificationState::CLAIM_TIMEOUT_SECONDS + 1));
    $replacement = DevicePresenceNotificationState::claimLegacyMarkerFor($device);

    expect($expired)->toBeString()
        ->and($replacement)->toBeString()->not->toBe($expired)
        ->and(DevicePresenceNotificationState::consumeClaimedLegacyMarkerFor($device, $expired))->toBeFalse()
        ->and(DevicePresenceNotificationState::consumeClaimedLegacyMarkerFor($device, $replacement))->toBeTrue();
});

test('scheduled sweeps prune expired snoozes and aged orphaned targets', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $device = presenceDevice();
    $expired = DevicePresenceSnooze::snoozeDevice($device, now()->subSecond());
    $orphan = DevicePresenceSnooze::query()->create([
        'target_type' => DevicePresenceSnooze::TARGET_DEVICE,
        'target_id' => 999999,
        'expires_at' => now()->addHour(),
        'created_at' => now()->subDays(7)->subSecond(),
        'updated_at' => now(),
    ]);
    DevicePresenceSnooze::query()->whereKey($orphan->id)->update(['created_at' => now()->subDays(7)->subSecond()]);

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    expect(DevicePresenceSnooze::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(DevicePresenceSnooze::query()->whereKey($orphan->id)->exists())->toBeFalse();
});

test('only the owner of a pending claim can release it', function (): void {
    $device = presenceDevice();
    $claim = DevicePresenceNotificationState::claimOfflineFor($device);

    expect(DevicePresenceNotificationState::releaseClaim($device, 'not-'.$claim))->toBeFalse()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeTrue()
        ->and(DevicePresenceNotificationState::releaseClaim($device, $claim))->toBeTrue()
        ->and(DevicePresenceNotificationState::query()->where('device_id', $device->id)->exists())->toBeFalse();
});

test('a sweep preloads snoozes and notification state instead of querying them per device', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    presenceDevice(['rustdesk_id' => 'batch-one']);
    presenceDevice(['rustdesk_id' => 'batch-two']);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('cortendesk:check-device-notifications')->assertSuccessful();

    expect(collect($queries)->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'device_presence_notification_states'))->count())->toBeLessThanOrEqual(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'device_presence_snoozes'))->count())->toBeLessThanOrEqual(1);
});

test('an administrator can create a bounded group maintenance snooze', function (): void {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $group = DeviceGroup::query()->create(['name' => 'Weekend work']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(SettingsPage::class)
        ->set('presenceSnoozeTargetType', 'group')
        ->set('presenceSnoozeTargetId', $group->id)
        ->set('presenceSnoozeMinutes', 90)
        ->call('createPresenceSnooze')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('device_presence_snoozes', [
        'target_type' => 'group',
        'target_id' => $group->id,
        'expires_at' => now()->addMinutes(90),
    ]);
});

function delegatedSettingsManager(): User
{
    $role = Role::query()->create([
        'name' => 'Settings manager',
        'permissions' => ['setting' => 'rw'],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('a delegated settings manager cannot create a presence snooze', function (): void {
    $group = DeviceGroup::query()->create(['name' => 'Private maintenance']);
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->set('presenceSnoozeTargetType', 'group')
        ->set('presenceSnoozeTargetId', $group->id)
        ->call('createPresenceSnooze')
        ->assertForbidden();

    expect(DevicePresenceSnooze::query()->count())->toBe(0);
});

test('a delegated settings manager cannot end a presence snooze', function (): void {
    $device = presenceDevice();
    $snooze = DevicePresenceSnooze::snoozeDevice($device, now()->addHour());
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->call('clearPresenceSnooze', $snooze->id)
        ->assertForbidden();

    expect(DevicePresenceSnooze::query()->whereKey($snooze->id)->exists())->toBeTrue();
});

test('a delegated settings manager cannot see presence maintenance controls or targets', function (): void {
    $group = DeviceGroup::query()->create(['name' => 'Secret fleet group']);
    $device = presenceDevice(['hostname' => 'Secret fleet device']);
    DevicePresenceSnooze::snoozeGroup($group, now()->addHour());
    $manager = delegatedSettingsManager();

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->assertDontSee('Presence alert maintenance')
        ->assertDontSee('Secret fleet group')
        ->assertDontSee('Secret fleet device')
        ->assertDontSee('Snooze alerts')
        ->assertDontSee('End now');
});
