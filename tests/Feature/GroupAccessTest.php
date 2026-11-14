<?php

use App\Livewire\GroupList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\GroupAccess;
use App\Models\User;
use App\Models\UserGroup;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| B4 — group↔group visibility ("accessed from"). Reuses the admin(),
| plainUser(), deviceIn() and clientHeaders() helpers from AccessScopingTest.
|--------------------------------------------------------------------------
*/

// ------------------------------------------------- user_group → user_group ----

it('does not show members of another user group without an access grant', function () {
    $u = plainUser();
    $stranger = plainUser();
    $mine = UserGroup::create(['name' => 'Mine']);
    $theirs = UserGroup::create(['name' => 'Theirs']);
    $mine->users()->attach($u);
    $theirs->users()->attach($stranger);

    expect($u->fresh()->visibleUserIds())->toEqualCanonicalizing([$u->id]);

    $names = collect($this->getJson('/api/users?current=1&pageSize=100&status=1', clientHeaders($u))->json('data'))
        ->pluck('name');
    expect($names)->toContain($u->username)->not->toContain($stranger->username);
});

it('shows members of a user group accessed_from one of my groups (/api/users)', function () {
    $u = plainUser();
    $stranger = plainUser();
    $mine = UserGroup::create(['name' => 'Mine']);
    $theirs = UserGroup::create(['name' => 'Theirs']);
    $mine->users()->attach($u);
    $theirs->users()->attach($stranger);

    // "Theirs" is accessed from "Mine": Mine's members may see Theirs' members.
    $theirs->syncAccessorUserGroups([$mine->id]);

    expect($u->fresh()->visibleUserIds())->toEqualCanonicalizing([$u->id, $stranger->id]);

    $names = collect($this->getJson('/api/users?current=1&pageSize=100&status=1', clientHeaders($u))->json('data'))
        ->pluck('name');
    expect($names)->toContain($u->username)->toContain($stranger->username);

    // Access is one-directional: the stranger still cannot see me.
    expect($stranger->fresh()->visibleUserIds())->toEqualCanonicalizing([$stranger->id]);
});

it('grants access to an individual user via group_accesses (accessor = user)', function () {
    $u = plainUser();
    $stranger = plainUser();
    $theirs = UserGroup::create(['name' => 'Theirs']);
    $theirs->users()->attach($stranger);

    GroupAccess::create([
        'accessor_type' => GroupAccess::ACCESSOR_USER,
        'accessor_id' => $u->id,
        'target_type' => GroupAccess::TARGET_USER_GROUP,
        'target_id' => $theirs->id,
    ]);

    expect($u->fresh()->visibleUserIds())->toEqualCanonicalizing([$u->id, $stranger->id]);
});

// --------------------------------------------- device_group "accessed from" ----

it('grants folder visibility to a user group via group_accesses (device targets)', function () {
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach($u);

    $folder = DeviceGroup::create(['name' => 'Accessed']);
    $other = DeviceGroup::create(['name' => 'Other']);

    // Folder is accessed from Team — via the symmetric device-group editor.
    $folder->syncAccessorUserGroups([$team->id]);

    expect($u->fresh()->accessibleDeviceGroupIds())->toEqualCanonicalizing([$folder->id]);

    $seen = deviceIn($folder);
    $hidden = deviceIn($other);
    $ids = Device::visibleTo($u->fresh())->pluck('id');
    expect($ids)->toContain($seen->id)->not->toContain($hidden->id);

    // And through the client group tab.
    $res = $this->getJson('/api/peers?current=1&pageSize=100&status=1', clientHeaders($u))->json();
    expect(collect($res['data'])->pluck('id'))->toContain($seen->rustdesk_id)
        ->not->toContain($hidden->rustdesk_id);
});

it('unions group_accesses folder grants with the existing folder tables', function () {
    $u = plainUser();
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach($u);

    $viaGroupTable = DeviceGroup::create(['name' => 'Via device_group_user_group']);
    $viaAccess = DeviceGroup::create(['name' => 'Via group_accesses']);
    $direct = DeviceGroup::create(['name' => 'Direct per-user']);

    $team->deviceGroups()->attach($viaGroupTable);   // existing table
    $viaAccess->syncAccessorUserGroups([$team->id]);  // new group_accesses
    $u->deviceGroups()->attach($direct);              // existing per-user table

    expect($u->fresh()->accessibleDeviceGroupIds())
        ->toEqualCanonicalizing([$viaGroupTable->id, $viaAccess->id, $direct->id]);
});

// ------------------------------------------------------ console editor / UI ----

it('edits "accessed from" on the device-group editor and persists group_accesses', function () {
    $this->actingAs(admin());
    $folder = DeviceGroup::create(['name' => 'Ops']);
    $team = UserGroup::create(['name' => 'Team']);

    Livewire::test(GroupList::class)
        ->call('edit', 'devices', $folder->id)
        ->set('accessor_group_ids', [$team->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($folder->fresh()->accessorUserGroupIds())->toBe([$team->id]);

    // Clearing it removes the grant.
    Livewire::test(GroupList::class)
        ->call('edit', 'devices', $folder->id)
        ->set('accessor_group_ids', [])
        ->call('save');

    expect($folder->fresh()->accessorUserGroupIds())->toBe([]);
});

it('edits "accessed from" on the user-group editor and ignores a self-reference', function () {
    $this->actingAs(admin());
    $target = UserGroup::create(['name' => 'Target']);
    $accessor = UserGroup::create(['name' => 'Accessor']);

    Livewire::test(GroupList::class)
        ->call('edit', 'users', $target->id)
        ->set('accessor_group_ids', [$accessor->id, $target->id]) // self must be dropped
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->accessorUserGroupIds())->toBe([$accessor->id]);
});

it('purges group_accesses rows when a group is deleted', function () {
    $this->actingAs(admin());
    $folder = DeviceGroup::create(['name' => 'Ops']);
    $team = UserGroup::create(['name' => 'Team']);
    $folder->syncAccessorUserGroups([$team->id]);

    expect(GroupAccess::count())->toBe(1);

    // Deleting the accessor user group clears the row.
    Livewire::test(GroupList::class)->call('deleteGroup', 'users', $team->id);
    expect(GroupAccess::count())->toBe(0);

    // Same when the target device group is deleted.
    $team2 = UserGroup::create(['name' => 'Team2']);
    $folder->fresh()->syncAccessorUserGroups([$team2->id]);
    expect(GroupAccess::count())->toBe(1);

    Livewire::test(GroupList::class)->call('deleteGroup', 'devices', $folder->id);
    expect(GroupAccess::count())->toBe(0);
});
