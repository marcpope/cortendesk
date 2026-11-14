<?php

/**
 * Deployment approval gate (PLAN B3).
 *
 * Setting `require_device_approval` (default off) quarantines first-seen
 * devices as `pending`: registered but excluded from scoping/address book/
 * group tab/device list until an operator approves them. Heartbeat/sysinfo
 * stay client-transparent. A `--deploy` (API token, Device rw) registration is
 * pre-approved.
 */

use App\Livewire\DeviceList;
use App\Models\ApiToken;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function gateAdmin(): User
{
    $user = User::create([
        'username' => 'gate-admin',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

function enableGate(): void
{
    Setting::put('require_device_approval', '1');
}

function sysinfoRegister(string $id, string $uuid = 'u-gate'): void
{
    test()->postJson('/api/sysinfo', [
        'id' => $id,
        'uuid' => $uuid,
        'hostname' => 'PC-'.$id,
        'os' => 'windows / Windows 11',
        'username' => 'winuser',
        'version' => '1.4.2',
    ])->assertOk();
}

// -------------------------------------------------------------- gate off ---

it('registers a first-seen device as active when the gate is off (current behavior)', function () {
    sysinfoRegister('300000001');

    $device = Device::where('rustdesk_id', '300000001')->first();
    expect($device)->not->toBeNull()
        ->and($device->status)->toBe(Device::STATUS_ACTIVE);
});

// --------------------------------------------------------------- gate on ---

it('quarantines a first-seen device as pending when the gate is on', function () {
    enableGate();
    sysinfoRegister('300000002');

    $device = Device::where('rustdesk_id', '300000002')->first();
    expect($device)->not->toBeNull()
        ->and($device->status)->toBe(Device::STATUS_PENDING);
});

it('keeps sysinfo and heartbeat client-transparent while the gate holds a device pending', function () {
    enableGate();

    // sysinfo ack is byte-identical to the ungated path.
    $ack = $this->postJson('/api/sysinfo', ['id' => '300000003', 'uuid' => 'u', 'hostname' => 'h'])
        ->assertOk();
    expect($ack->getContent())->toBe('SYSINFO_UPDATED');

    // heartbeat still returns 200 and updates presence.
    $this->postJson('/api/heartbeat', ['id' => '300000003', 'uuid' => 'u'])->assertOk();

    $device = Device::where('rustdesk_id', '300000003')->first();
    expect($device->status)->toBe(Device::STATUS_PENDING)
        ->and($device->last_online_at)->not->toBeNull();
});

// ----------------------------------------------------- pending exclusion ---

it('excludes pending devices from the visibleTo scope (device list / dashboards)', function () {
    $admin = gateAdmin();
    enableGate();
    sysinfoRegister('300000004');

    expect(Device::visibleTo($admin)->count())->toBe(0)
        ->and(Device::query()->ownershipVisibleTo($admin)->pending()->count())->toBe(1);
});

it('hides pending devices from the main device list but shows them on the Pending tab', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000005');

    Livewire::test(DeviceList::class)
        ->assertDontSee('300000005')
        ->assertSee('Pending approval')
        ->set('pendingTab', true)
        ->assertSee('300000005');
});

it('excludes pending devices from the group-tab peers endpoint', function () {
    $user = User::create(['username' => 'peer-user', 'password' => 'secret-password', 'is_admin' => true]);
    enableGate();
    sysinfoRegister('300000006');

    $token = ClientToken::issue($user);
    $peers = $this->getJson('/api/peers?current=1&pageSize=100', [
        'Authorization' => "Bearer {$token->token}", 'Accept' => 'application/json',
    ])->assertOk()->json('data');

    expect($peers)->toBe([]);
});

// -------------------------------------------------- approve / reject ---

it('approves a pending device: flips to active, becomes visible, and is audited', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000007');
    $device = Device::where('rustdesk_id', '300000007')->first();

    Livewire::test(DeviceList::class)
        ->call('approveDevice', $device->id);

    expect($device->fresh()->status)->toBe(Device::STATUS_ACTIVE);
    expect(ConsoleAudit::where('action', 'device.approve')
        ->where('target_id', '300000007')->exists())->toBeTrue();
});

it('rejects a pending device: soft-deletes it and is audited', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000008');
    $device = Device::where('rustdesk_id', '300000008')->first();

    Livewire::test(DeviceList::class)
        ->call('rejectDevice', $device->id);

    expect($device->fresh()->trashed())->toBeTrue();
    expect(ConsoleAudit::where('action', 'device.reject')
        ->where('target_id', '300000008')->exists())->toBeTrue();
});

it('shows a rejected device in the recycle bin, where it can be restored to pending (PR #140)', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000010');
    $device = Device::where('rustdesk_id', '300000010')->first();

    $list = Livewire::test(DeviceList::class)->call('rejectDevice', $device->id);
    $list->set('trashed', true)
        ->assertSee('300000010')
        ->assertSee(__('devices.rejected'))
        ->assertViewHas('trashedCount', 1);

    $list->call('restoreDevice', $device->id);
    $device->refresh();
    expect($device->trashed())->toBeFalse();
    expect($device->status)->toBe(Device::STATUS_PENDING);
});

it('permanently deletes a rejected device, freeing its ID (PR #140)', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000011');
    $device = Device::where('rustdesk_id', '300000011')->first();

    Livewire::test(DeviceList::class)
        ->call('rejectDevice', $device->id)
        ->call('forceDeleteDevice', $device->id);

    expect(Device::withTrashed()->where('rustdesk_id', '300000011')->exists())->toBeFalse();
});

it('says why a new device ID is taken (PR #140)', function () {
    gateAdmin();
    enableGate();
    sysinfoRegister('300000012');
    sysinfoRegister('300000013');
    $rejected = Device::where('rustdesk_id', '300000012')->first();
    Device::create(['rustdesk_id' => '300000014', 'uuid' => 'u', 'status' => Device::STATUS_ACTIVE]);

    $list = Livewire::test(DeviceList::class)->call('rejectDevice', $rejected->id);
    foreach ([
        '300000012' => 'devices.id_taken_in_recycle_bin',
        '300000013' => 'devices.id_taken_pending',
        '300000014' => 'devices.id_taken',
    ] as $id => $key) {
        $list->call('create')->set('formRustdeskId', $id)->call('save')
            ->assertHasErrors('formRustdeskId')
            ->assertSee(__($key));
    }
    expect(Device::withTrashed()->count())->toBe(3);
});

it('will not approve a non-pending (already active) device via the pending action', function () {
    gateAdmin();
    $active = Device::create(['rustdesk_id' => '300000009', 'uuid' => 'u', 'status' => Device::STATUS_ACTIVE]);

    Livewire::test(DeviceList::class)->call('approveDevice', $active->id)->assertNotFound();

    expect($active->fresh()->status)->toBe(Device::STATUS_ACTIVE);
});

// ------------------------------------------------------- --deploy path ---

it('registers a pre-approved (active) device via the --deploy/--assign token path even when the gate is on', function () {
    enableGate();
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'deploy-token', ['device' => 'rw']);
    $assignee = User::factory()->create(['username' => 'deployee']);

    $this->postJson('/api/devices/cli', [
        'id' => '300000010', 'uuid' => 'u-deploy', 'user_name' => 'deployee',
    ], ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])->assertOk();

    $device = Device::where('rustdesk_id', '300000010')->first();
    expect($device)->not->toBeNull()
        ->and($device->status)->toBe(Device::STATUS_ACTIVE);
});

it('approves a previously-quarantined device when a --deploy token re-registers it', function () {
    enableGate();
    sysinfoRegister('300000011', 'u-deploy2');
    expect(Device::where('rustdesk_id', '300000011')->first()->status)->toBe(Device::STATUS_PENDING);

    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'deploy-token2', ['device' => 'rw']);
    User::factory()->create(['username' => 'deployee2']);

    $this->postJson('/api/devices/cli', [
        'id' => '300000011', 'uuid' => 'u-deploy2', 'user_name' => 'deployee2',
    ], ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])->assertOk();

    expect(Device::where('rustdesk_id', '300000011')->first()->status)->toBe(Device::STATUS_ACTIVE);
});
