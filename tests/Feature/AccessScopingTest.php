<?php

use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Livewire\UserList;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Livewire\Livewire;

/** @return array<string,string> bearer headers for the client API */
function clientHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.ClientToken::issue($user)->token];
}

function admin(): User
{
    return User::create(['username' => 'admin-'.uniqid(), 'password' => 'secret-password', 'is_admin' => true]);
}

function plainUser(): User
{
    return User::create(['username' => 'user-'.uniqid(), 'password' => 'secret-password', 'is_admin' => false]);
}

function deviceIn(?DeviceGroup $group, ?User $owner = null): Device
{
    static $n = 0;
    $n++;

    return Device::create([
        'rustdesk_id' => "9000000$n",
        'uuid' => "u$n",
        'hostname' => "host$n",
        'device_group_id' => $group?->id,
        'user_id' => $owner?->id,
    ]);
}

// ---------------------------------------------------------------- devices ----

it('admin sees every device', function () {
    $a = admin();
    $g = DeviceGroup::create(['name' => 'G1']);
    deviceIn($g);
    deviceIn(null);

    $this->actingAs($a);
    Livewire::test(DeviceList::class)
        ->assertViewHas('totalCount', 2);
});

it('non-admin sees only devices in granted groups plus their own', function () {
    $u = plainUser();
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $other = DeviceGroup::create(['name' => 'Other']);
    $u->deviceGroups()->attach($granted);

    $inGranted = deviceIn($granted);
    $owned = deviceIn($other, $u);   // in a non-granted group but owned
    $hidden = deviceIn($other);      // neither granted nor owned

    $this->actingAs($u);
    Livewire::test(DeviceList::class)
        ->assertViewHas('totalCount', 2)
        ->assertSee($inGranted->rustdesk_id)
        ->assertSee($owned->rustdesk_id)
        ->assertDontSee($hidden->rustdesk_id);
});

it('non-admin with no grants and no owned devices sees nothing', function () {
    $u = plainUser();
    DeviceGroup::create(['name' => 'G']);
    deviceIn(DeviceGroup::first());

    $this->actingAs($u);
    Livewire::test(DeviceList::class)->assertViewHas('totalCount', 0);
});

it('non-admin cannot edit a device outside their scope even by id', function () {
    $u = plainUser();
    $hidden = deviceIn(DeviceGroup::create(['name' => 'X']));

    $this->actingAs($u);
    Livewire::test(DeviceList::class)->call('edit', $hidden->id)->assertNotFound();
});

it('non-admin cannot delete a device outside their scope', function () {
    $u = plainUser();
    $hidden = deviceIn(DeviceGroup::create(['name' => 'X']));

    $this->actingAs($u);
    Livewire::test(DeviceList::class)->call('deleteDevice', $hidden->id)->assertNotFound();

    expect(Device::whereKey($hidden->id)->exists())->toBeTrue();
});

it('non-admin cannot move a device into a group they cannot reach', function () {
    $u = plainUser();
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $other = DeviceGroup::create(['name' => 'Other']);
    $u->deviceGroups()->attach($granted);
    $device = deviceIn($granted);

    $this->actingAs($u);
    Livewire::test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formGroupId', $other->id)
        ->call('save')
        ->assertHasErrors('formGroupId');

    expect($device->fresh()->device_group_id)->toBe($granted->id);

    // A group id that does not exist is refused the same way, for admins too.
    $this->actingAs(admin());
    Livewire::test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formGroupId', 999999)
        ->call('save')
        ->assertHasErrors('formGroupId');
});

it('non-admin cannot pre-register a device into a group they cannot reach', function () {
    $u = plainUser();
    $other = DeviceGroup::create(['name' => 'Other']);

    $this->actingAs($u);
    Livewire::test(DeviceList::class)
        ->set('editingId', 0)
        ->set('formRustdeskId', '900111222')
        ->set('formGroupId', $other->id)
        ->call('save')
        ->assertHasErrors('formGroupId');

    expect(Device::withTrashed()->where('rustdesk_id', '900111222')->exists())->toBeFalse();
});

it('non-admin can still save a device they own that sits in a group they cannot reach', function () {
    $u = plainUser();
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $other = DeviceGroup::create(['name' => 'Other']);
    $u->deviceGroups()->attach($granted);
    $owned = deviceIn($other, $u);

    $this->actingAs($u);
    Livewire::test(DeviceList::class)
        ->call('edit', $owned->id)
        ->set('formAlias', 'My laptop')
        ->call('save')
        ->assertHasNoErrors();

    expect($owned->fresh()->alias)->toBe('My laptop')
        ->and($owned->fresh()->device_group_id)->toBe($other->id);

    // And may move it into a group they can reach.
    Livewire::test(DeviceList::class)
        ->call('edit', $owned->id)
        ->set('formGroupId', $granted->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($owned->fresh()->device_group_id)->toBe($granted->id);
});

// --------------------------------------------------------- admin sections ----

it('blocks non-admins from admin-only pages', function () {
    $this->actingAs(plainUser());
    $this->get('/users')->assertRedirect('/');
    $this->get('/groups')->assertRedirect('/');
    $this->get('/settings')->assertRedirect('/');
    $this->get('/logs/logins')->assertRedirect('/');
});

it('allows admins into admin-only pages', function () {
    $this->actingAs(admin());
    $this->get('/users')->assertOk();
    $this->get('/settings')->assertOk();
});

it('lets non-admins reach devices and address books', function () {
    $this->actingAs(plainUser());
    $this->get('/devices')->assertOk();
    $this->get('/address-books')->assertOk();
    $this->get('/logs/connections')->assertOk();
});

// ------------------------------------------------- granting device groups ----

it('admin can grant and revoke device-group access via the user editor', function () {
    $this->actingAs(admin());
    $target = plainUser();
    $g1 = DeviceGroup::create(['name' => 'A']);
    $g2 = DeviceGroup::create(['name' => 'B']);

    Livewire::test(UserList::class)
        ->call('edit', $target->id)
        ->set('device_group_ids', [$g1->id, $g2->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->accessibleDeviceGroupIds())->toEqualCanonicalizing([$g1->id, $g2->id]);

    Livewire::test(UserList::class)
        ->call('edit', $target->id)
        ->set('device_group_ids', [$g1->id])
        ->call('save');

    expect($target->fresh()->accessibleDeviceGroupIds())->toBe([$g1->id]);
});

// ------------------------------------- granting folders to USER GROUPS ----

it('grants folder access to every member of a user group (union with per-user grants)', function () {
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach($u);

    $viaGroup = DeviceGroup::create(['name' => 'Via Group']);
    $direct = DeviceGroup::create(['name' => 'Direct']);
    $other = DeviceGroup::create(['name' => 'Other']);

    $team->deviceGroups()->attach($viaGroup);
    $u->deviceGroups()->attach($direct);

    expect($u->fresh()->accessibleDeviceGroupIds())
        ->toEqualCanonicalizing([$viaGroup->id, $direct->id]);

    // Console device visibility follows the union.
    $seen = deviceIn($viaGroup);
    $alsoSeen = deviceIn($direct);
    $hidden = deviceIn($other);

    $ids = Device::visibleTo($u->fresh())->pluck('id');
    expect($ids)->toContain($seen->id)->toContain($alsoSeen->id)
        ->not->toContain($hidden->id);
});

it('admin can grant device groups to a user group via the Groups page editor', function () {
    $this->actingAs(admin());
    $team = UserGroup::create(['name' => 'Team']);
    $g1 = DeviceGroup::create(['name' => 'A']);
    $g2 = DeviceGroup::create(['name' => 'B']);

    Livewire::test(GroupList::class)
        ->call('edit', 'users', $team->id)
        ->set('device_group_ids', [$g1->id, $g2->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->deviceGroups()->pluck('device_groups.id')->all())
        ->toEqualCanonicalizing([$g1->id, $g2->id]);

    Livewire::test(GroupList::class)
        ->call('edit', 'users', $team->id)
        ->set('device_group_ids', [$g2->id])
        ->call('save');

    expect($team->fresh()->deviceGroups()->pluck('device_groups.id')->all())->toBe([$g2->id]);
});

it('removes group grants when the user group is deleted', function () {
    $this->actingAs(admin());
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Doomed']);
    $team->users()->attach($u);
    $folder = DeviceGroup::create(['name' => 'F']);
    $team->deviceGroups()->attach($folder);

    expect($u->fresh()->accessibleDeviceGroupIds())->toBe([$folder->id]);

    Livewire::test(GroupList::class)->call('deleteGroup', 'users', $team->id);

    expect($u->fresh()->accessibleDeviceGroupIds())->toBe([]);
});

// ------------------------------------------ client Group-tab API scoping ----

it('scopes /api/peers to own devices plus granted-folder devices (strict folder model)', function () {
    $u = plainUser();
    $mate = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach([$u->id, $mate->id]);

    $folder = DeviceGroup::create(['name' => 'Granted']);
    $otherFolder = DeviceGroup::create(['name' => 'Hidden Folder']);
    $team->deviceGroups()->attach($folder);

    $inFolder = deviceIn($folder);
    $own = deviceIn(null, $u);
    $matesPersonal = deviceIn(null, $mate);   // group-mate's personal device: NOT visible
    $inOtherFolder = deviceIn($otherFolder);  // ungranted folder: NOT visible

    $res = $this->getJson('/api/peers?current=1&pageSize=100&accessible=&status=1', clientHeaders($u))
        ->assertOk()->json();

    $ids = collect($res['data'])->pluck('id');
    expect($res['total'])->toBe(2)
        ->and($ids)->toContain($inFolder->rustdesk_id)->toContain($own->rustdesk_id)
        ->not->toContain($matesPersonal->rustdesk_id)
        ->not->toContain($inOtherFolder->rustdesk_id);
});

it('scopes /api/device-group/accessible to granted folders only', function () {
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach($u);

    $granted = DeviceGroup::create(['name' => 'Granted']);
    DeviceGroup::create(['name' => 'Secret Folder']);
    $team->deviceGroups()->attach($granted);

    $res = $this->getJson('/api/device-group/accessible?current=1&pageSize=100', clientHeaders($u))
        ->assertOk()->json();

    expect($res['total'])->toBe(1)
        ->and(collect($res['data'])->pluck('name')->all())->toBe(['Granted']);
});

it('scopes /api/users to group-mates plus self', function () {
    $u = plainUser();
    $mate = plainUser();
    $stranger = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach([$u->id, $mate->id]);

    $otherGroup = UserGroup::create(['name' => 'Elsewhere']);
    $otherGroup->users()->attach($stranger);

    $res = $this->getJson('/api/users?current=1&pageSize=100&accessible=&status=1', clientHeaders($u))
        ->assertOk()->json();

    $names = collect($res['data'])->pluck('name');
    expect($res['total'])->toBe(2)
        ->and($names)->toContain($u->username)->toContain($mate->username)
        ->not->toContain($stranger->username);
});

it('includes only self in /api/users when the caller has no user groups', function () {
    $u = plainUser();
    plainUser(); // unrelated user

    $res = $this->getJson('/api/users?current=1&pageSize=100&accessible=&status=1', clientHeaders($u))
        ->assertOk()->json();

    expect($res['total'])->toBe(1)
        ->and($res['data'][0]['name'])->toBe($u->username);
});

it('gives admins the full fleet, all folders and all users in the group tab', function () {
    $a = admin();
    $u = plainUser();
    $folder = DeviceGroup::create(['name' => 'F1']);
    DeviceGroup::create(['name' => 'F2']);
    deviceIn($folder);
    deviceIn(null, $u);
    deviceIn(null);

    $headers = clientHeaders($a);

    expect($this->getJson('/api/peers?current=1&pageSize=100&accessible=&status=1', $headers)->json('total'))->toBe(3)
        ->and($this->getJson('/api/device-group/accessible?current=1&pageSize=100', $headers)->json('total'))->toBe(2)
        ->and($this->getJson('/api/users?current=1&pageSize=100&accessible=&status=1', $headers)->json('total'))->toBe(User::count());
});

it('honours per-user folder grants alongside group grants in the client API', function () {
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach($u);

    $viaGroup = DeviceGroup::create(['name' => 'Via Group']);
    $direct = DeviceGroup::create(['name' => 'Direct']);
    DeviceGroup::create(['name' => 'Neither']);
    $team->deviceGroups()->attach($viaGroup);
    $u->deviceGroups()->attach($direct);

    deviceIn($viaGroup);
    deviceIn($direct);
    deviceIn(DeviceGroup::where('name', 'Neither')->first());

    $headers = clientHeaders($u);

    $folders = collect($this->getJson('/api/device-group/accessible?current=1&pageSize=100', $headers)->json('data'))->pluck('name')->all();
    expect($folders)->toEqualCanonicalizing(['Via Group', 'Direct']);

    expect($this->getJson('/api/peers?current=1&pageSize=100&accessible=&status=1', $headers)->json('total'))->toBe(2);
});

it('clears device-group grants when a user is made an admin', function () {
    $this->actingAs(admin());
    $target = plainUser();
    $g = DeviceGroup::create(['name' => 'A']);
    $target->deviceGroups()->attach($g);

    Livewire::test(UserList::class)
        ->call('edit', $target->id)
        ->set('is_admin', true)
        ->call('save');

    expect($target->fresh()->accessibleDeviceGroupIds())->toBe([]);
});
