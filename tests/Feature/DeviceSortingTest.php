<?php

use App\Livewire\DeviceList;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

/*
 * Device list ordering (issue #27). The complaint that started it was rows
 * moving under wire:poll while aliases were being edited, so the tie-breaker
 * and the blanks-last rules matter as much as the column choice itself.
 */

function sortDevice(string $id, ?string $alias = null, ?DeviceGroup $group = null, ?User $owner = null, $seenAt = null): Device
{
    return Device::create([
        'rustdesk_id' => $id,
        'uuid' => 'uuid-'.$id,
        'alias' => $alias,
        'device_group_id' => $group?->id,
        'user_id' => $owner?->id,
        'last_online_at' => $seenAt,
    ]);
}

function idsInOrder($devices): array
{
    return $devices->pluck('rustdesk_id')->all();
}

it('sorts by alias, reverses on a second click, and keeps blanks last', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', alias: 'Zulu', seenAt: now()->subMinute());
    sortDevice('200', alias: null, seenAt: now());
    sortDevice('300', alias: 'Alpha', seenAt: now()->subMinutes(2));

    $component = Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'alias')
        ->assertSet('sortField', 'alias')
        ->assertSet('sortDirection', 'asc')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['300', '100', '200']);

    $component->call('sortBy', 'alias')
        ->assertSet('sortDirection', 'desc')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '300', '200']);
});

it('treats an empty string as missing rather than as a name', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', alias: '');
    sortDevice('200', alias: 'Alpha');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'alias')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['200', '100']);
});

it('sorts by group, owner, status and last seen', function () {
    $admin = User::factory()->admin()->create();
    $alpha = DeviceGroup::create(['name' => 'Alpha']);
    $zulu = DeviceGroup::create(['name' => 'Zulu']);
    $amy = User::factory()->create(['username' => 'amy']);
    $zoe = User::factory()->create(['username' => 'zoe']);

    sortDevice('100', group: $zulu, owner: $amy, seenAt: now()->subMinutes(10));
    sortDevice('200', group: $alpha, owner: $zoe, seenAt: now());
    sortDevice('300', group: null, owner: null, seenAt: null);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'group')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['200', '100', '300'])
        ->call('sortBy', 'owner')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '200', '300'])
        ->call('sortBy', 'status')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '300', '200'])
        ->call('sortBy', 'status')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['200', '100', '300'])
        ->call('sortBy', 'last_seen')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '200', '300']);
});

it('breaks ties on the rustdesk id so equal rows hold still', function () {
    $admin = User::factory()->admin()->create();
    $seen = now()->subMinutes(5);
    sortDevice('300', alias: 'Same', seenAt: $seen);
    sortDevice('100', alias: 'Same', seenAt: $seen);
    sortDevice('200', alias: 'Same', seenAt: $seen);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'alias')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '200', '300']);
});

it('remembers the sort across a fresh mount', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', alias: 'Zulu');
    sortDevice('200', alias: 'Alpha');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'alias')
        ->call('sortBy', 'alias');

    $admin->refresh();
    expect($admin->devices_sort)->toBe('alias')
        ->and($admin->devices_sort_direction)->toBe('desc');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertSet('sortField', 'alias')
        ->assertSet('sortDirection', 'desc')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['100', '200']);
});

it('defaults to last seen descending, as it did before it was sortable', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', seenAt: now()->subMinutes(2));
    sortDevice('200', seenAt: now());
    sortDevice('300', seenAt: null);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertSet('sortField', 'last_seen')
        ->assertSet('sortDirection', 'desc')
        ->assertViewHas('devices', fn ($d) => idsInOrder($d) === ['200', '100', '300']);
});

it('exports the CSV in the order shown', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', seenAt: now()->subMinutes(2));
    sortDevice('200', seenAt: now());

    $component = Livewire::actingAs($admin)->test(DeviceList::class)->call('sortBy', 'id');

    ob_start();
    $component->instance()->exportCsv()->sendContent();
    $csv = (string) ob_get_clean();

    expect(strpos($csv, ',100,'))->toBeLessThan(strpos($csv, ',200,'));
});

it('refuses owner sorting for a non-admin and ignores unknown fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(DeviceList::class)
        ->call('sortBy', 'owner')
        ->assertSet('sortField', 'last_seen')
        ->call('sortBy', 'drop table devices')
        ->assertSet('sortField', 'last_seen');
});

it('falls back to the default when a saved sort is no longer permitted', function () {
    // Sorted by owner as an admin, then demoted: the stored preference is now
    // one they are not allowed to use.
    $user = User::factory()->create(['is_admin' => false]);
    $user->forceFill(['devices_sort' => 'owner', 'devices_sort_direction' => 'asc'])->save();

    Livewire::actingAs($user)->test(DeviceList::class)
        ->assertSet('sortField', 'last_seen')
        ->assertSet('sortDirection', 'desc');
});

it('does not reverse when the mobile picker re-selects the current field', function () {
    $admin = User::factory()->admin()->create();
    sortDevice('100', alias: 'Alpha');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('selectSort', 'alias')
        ->assertSet('sortDirection', 'asc')
        ->call('selectSort', 'alias')
        ->assertSet('sortDirection', 'asc');
});
