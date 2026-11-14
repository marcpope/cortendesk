<?php

use App\Livewire\GroupList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

it('renders the groups page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/groups')
        ->assertOk()
        ->assertSeeLivewire(GroupList::class);
});

it('creates a device group', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(GroupList::class)
        ->call('create', 'devices')
        ->assertSet('showModal', true)
        ->set('name', 'Office')
        ->set('note', 'Head office machines')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('device_groups', ['name' => 'Office', 'note' => 'Head office machines']);
});

it('renames a device group', function () {
    $admin = User::factory()->admin()->create();
    $group = DeviceGroup::create(['name' => 'Old Name', 'note' => 'note']);

    Livewire::actingAs($admin)
        ->test(GroupList::class)
        ->call('edit', 'devices', $group->id)
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('note', 'updated note')
        ->call('save')
        ->assertHasNoErrors();

    $group->refresh();
    expect($group->name)->toBe('New Name')
        ->and($group->note)->toBe('updated note');
});

it('deletes a device group and nulls its devices group id', function () {
    $admin = User::factory()->admin()->create();
    $group = DeviceGroup::create(['name' => 'Doomed']);

    $device = Device::create([
        'rustdesk_id' => '123456789',
        'uuid' => 'test-uuid-1',
        'device_group_id' => $group->id,
    ]);

    Livewire::actingAs($admin)
        ->test(GroupList::class)
        ->call('deleteGroup', 'devices', $group->id);

    expect(DeviceGroup::find($group->id))->toBeNull()
        ->and($device->fresh()->device_group_id)->toBeNull()
        ->and(Device::find($device->id))->not->toBeNull();
});
