<?php

use App\Livewire\DeviceList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

it('filters by first registration and includes the 24-hour boundary', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    Device::withoutEvents(function () {
        foreach (['new' => now()->subHour(), 'boundary' => now()->subHours(24), 'old' => now()->subHours(24)->subSecond(), 'future' => now()->addSecond()] as $id => $date) {
            $device = new Device(['rustdesk_id' => $id, 'uuid' => $id, 'status' => 'active', 'last_online_at' => now()]);
            $device->created_at = $date;
            $device->save();
        }
    });
    $this->actingAs($user);
    Livewire::test(DeviceList::class)->call('toggleNewDevices')
        ->assertSet('newOnly', true)
        ->assertViewHas('devices', fn ($rows) => $rows->pluck('rustdesk_id')->sort()->values()->all() === ['boundary', 'new'])
        ->assertViewHas('newCount', 2)
        ->assertSee(__('devices.add_device'))
        ->assertSee(__('devices.new_device_badge'))
        ->call('resetFilters')->assertSet('newOnly', false);
    expect(Device::where('rustdesk_id', 'boundary')->first()->isNewlyRegistered())->toBeTrue();
    expect(Device::where('rustdesk_id', 'old')->first()->isNewlyRegistered())->toBeFalse();
});

it('combines no-group, recent, owner and presence filters without widening visibility', function () {
    $this->travelTo(now()->startOfSecond());
    $viewer = User::create(['username' => 'viewer', 'password' => 'test']);
    $group = DeviceGroup::create(['name' => 'Customer']);
    Device::withoutEvents(function () use ($viewer, $group) {
        foreach ([['mine', null, $viewer->id], ['grouped', $group->id, $viewer->id], ['hidden', null, null]] as [$id, $gid, $uid]) {
            Device::create(['rustdesk_id' => $id, 'uuid' => $id, 'status' => 'active', 'user_id' => $uid, 'device_group_id' => $gid, 'last_online_at' => now()]);
        }
    });
    $this->actingAs($viewer);
    Livewire::test(DeviceList::class)->set('selected', ['999'])->set('group', -1)
        ->assertSet('selected', [])->call('toggleNewDevices')->set('status', 'online')
        ->assertViewHas('devices', fn ($rows) => $rows->pluck('rustdesk_id')->all() === ['mine']);
});

it('translates every new device filter label in all supported locales', function () {
    foreach (array_keys(User::LOCALES) as $locale) {
        $messages = require base_path('lang/'.$locale.'/devices.php');
        foreach (['new_last_24_hours', 'registered_last_24_hours', 'new_device_badge', 'no_group'] as $key) {
            expect($messages)->toHaveKey($key);
            expect(trim($messages[$key]))->not->toBe('');
        }
    }
});
