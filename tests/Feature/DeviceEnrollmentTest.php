<?php

use App\Livewire\DeviceList;
use App\Livewire\SettingsPage;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceEnrollment;
use Livewire\Livewire;

/**
 * Issue #97: a default device group for first-seen devices.
 * Issue #95: approve a pending device when its client signs in.
 */
function enrollSysinfo(string $id, string $uuid = 'u-enroll'): void
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

function enrollLogin(User $user, string $id, string $uuid = 'u-enroll'): void
{
    test()->postJson('/api/login', [
        'username' => $user->username,
        'password' => 'secret-password',
        'id' => $id,
        'uuid' => $uuid,
        'type' => 'account',
        'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'PC'],
    ])->assertOk()->assertJsonStructure(['access_token']);
}

/** A Device rw API token's headers, as the client's --assign sends them. */
function enrollAssignHeaders(): array
{
    [, $plain] = ApiToken::issue(User::factory()->admin()->create(), 'enroll-'.uniqid(), ['device' => 'rw']);

    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

function enrollUser(string $username = 'tech'): User
{
    return User::create(['username' => $username, 'password' => 'secret-password', 'is_active' => true]);
}

// ---------------------------------------------------------- default group ---

it('places a first-seen device in the default group', function () {
    $group = DeviceGroup::create(['name' => 'New arrivals']);
    Setting::put(DeviceEnrollment::SETTING_DEFAULT_GROUP, (string) $group->id);

    enrollSysinfo('400000001');

    expect(Device::where('rustdesk_id', '400000001')->value('device_group_id'))->toBe($group->id);
});

it('files pending devices in the default group too, and leaves existing devices alone', function () {
    enrollSysinfo('400000002');
    $group = DeviceGroup::create(['name' => 'New arrivals']);
    Setting::put(DeviceEnrollment::SETTING_DEFAULT_GROUP, (string) $group->id);
    Setting::put('require_device_approval', '1');

    enrollSysinfo('400000002'); // a later heartbeat from the old device
    enrollSysinfo('400000003');

    expect(Device::where('rustdesk_id', '400000002')->value('device_group_id'))->toBeNull()
        ->and(Device::where('rustdesk_id', '400000003')->first())
        ->device_group_id->toBe($group->id)
        ->status->toBe(Device::STATUS_PENDING);
});

it('ignores a default group that has been deleted', function () {
    $group = DeviceGroup::create(['name' => 'Gone']);
    Setting::put(DeviceEnrollment::SETTING_DEFAULT_GROUP, (string) $group->id);
    $group->delete();

    enrollSysinfo('400000004');

    expect(Device::where('rustdesk_id', '400000004')->value('device_group_id'))->toBeNull();
});

it('applies the default group to a CLI assign that names no group', function () {
    $group = DeviceGroup::create(['name' => 'New arrivals']);
    Setting::put(DeviceEnrollment::SETTING_DEFAULT_GROUP, (string) $group->id);
    $named = DeviceGroup::create(['name' => 'Named']);
    enrollUser('alice');

    $this->postJson('/api/devices/cli', ['id' => '400000005', 'uuid' => 'u', 'user_name' => 'alice'], enrollAssignHeaders())->assertOk();
    $this->postJson('/api/devices/cli', ['id' => '400000006', 'uuid' => 'u', 'user_name' => 'alice', 'device_group_name' => 'Named'], enrollAssignHeaders())->assertOk();

    expect(Device::where('rustdesk_id', '400000005')->value('device_group_id'))->toBe($group->id)
        ->and(Device::where('rustdesk_id', '400000006')->value('device_group_id'))->toBe($named->id);
});

// ------------------------------------------------------ approval by sign-in ---

it('approves a pending device when its user signs in afterwards', function () {
    Setting::put('require_device_approval', '1');
    Setting::put(DeviceEnrollment::SETTING_AUTO_APPROVE, '1');
    $tech = enrollUser();

    enrollSysinfo('400000010');
    expect(Device::where('rustdesk_id', '400000010')->first()->isPending())->toBeTrue();

    enrollLogin($tech, '400000010');

    $device = Device::where('rustdesk_id', '400000010')->first();
    expect($device->status)->toBe(Device::STATUS_ACTIVE)
        ->and($device->user_id)->toBe($tech->id);
});

it('approves a device that registers after its user signed in', function () {
    Setting::put('require_device_approval', '1');
    Setting::put(DeviceEnrollment::SETTING_AUTO_APPROVE, '1');
    $tech = enrollUser();

    enrollLogin($tech, '400000011');
    enrollSysinfo('400000011');

    $device = Device::where('rustdesk_id', '400000011')->first();
    expect($device->status)->toBe(Device::STATUS_ACTIVE)
        ->and($device->user_id)->toBe($tech->id);
});

it('keeps the gate when the setting is off, or the sign-in is from another device', function () {
    Setting::put('require_device_approval', '1');
    $tech = enrollUser();

    enrollLogin($tech, '400000012');
    enrollSysinfo('400000012');
    expect(Device::where('rustdesk_id', '400000012')->first()->isPending())->toBeTrue();

    Setting::put(DeviceEnrollment::SETTING_AUTO_APPROVE, '1');
    enrollLogin($tech, '400000013');
    enrollSysinfo('400000099');
    expect(Device::where('rustdesk_id', '400000099')->first()->isPending())->toBeTrue();
});

it('never demotes an approved device or replaces an existing owner', function () {
    Setting::put('require_device_approval', '1');
    Setting::put(DeviceEnrollment::SETTING_AUTO_APPROVE, '1');
    $owner = enrollUser('owner');
    $tech = enrollUser('tech');

    enrollSysinfo('400000014');
    Device::where('rustdesk_id', '400000014')->update(['user_id' => $owner->id]);
    enrollLogin($tech, '400000014');

    $device = Device::where('rustdesk_id', '400000014')->first();
    expect($device->status)->toBe(Device::STATUS_ACTIVE)
        ->and($device->user_id)->toBe($owner->id);
});

// -------------------------------------- sign-in registers the device (#110) ---

function enrollPhoneLogin(User $user, string $id, string $uuid = 'cGhvbmU='): void
{
    test()->postJson('/api/login', [
        'username' => $user->username,
        'password' => 'secret-password',
        'id' => $id,
        'uuid' => $uuid,
        'type' => 'account',
        'deviceInfo' => ['os' => 'ios', 'type' => 'client', 'name' => 'iPhone'],
    ])->assertOk()->assertJsonStructure(['access_token']);
}

it('registers a device that signs in without ever sending sysinfo', function () {
    $tech = enrollUser();

    enrollPhoneLogin($tech, '400000020');

    $device = Device::where('rustdesk_id', '400000020')->first();
    expect($device)->not->toBeNull()
        ->status->toBe(Device::STATUS_ACTIVE)
        ->user_id->toBe($tech->id)
        ->hostname->toBeNull()
        ->os->toBe('ios')
        ->uuid->toBe('cGhvbmU=')
        ->last_online_at->toBeNull()
        ->and(Device::count())->toBe(1);
});

it('puts a signed-in phone on the server policy allow list', function () {
    config(['cortendesk.server_secret' => 'enroll-secret']);
    Setting::put('server_access_mode', 'approved');
    $tech = enrollUser();

    enrollPhoneLogin($tech, '400000021');

    $policy = test()->getJson('/api/server/policy', [
        'Authorization' => 'Bearer enroll-secret',
        'User-Agent' => 'cortendesk-server/1.1.1',
    ])->assertOk()->json();

    expect($policy['allow'])->toContain('400000021')
        ->and(array_values($policy['tokens']))->toContain('400000021');
});

it('holds a signed-in device for approval when the gate is on', function () {
    Setting::put('require_device_approval', '1');
    $tech = enrollUser();

    enrollPhoneLogin($tech, '400000022');
    expect(Device::where('rustdesk_id', '400000022')->first()->isPending())->toBeTrue();

    Setting::put(DeviceEnrollment::SETTING_AUTO_APPROVE, '1');
    enrollPhoneLogin($tech, '400000023');
    expect(Device::where('rustdesk_id', '400000023')->first())
        ->status->toBe(Device::STATUS_ACTIVE)
        ->user_id->toBe($tech->id);
});

it('leaves a recycled device recycled when its client signs in', function () {
    $tech = enrollUser();
    enrollSysinfo('400000024');
    Device::where('rustdesk_id', '400000024')->first()->delete();

    enrollPhoneLogin($tech, '400000024');

    expect(Device::where('rustdesk_id', '400000024')->exists())->toBeFalse()
        ->and(Device::withTrashed()->where('rustdesk_id', '400000024')->count())->toBe(1);
});

it('still asks a device registered at sign-in for its inventory', function () {
    $tech = enrollUser();
    enrollPhoneLogin($tech, '400000025');

    test()->postJson('/api/heartbeat', ['id' => '400000025', 'uuid' => 'cGhvbmU=', 'ver' => 1004002])
        ->assertOk()->assertJson(['sysinfo' => 1]);

    enrollSysinfo('400000025', 'cGhvbmU=');

    expect(Device::where('rustdesk_id', '400000025')->first())
        ->hostname->toBe('PC-400000025')
        ->version->toBe('1.4.2')
        ->user_id->toBe($tech->id);
});

// ---------------------------------------------------------------- settings ---

it('saves both settings from the Server tab and refuses a vanished group', function () {
    $admin = User::create(['username' => 'enroll-admin', 'password' => 'secret-password', 'is_admin' => true]);
    $this->actingAs($admin);
    $group = DeviceGroup::create(['name' => 'New arrivals']);

    Livewire::test(SettingsPage::class)
        ->set('requireDeviceApproval', true)
        ->set('autoApproveSignedIn', true)
        ->set('defaultDeviceGroupId', $group->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(DeviceEnrollment::autoApproveSignedIn())->toBeTrue()
        ->and(DeviceEnrollment::defaultGroup()?->id)->toBe($group->id);

    Livewire::test(SettingsPage::class)
        ->set('defaultDeviceGroupId', 999999)
        ->call('save')
        ->assertHasErrors('defaultDeviceGroupId')
        ->assertSet('tab', 'server');
});

// ------------------------------------------------- new devices incoming only ---

it('makes every first-seen device incoming only when the setting is on (#107)', function () {
    Setting::put(DeviceEnrollment::SETTING_NEW_INCOMING_ONLY, '1');
    enrollUser('alice');
    $tech = enrollUser('tech');

    enrollSysinfo('400000040');
    enrollPhoneLogin($tech, '400000041');
    $this->postJson('/api/devices/cli', ['id' => '400000042', 'uuid' => 'u', 'user_name' => 'alice'], enrollAssignHeaders())->assertOk();

    foreach (['400000040', '400000041', '400000042'] as $id) {
        expect(Device::where('rustdesk_id', $id)->first()->isIncomingOnly())->toBeTrue();
    }
});

it('leaves new devices able to start sessions by default, and existing ones alone', function () {
    enrollSysinfo('400000043');
    Setting::put(DeviceEnrollment::SETTING_NEW_INCOMING_ONLY, '1');
    enrollSysinfo('400000043');

    expect(Device::where('rustdesk_id', '400000043')->first()->isIncomingOnly())->toBeFalse();
});

it('saves the setting and presets the Add device form from it', function () {
    $admin = User::create(['username' => 'enroll-admin', 'password' => 'secret-password', 'is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(SettingsPage::class)
        ->set('newDevicesIncomingOnly', true)
        ->call('save')
        ->assertHasNoErrors();
    expect(DeviceEnrollment::newDevicesIncomingOnly())->toBeTrue();

    Livewire::test(DeviceList::class)
        ->call('create')
        ->assertSet('formCanInitiate', false);
});
