<?php

use App\Console\Commands\CheckDeviceNotifications;
use App\Livewire\SettingsPage;
use App\Models\AlarmLog;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppriseNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * Apprise notifications (issue #22). The delivery path runs inside auth and
 * client-API requests, so the two properties worth defending are that nothing
 * transport-shaped reaches the database or the log, and that a failure never
 * changes the response the caller gets.
 */

/** @param list<string> $events */
function configureApprise(array $events, string $mode = 'config'): void
{
    Cache::clear();
    Setting::put('apprise_enabled', '1');
    Setting::put('apprise_endpoint', Crypt::encryptString('https://api.example.test/apprise?access_token=secret'));
    Setting::put('apprise_delivery_mode', $mode);
    Setting::put('apprise_config_key', Crypt::encryptString('office-alerts'));
    Setting::put('apprise_cooldown_minutes', '15');

    foreach (array_keys(AppriseNotifications::EVENTS) as $event) {
        $suffix = str_replace('.', '_', $event);
        Setting::put('apprise_event_'.$suffix, in_array($suffix, $events, true) ? '1' : '0');
    }
}

it('it sends a configured stateful apprise notification and records a safe delivery log', function () {
    configureApprise(['security_alarm']);

    Http::fake([
        'https://api.example.test/apprise/notify/office-alerts?access_token=secret' => Http::response(['result' => 'success']),
    ]);

    $delivery = app(AppriseNotifications::class)->send(
        'security.alarm',
        'Security alarm',
        'Device 123 reported an alarm.',
        'alarm:123:1',
    );

    $this->assertNotNull($delivery);
    $this->assertSame('sent', $delivery->status, $delivery->error ?? 'Delivery did not send.');
    $this->assertDatabaseHas('notification_deliveries', [
        'event' => 'security.alarm',
        'subject' => 'alarm:123:1',
        'status' => 'sent',
    ]);
    $this->assertStringNotContainsString('secret', (string) $delivery->error);
    $this->assertDatabaseMissing('notification_deliveries', [
        'error' => 'https://api.example.test/apprise?access_token=secret',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.example.test/apprise/notify/office-alerts?access_token=secret'
            && $request['title'] === 'Security alarm'
            && $request['body'] === 'Device 123 reported an alarm.'
            && $request['type'] === 'failure';
    });
});

it('sends the apprise type that matches the event (issue #53)', function (string $event, string $type) {
    configureApprise([str_replace('.', '_', $event)]);
    Http::fake(['*' => Http::response(['result' => 'success'])]);

    app(AppriseNotifications::class)->send($event, 'Title', 'Body', 'subject:'.$event);

    Http::assertSent(fn ($request) => $request['type'] === $type);
})->with([
    ['device.pending_approval', 'warning'],
    ['device.offline', 'info'],
    ['device.online', 'success'],
    ['console.login_failed', 'warning'],
    ['security.alarm', 'failure'],
    ['remote_connection.failure', 'failure'],
]);

it('sends the settings test as info', function () {
    configureApprise([]);
    Http::fake(['*' => Http::response(['result' => 'success'])]);

    app(AppriseNotifications::class)->test();

    Http::assertSent(fn ($request) => $request['type'] === 'info');
});

it('names and links the device behind a recent delivery (issue #53)', function () {
    $admin = User::factory()->admin()->create();
    $device = Device::create(['rustdesk_id' => '555111222', 'uuid' => 'u-555', 'alias' => 'Front desk']);
    $alarm = AlarmLog::create(['rustdesk_id' => '555111222', 'uuid' => 'u-555', 'typ' => 1, 'info' => '{}']);
    NotificationDelivery::create(['event' => 'device.offline', 'subject' => 'device:555111222', 'status' => 'sent', 'title' => 'Device offline']);
    NotificationDelivery::create(['event' => 'security.alarm', 'subject' => 'alarm:'.$alarm->id, 'status' => 'sent', 'title' => 'Security alarm']);
    NotificationDelivery::create(['event' => 'console.login_failed', 'subject' => 'web-login:abc', 'status' => 'failed', 'title' => 'Failed console login', 'error' => 'HTTP 500']);

    $html = Livewire::actingAs($admin)->test(SettingsPage::class)->html();

    expect(substr_count($html, 'Front desk (555111222)'))->toBe(2)
        ->and(substr_count($html, route('devices.show', $device->id)))->toBe(2)
        ->and($html)->toContain('Failed console login')
        ->and($html)->toContain('Device offline')->not->toContain('device.offline');
});

it('it honors cooldown without creating delivery log noise', function () {
    configureApprise(['device_offline']);
    Http::fake(['*' => Http::response(['result' => 'success'])]);

    $notifications = app(AppriseNotifications::class);
    $this->assertSame('sent', $notifications->send('device.offline', 'Device offline', 'Host is offline.', 'device:1')?->status);
    $this->assertNull($notifications->send('device.offline', 'Device offline', 'Host is offline.', 'device:1'));

    Http::assertSentCount(1);
    $this->assertSame(1, NotificationDelivery::query()->count());
});

it('device events can be scoped to selected groups or devices', function () {
    configureApprise(['device_offline']);
    Http::fake(['*' => Http::response(['result' => 'success'])]);
    $group = DeviceGroup::create(['name' => 'Servers']);
    $includedByGroup = Device::create(['rustdesk_id' => '101', 'uuid' => 'grouped', 'status' => Device::STATUS_ACTIVE, 'device_group_id' => $group->id]);
    $includedDirectly = Device::create(['rustdesk_id' => '102', 'uuid' => 'direct', 'status' => Device::STATUS_ACTIVE]);
    $excluded = Device::create(['rustdesk_id' => '103', 'uuid' => 'excluded', 'status' => Device::STATUS_ACTIVE]);

    Setting::put('apprise_scope_device_offline', 'selected');
    Setting::put('apprise_scope_groups_device_offline', json_encode([$group->id]));
    Setting::put('apprise_scope_devices_device_offline', json_encode([$includedDirectly->id]));

    $notifications = app(AppriseNotifications::class);
    $this->assertSame('sent', $notifications->send('device.offline', 'Offline', 'Grouped', 'device:101', $includedByGroup)?->status);
    $this->assertSame('sent', $notifications->send('device.offline', 'Offline', 'Direct', 'device:102', $includedDirectly)?->status);
    $this->assertNull($notifications->send('device.offline', 'Offline', 'Excluded', 'device:103', $excluded));
    Http::assertSentCount(2);
});

it('sends pending approval for every device, whatever scope was saved for it (issue #109)', function () {
    configureApprise(['device_pending_approval']);
    Http::fake(['*' => Http::response(['result' => 'success'])]);
    $device = Device::create(['rustdesk_id' => '104', 'uuid' => 'new', 'status' => Device::STATUS_PENDING]);

    // A scope saved by an older version, naming nothing this device matches.
    Setting::put('apprise_scope_device_pending_approval', 'selected');
    Setting::put('apprise_scope_groups_device_pending_approval', '[]');
    Setting::put('apprise_scope_devices_device_pending_approval', '[]');

    $delivery = app(AppriseNotifications::class)->send('device.pending_approval', 'Pending', 'New', 'device:104', $device);

    $this->assertSame('sent', $delivery?->status);
});

it('offers a scope only for offline and recovery alerts', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->set('tab', 'notifications')
        ->assertSeeHtml('wire:model.live="appriseScopes.device_offline"')
        ->assertSeeHtml('wire:model.live="appriseScopes.device_online"')
        ->assertDontSeeHtml('wire:model.live="appriseScopes.device_pending_approval"');
});

it('it uses stateless urls without exposing them in the delivery log', function () {
    configureApprise(['security_alarm'], 'urls');
    Setting::put('apprise_urls', Crypt::encryptString(json_encode([
        'jsons://hooks.example.test/secret-token',
    ])));
    Http::fake(['https://api.example.test/apprise/notify?access_token=secret' => Http::response(['result' => 'success'])]);

    $delivery = app(AppriseNotifications::class)->send('security.alarm', 'Security alarm', 'Body', 'alarm:4');

    $this->assertSame('sent', $delivery?->status);
    Http::assertSent(fn ($request) => $request['urls'] === ['jsons://hooks.example.test/secret-token']);
    $this->assertStringNotContainsString('secret-token', (string) $delivery?->error);
    $this->assertStringNotContainsString('hooks.example.test', (string) $delivery?->error);
    $this->assertSame('Delivery to [redacted URL] failed', AppriseNotifications::redact('Delivery to jsons://hooks.example.test/secret-token failed'));
});

it('settings save encrypts write only apprise transport fields', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->set('appriseEndpoint', 'https://apprise.example.test?access_token=secret')
        ->set('appriseConfigKey', 'office-alerts')
        ->set('appriseEnabled', true)
        ->set('appriseEvents.security_alarm', true)
        ->set('appriseScopes.device_offline', 'selected')
        ->set('appriseScopeGroups.device_offline', [12])
        ->set('appriseScopeDevices.device_offline', [34])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('appriseEndpoint', '')
        ->assertSet('appriseConfigKey', '');

    $storedEndpoint = (string) Setting::get('apprise_endpoint', '');
    $this->assertStringNotContainsString('apprise.example.test', $storedEndpoint);
    $this->assertSame('https://apprise.example.test?access_token=secret', Crypt::decryptString($storedEndpoint));
    $this->assertSame('office-alerts', Crypt::decryptString((string) Setting::get('apprise_config_key', '')));
    $this->assertSame('1', Setting::get('apprise_event_security_alarm'));
    $this->assertSame('selected', Setting::get('apprise_scope_device_offline'));
    $this->assertSame([12], json_decode((string) Setting::get('apprise_scope_groups_device_offline'), true));
    $this->assertSame([34], json_decode((string) Setting::get('apprise_scope_devices_device_offline'), true));
});

it('reliable api and console paths emit their enabled events', function () {
    configureApprise([
        'device_pending_approval',
        'console_login_failed',
        'security_alarm',
        'remote_connection_failure',
    ]);
    Setting::put('require_device_approval', '1');
    Http::fake(['*' => Http::response(['result' => 'success'])]);

    $this->postJson('/api/sysinfo', [
        'id' => '123456789',
        'uuid' => 'new-device',
        'hostname' => 'Awaiting approval',
    ])->assertOk();

    $this->post('/login', ['username' => 'nobody', 'password' => 'wrong'])
        ->assertSessionHasErrors('username');

    $this->postJson('/api/audit/alarm', [
        'id' => '123456789',
        'uuid' => 'new-device',
        'typ' => 1,
        'info' => '{"detail":"many failures"}',
    ])->assertOk();

    $sentBodies = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]['body'])->all();
    $this->assertFalse(collect($sentBodies)->contains(fn ($body) => str_contains($body, 'nobody') || str_contains($body, 'wrong')));
    $this->assertDatabaseHas('notification_deliveries', ['event' => 'device.pending_approval', 'status' => 'sent']);
    $this->assertDatabaseHas('notification_deliveries', ['event' => 'console.login_failed', 'status' => 'sent']);
    $this->assertDatabaseHas('notification_deliveries', ['event' => 'security.alarm', 'status' => 'sent']);
    $this->assertDatabaseHas('notification_deliveries', ['event' => 'remote_connection.failure', 'status' => 'sent']);
});

it('delivery exceptions are redacted and never escape', function () {
    configureApprise(['security_alarm']);
    Log::spy();
    Http::fake(fn () => throw new RuntimeException('token=top-secret https://private.example.test/hook'));

    $delivery = app(AppriseNotifications::class)->send('security.alarm', 'Alarm', 'Body', 'alarm:5');

    $this->assertSame('failed', $delivery?->status);
    $this->assertStringNotContainsString('top-secret', (string) $delivery?->error);
    $this->assertStringNotContainsString('private.example.test', (string) $delivery?->error);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Apprise notification delivery failed.'
        && ! str_contains((string) $context['error'], 'top-secret')
        && ! str_contains((string) $context['error'], 'private.example.test'));
});

it('json error bodies cannot leak quoted secrets', function () {
    configureApprise(['security_alarm']);
    Http::fake(['*' => Http::response([
        'access_token' => 'top-secret',
        'password' => 'hunter2',
        'message' => 'delivery rejected',
    ], 400)]);

    $delivery = app(AppriseNotifications::class)->send('security.alarm', 'Alarm', 'Body', 'alarm:6');

    $this->assertSame('failed', $delivery?->status);
    $this->assertStringNotContainsString('top-secret', (string) $delivery?->error);
    $this->assertStringNotContainsString('hunter2', (string) $delivery?->error);
    $this->assertStringContainsString('[redacted]', (string) $delivery?->error);
});

it('presence command detects offline and recovery once each', function () {
    configureApprise(['device_offline', 'device_online']);
    Setting::put('online_window', '60');
    Http::fake(['*' => Http::response(['result' => 'success'])]);

    $device = Device::create([
        'rustdesk_id' => '123456789',
        'uuid' => 'device-one',
        'hostname' => 'Workstation',
        'status' => Device::STATUS_ACTIVE,
        'last_online_at' => now()->subMinutes(2),
    ]);

    $this->artisan(CheckDeviceNotifications::class)->assertExitCode(0);
    $this->assertDatabaseHas('notification_deliveries', [
        'event' => 'device.offline',
        'subject' => 'device:'.$device->rustdesk_id,
        'status' => 'sent',
    ]);

    $device->update(['last_online_at' => now()]);
    $this->artisan(CheckDeviceNotifications::class)->assertExitCode(0);
    $this->assertDatabaseHas('notification_deliveries', [
        'event' => 'device.online',
        'subject' => 'device:'.$device->rustdesk_id,
        'status' => 'sent',
    ]);

    $this->assertSame(2, NotificationDelivery::query()->count());
});
