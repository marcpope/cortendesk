<?php

use App\Livewire\DeviceList;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

/*
 * Incoming-only devices (issue #82) and the LAN address column (issue #76)
 * on the Devices screens.
 */

function incomingDevice(string $id, array $attrs = []): Device
{
    return Device::create(['rustdesk_id' => $id, 'uuid' => 'u-'.$id, 'hostname' => 'host-'.$id] + $attrs);
}

it('can start sessions by default', function () {
    $device = incomingDevice('100000001')->fresh();

    expect($device->can_initiate)->toBeTrue()
        ->and($device->isIncomingOnly())->toBeFalse();
});

it('turns incoming only on and off in the editor and audits it', function () {
    $admin = User::factory()->admin()->create();
    $device = incomingDevice('100000001');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSet('formCanInitiate', true)
        ->set('formCanInitiate', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh()->isIncomingOnly())->toBeTrue()
        ->and(ConsoleAudit::where('action', 'device.initiate')->count())->toBe(1);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSet('formCanInitiate', false)
        ->set('formCanInitiate', true)
        ->call('save');

    expect($device->fresh()->isIncomingOnly())->toBeFalse();
});

it('sets incoming only on the selected devices in bulk', function () {
    $admin = User::factory()->admin()->create();
    $a = incomingDevice('100000001');
    $b = incomingDevice('100000002', ['can_initiate' => false]);
    $c = incomingDevice('100000003');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('setIncomingOnly', true)
        ->assertSet('bulkResult', '1 device set to incoming only.')
        ->assertSet('selected', []);

    expect($a->fresh()->isIncomingOnly())->toBeTrue()
        ->and($b->fresh()->isIncomingOnly())->toBeTrue()
        ->and($c->fresh()->isIncomingOnly())->toBeFalse();

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('setIncomingOnly', false)
        ->assertSet('bulkResult', '2 devices allowed to start sessions.');

    expect($a->fresh()->isIncomingOnly())->toBeFalse()
        ->and($b->fresh()->isIncomingOnly())->toBeFalse();
});

it('bulk changes only devices the user can see', function () {
    $user = User::factory()->create();
    $mine = incomingDevice('100000001', ['user_id' => $user->id]);
    $other = incomingDevice('100000002');

    Livewire::actingAs($user)->test(DeviceList::class)
        ->set('selected', [(string) $mine->id, (string) $other->id])
        ->call('setIncomingOnly', true);

    expect($mine->fresh()->isIncomingOnly())->toBeTrue()
        ->and($other->fresh()->isIncomingOnly())->toBeFalse();
});

it('shows an incoming only badge', function () {
    $admin = User::factory()->admin()->create();
    incomingDevice('100000001', ['can_initiate' => false]);

    Livewire::actingAs($admin)->test(DeviceList::class)->assertSee('Incoming only');

    $this->actingAs($admin)->get('/devices/'.Device::first()->id)
        ->assertOk()
        ->assertSee('Incoming only');
});

it('offers a LAN IP column, off by default', function () {
    $admin = User::factory()->admin()->create();
    $device = incomingDevice('100000001');
    $device->forceFill(['lan_ip' => '192.168.44.7', 'lan_ip_seen_at' => now()])->saveQuietly();

    expect(DeviceList::COLUMNS)->toHaveKey('lan_ip')
        ->and(DeviceList::DEFAULT_COLUMNS)->not->toContain('lan_ip');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertDontSee('192.168.44.7')
        ->set('columns', [...DeviceList::DEFAULT_COLUMNS, 'lan_ip'])
        ->assertSee('LAN IP')
        ->assertSee('192.168.44.7');
});

it('shows the LAN IP on the device page as seen during a connection', function () {
    $admin = User::factory()->admin()->create();
    $device = incomingDevice('100000001');

    $this->actingAs($admin)->get('/devices/'.$device->id)->assertOk()->assertSee('LAN IP');

    $device->forceFill(['lan_ip' => '192.168.44.7', 'lan_ip_seen_at' => now()->subHour()])->saveQuietly();
    $this->actingAs($admin)->get('/devices/'.$device->id)
        ->assertSee('192.168.44.7')
        ->assertSee('last seen during a connection 1 hour ago');
});

it('exports incoming only and the LAN IP after the existing columns', function () {
    $admin = User::factory()->admin()->create();
    $device = incomingDevice('100000001', ['can_initiate' => false]);
    $device->forceFill(['lan_ip' => '192.168.44.7', 'lan_ip_seen_at' => now()])->saveQuietly();

    $export = Livewire::actingAs($admin)->test(DeviceList::class)->call('exportCsv');
    $csv = $export->effects['download']['content'];
    $csv = base64_decode($csv, true) ?: $csv;
    $lines = explode("\n", trim($csv));

    expect($lines[0])->toEndWith('registered_ip,incoming_only,lan_ip,lan_ip_seen_at')
        ->and($lines[1])->toContain(',1,192.168.44.7,');
});
