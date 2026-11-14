<?php

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;

// #126: device groups feed the ID whitelist.

function whitelistDevice(string $id, ?DeviceGroup $group = null): Device
{
    return Device::create([
        'rustdesk_id' => $id,
        'uuid' => 'uuid-'.$id,
        'device_group_id' => $group?->id,
        'status' => Device::STATUS_ACTIVE,
    ]);
}

it('stores ticked groups sorted and unique', function () {
    expect(Strategy::sanitizeOptions(['id-whitelist-groups' => '7, 3,x,3,0']))
        ->toBe(['id-whitelist-groups' => '3,7']);
});

it('pushes the group members as the ID whitelist, never the group key', function () {
    $admins = DeviceGroup::create(['name' => 'IT']);
    whitelistDevice('111111111', $admins);
    whitelistDevice('222222222', $admins);
    whitelistDevice('333333333');

    $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true]);
    $strategy->setOptions(['id-whitelist' => 'web-1', 'id-whitelist-groups' => (string) $admins->id]);
    $strategy->save();

    expect($strategy->configOptions())->toBe(['id-whitelist' => 'web-1,111111111,222222222']);
});

it('follows membership changes without a strategy save', function () {
    $group = DeviceGroup::create(['name' => 'IT']);
    whitelistDevice('111111111', $group);
    $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true]);
    $strategy->setOptions(['id-whitelist-groups' => (string) $group->id]);
    $strategy->save();

    expect($strategy->configOptions()['id-whitelist'])->toBe('111111111');

    whitelistDevice('444444444', $group);

    expect($strategy->configOptions()['id-whitelist'])->toBe('111111111,444444444');
});

it('fails closed when the groups hold no devices', function () {
    $empty = DeviceGroup::create(['name' => 'Empty']);
    $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true]);
    $strategy->setOptions(['id-whitelist-groups' => (string) $empty->id]);
    $strategy->save();

    expect($strategy->configOptions())->toBe(['id-whitelist' => Strategy::ID_WHITELIST_NOBODY]);
});

it('delivers the expanded list to a device and resends when the group changes', function () {
    $group = DeviceGroup::create(['name' => 'IT']);
    whitelistDevice('111111111', $group);
    $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true, 'is_default' => true]);
    $strategy->setOptions(['id-whitelist-groups' => (string) $group->id]);
    $strategy->save();

    $target = whitelistDevice('999999999');
    Strategy::syncResolvedFor($target);
    $target->refresh();

    $first = Strategy::deliveryFor($target, 0);
    expect($first['strategy']['config_options'])->toBe(['id-whitelist' => '111111111']);

    $target->refresh();
    expect(Strategy::deliveryFor($target, $first['modified_at']))->toBeNull();

    whitelistDevice('222222222', $group);
    $target->refresh();
    $second = Strategy::deliveryFor($target, $first['modified_at']);
    expect($second['strategy']['config_options'])->toBe(['id-whitelist' => '111111111,222222222'])
        ->and($second['modified_at'])->toBeGreaterThan($first['modified_at']);
});

it('saves ticked groups from the editor and shows them again on edit', function () {
    $it = DeviceGroup::create(['name' => 'IT']);
    $ops = DeviceGroup::create(['name' => 'Ops']);
    $gone = DeviceGroup::create(['name' => 'Gone']);

    $component = Livewire\Livewire::actingAs(App\Models\User::factory()->admin()->create())
        ->test(App\Livewire\StrategyList::class)
        ->call('create')
        ->assertSee('IT')
        ->set('formName', 'Admins only')
        ->set('formIdWhitelistGroups', [(string) $ops->id, (string) $it->id, (string) $gone->id]);
    $gone->delete();
    $component->call('save')->call('confirmSave')->assertHasNoErrors();

    $strategy = Strategy::where('name', 'Admins only')->first();
    expect($strategy->optionMap())->toBe(['id-whitelist-groups' => $it->id.','.$ops->id]);

    $component->call('edit', $strategy->id)
        ->assertSet('formIdWhitelistGroups', [(string) $it->id, (string) $ops->id]);
});
