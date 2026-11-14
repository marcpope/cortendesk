<?php

use App\Livewire\ActiveSessions;
use App\Livewire\DeviceList;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

/* #154: aliases in Active Sessions, IP sorting, a Strategy column, and the
   inherited strategy named in the edit modal. */

function ipDevice(string $id, ?string $ip): Device
{
    return Device::create(['rustdesk_id' => $id, 'uuid' => 'uuid-'.$id, 'last_online_ip' => $ip]);
}

it('sorts by IP as addresses, IPv4 before IPv6, blanks last', function () {
    $admin = User::factory()->admin()->create();
    ipDevice('100', '10.0.0.10');
    ipDevice('200', '10.0.0.9');
    ipDevice('300', '2001:db8::1');
    ipDevice('400', null);
    ipDevice('500', '1.1.1.100');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('sortBy', 'ip')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['500', '200', '100', '300', '400'])
        ->call('sortBy', 'ip')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['300', '100', '200', '500', '400']);
});

it('shows and sorts the strategy in force, for admins only', function () {
    $admin = User::factory()->admin()->create();
    $office = Strategy::create(['name' => 'Office', 'enabled' => true]);
    $kiosk = Strategy::create(['name' => 'Kiosk', 'enabled' => true]);
    $a = ipDevice('100', null);
    $b = ipDevice('200', null);
    ipDevice('300', null);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $a->id, $office->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $b->id, $kiosk->id);
    $admin->forceFill(['devices_columns' => ['device', 'strategy']])->save();

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertSee('Office')
        ->assertSee('Kiosk')
        ->call('sortBy', 'strategy')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['200', '100', '300']);

    $user = User::factory()->create();
    $user->forceFill(['devices_columns' => ['device', 'strategy']])->save();
    Livewire::actingAs($user)->test(DeviceList::class)
        ->assertViewHas('cols', fn ($cols) => $cols['strategy'] === false)
        ->call('sortBy', 'strategy')
        ->assertSet('sortField', 'last_seen');
});

it('names the strategy the device would inherit', function () {
    $admin = User::factory()->admin()->create();
    Strategy::create(['name' => 'Fleet default', 'enabled' => true, 'is_default' => true]);
    $device = ipDevice('100', null);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSee('Inherit (owner, group, or default): Fleet default');
});

it('shows the device alias in Active Sessions', function () {
    $admin = User::factory()->admin()->create();
    Device::create(['rustdesk_id' => '900000001', 'uuid' => 'u1', 'alias' => 'Front desk', 'hostname' => 'PC-01']);
    Device::create(['rustdesk_id' => '900000002', 'uuid' => 'u2', 'alias' => 'Admin laptop']);
    AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => '900000001', 'from_peer' => '900000002', 'from_name' => '', 'conn_type' => 0]);

    Livewire::actingAs($admin)->test(ActiveSessions::class)
        ->assertSee('Front desk')
        ->assertSee('Admin laptop')
        ->assertDontSee('PC-01');
});
