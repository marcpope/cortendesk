<?php

use App\Livewire\DeviceList;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

/*
 * Bulk move-to-group (PR #50 / issue #47).
 */

it('moves selected devices to an accessible group and audits once', function () {
    $admin = User::factory()->admin()->create();
    $group = DeviceGroup::create(['name' => 'Workshop']);
    $a = Device::create(['rustdesk_id' => '100', 'uuid' => 'a']);
    $b = Device::create(['rustdesk_id' => '200', 'uuid' => 'b', 'device_group_id' => $group->id]);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('openGroupPicker')
        ->set('moveGroupId', $group->id)
        ->call('moveSelectedToGroup')
        ->assertHasNoErrors();

    expect($a->fresh()->device_group_id)->toBe($group->id)
        ->and($b->fresh()->device_group_id)->toBe($group->id)
        ->and(ConsoleAudit::where('action', 'device.group-move')->count())->toBe(1);
});

it('moves to no group', function () {
    $admin = User::factory()->admin()->create();
    $group = DeviceGroup::create(['name' => 'Workshop']);
    $a = Device::create(['rustdesk_id' => '100', 'uuid' => 'a', 'device_group_id' => $group->id]);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id])
        ->set('moveGroupId', 0)
        ->call('moveSelectedToGroup');

    expect($a->fresh()->device_group_id)->toBeNull();
});

it('rejects a group outside the actor scope without mutating', function () {
    $user = User::factory()->create(); // no admin, no group grants
    $group = DeviceGroup::create(['name' => 'Forbidden']);
    $mine = Device::create(['rustdesk_id' => '100', 'uuid' => 'a', 'user_id' => $user->id]);

    Livewire::actingAs($user)->test(DeviceList::class)
        ->set('selected', [(string) $mine->id])
        ->set('moveGroupId', $group->id)
        ->call('moveSelectedToGroup')
        ->assertHasErrors('moveGroupId');

    expect($mine->fresh()->device_group_id)->toBeNull()
        ->and(ConsoleAudit::where('action', 'device.group-move')->count())->toBe(0);
});

it('requires a target selection', function () {
    $admin = User::factory()->admin()->create();
    $a = Device::create(['rustdesk_id' => '100', 'uuid' => 'a']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id])
        ->call('moveSelectedToGroup')
        ->assertHasErrors('moveGroupId');
});
