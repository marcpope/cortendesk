<?php

use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

it('selects a created group without losing unsaved device fields', function () {
    $admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => 'test', 'status' => 'active']));
    $this->actingAs($admin);
    $editor = Livewire::test(DeviceList::class)->call('edit', $device->id)->set('formAlias', 'Unsaved alias')->set('formNote', 'Unsaved note')->call('selectEditorGroup', -1)->assertSet('newGroupDialogOpen', true);
    $token = $editor->get('groupCreationToken');
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => $token])->set('name', 'Customer group')->set('note', 'Group note')->call('save')->assertDispatched('device-editor-group-closed', token: $token);
    $group = DeviceGroup::where('name', 'Customer group')->firstOrFail();
    $editor->call('finishEditorGroup', $token)->assertSet('formGroupId', $group->id)->assertSet('formAlias', 'Unsaved alias')->assertSet('formNote', 'Unsaved note')->assertSet('newGroupDialogOpen', false)->call('save');
    expect($device->fresh()->device_group_id)->toBe($group->id);
    expect($device->fresh()->alias)->toBe('Unsaved alias');
});

it('preserves the selection on cancellation and ignores unrelated events', function () {
    $admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    $group = DeviceGroup::create(['name' => 'Existing']);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => 'test', 'device_group_id' => $group->id, 'status' => 'active']));
    $this->actingAs($admin);
    $editor = Livewire::test(DeviceList::class)->call('edit', $device->id)->call('selectEditorGroup', -1);
    $token = $editor->get('groupCreationToken');
    $editor->call('finishEditorGroup', 'unrelated')->assertSet('newGroupDialogOpen', true);
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => $token])->call('closeModal')->assertDispatched('device-editor-group-closed');
    $editor->call('finishEditorGroup', $token)->assertSet('formGroupId', $group->id)->assertSet('newGroupDialogOpen', false);
});

it('requires group management and validates new group names', function () {
    $role = Role::create(['name' => 'Device manager', 'permissions' => ['device' => 'rw', 'group' => 'r']]);
    $user = User::create(['username' => 'manager', 'password' => 'test', 'role_id' => $role->id]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => 'test', 'user_id' => $user->id, 'status' => 'active']));
    $this->actingAs($user);
    Livewire::test(DeviceList::class)->call('edit', $device->id)->assertDontSee(__('devices.create_new_group'))->call('selectEditorGroup', -1)->assertForbidden();
    $role->update(['permissions' => ['device' => 'rw', 'group' => 'rw']]);
    $this->actingAs($user->fresh());
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => 'validation'])->set('name', '')->call('save')->assertHasErrors(['name' => 'required'])->assertSet('showModal', true);
});

it('allows delegated creation without granting visibility to other groups', function () {
    $role = Role::create(['name' => 'Manager', 'permissions' => ['device' => 'rw', 'group' => 'rw']]);
    $user = User::create(['username' => 'manager', 'password' => 'test', 'role_id' => $role->id]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => 'test', 'user_id' => $user->id, 'status' => 'active']));
    $this->actingAs($user);
    $before = $user->accessibleDeviceGroupIds();
    $editor = Livewire::test(DeviceList::class)->call('edit', $device->id)->call('selectEditorGroup', -1);
    $token = $editor->get('groupCreationToken');
    Livewire::test(GroupList::class, ['deviceEditor' => true, 'editorToken' => $token])->set('name', 'New customer')->call('save');
    $group = DeviceGroup::where('name', 'New customer')->firstOrFail();
    $editor->call('finishEditorGroup', $token)->assertSet('formGroupId', $group->id)->call('save');
    expect($device->fresh()->device_group_id)->toBe($group->id);
    expect($user->fresh()->accessibleDeviceGroupIds())->toBe($before);
});

it('translates the creation option in every supported locale', function () {
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/devices.php');
        expect($messages)->toHaveKey('create_new_group');
        expect(trim($messages['create_new_group']))->not->toBe('');
    }
});
