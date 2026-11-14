<?php

use App\Livewire\DeviceDetail;
use App\Livewire\DeviceList;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceDuplicateDismissal;
use App\Models\Role;
use App\Models\User;
use App\Services\DuplicateDevices;
use App\Services\FleetDiagnostics;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Possible duplicate devices (issue #91): one machine under two RustDesk IDs.
 */

function dupDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);

    $device = Device::create(array_merge([
        'rustdesk_id' => (string) (300000000 + $n),
        'uuid' => 'dup-uuid-'.$n,
        'hostname' => 'DUP-HOST-'.$n,
        'os' => 'windows / Windows 11 Pro',
        'username' => 'user'.$n,
        'version' => '1.4.2',
    ], $attrs));

    if ($createdAt !== null) {
        $device->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $device;
}

function dupMap(): DuplicateDevices
{
    return DuplicateDevices::in(Device::query()->approved());
}

it('pairs two IDs that report the same machine UUID', function () {
    $old = dupDevice(['uuid' => 'shared-uuid', 'last_online_at' => now()->subWeek()]);
    $new = dupDevice(['uuid' => 'shared-uuid', 'last_online_at' => now()]);
    dupDevice();

    $map = dupMap();

    expect($map->ids())->toEqualCanonicalizing([$old->id, $new->id])
        ->and($map->twinsOf($old->id)[0]['reason'])->toBe('uuid')
        ->and($map->rustdeskIdsOf($new->id))->toBe([$old->rustdesk_id]);
});

it('does not pair clones that report in side by side (#159)', function () {
    // Cloned from one image: same MachineGuid, both machines online for days.
    $a = dupDevice(['uuid' => 'cloned-guid', 'hostname' => 'POS-1', 'username' => 'till', 'created_at' => now()->subDays(10), 'last_online_at' => now()]);
    $b = dupDevice(['uuid' => 'cloned-guid', 'hostname' => 'POS-1', 'username' => 'till', 'created_at' => now()->subDays(9), 'last_online_at' => now()->subMinute()]);

    expect(dupMap()->count())->toBe(0)
        ->and(DuplicateDevices::of($a, Device::query()->approved())->count())->toBe(0);
});

it('still pairs a reinstall whose old ID went quiet', function () {
    $old = dupDevice(['uuid' => 'reinstalled', 'created_at' => now()->subMonth(), 'last_online_at' => now()->subDays(3)]);
    $new = dupDevice(['uuid' => 'reinstalled', 'created_at' => now()->subDays(3)->addMinutes(10), 'last_online_at' => now()]);

    expect(dupMap()->ids())->toEqualCanonicalizing([$old->id, $new->id]);
});

it('pairs same hostname, user and platform, ignoring case', function () {
    $a = dupDevice(['hostname' => 'DESKTOP-ABC123', 'username' => 'alice']);
    $b = dupDevice(['hostname' => 'desktop-abc123 ', 'username' => 'Alice']);

    expect(dupMap()->twinsOf($a->id))->toHaveCount(1)
        ->and(dupMap()->twinsOf($a->id)[0]['device']->id)->toBe($b->id)
        ->and(dupMap()->twinsOf($a->id)[0]['reason'])->toBe('hostname_user');
});

it('does not pair the same hostname with a different or empty user', function () {
    $a = dupDevice(['hostname' => 'FINANCE-07', 'username' => 'alice']);
    dupDevice(['hostname' => 'FINANCE-07', 'username' => 'bob']);
    dupDevice(['hostname' => 'FINANCE-07', 'username' => '']);
    dupDevice(['hostname' => 'FINANCE-07', 'username' => null]);
    dupDevice(['hostname' => 'FINANCE-07', 'username' => null]);

    expect(dupMap()->count())->toBe(0)
        ->and(DuplicateDevices::of($a, Device::query()->approved())->count())->toBe(0);
});

it('keeps the strongest reason when several signals match', function () {
    $a = dupDevice(['uuid' => 'both', 'hostname' => 'LAB-1', 'username' => 'bob']);
    dupDevice(['uuid' => 'both', 'hostname' => 'LAB-1', 'username' => 'bob']);

    expect(dupMap()->twinsOf($a->id))->toHaveCount(1)
        ->and(dupMap()->twinsOf($a->id)[0]['reason'])->toBe('uuid');
});

it('does not pair the same hostname on different platforms', function () {
    dupDevice(['hostname' => 'build-box', 'username' => 'ci', 'os' => 'linux / Ubuntu 24.04']);
    dupDevice(['hostname' => 'build-box', 'username' => 'ci', 'os' => 'windows / Windows 11 Pro']);

    expect(dupMap()->count())->toBe(0);
});

it('ignores generic hostnames, empty hostnames and phones', function () {
    foreach (['localhost', 'MacBook-Pro', 'macbook-pro-2', 'ubuntu', '', 'raspberrypi'] as $host) {
        dupDevice(['hostname' => $host, 'username' => 'same']);
        dupDevice(['hostname' => $host, 'username' => 'same']);
    }
    dupDevice(['hostname' => 'Pixel 8', 'username' => '', 'os' => 'android / Android 14']);
    dupDevice(['hostname' => 'Pixel 8', 'username' => '', 'os' => 'android / Android 14']);

    expect(dupMap()->count())->toBe(0)
        ->and(DuplicateDevices::isGenericHostname('MacBook-Air.local'))->toBeTrue()
        ->and(DuplicateDevices::isGenericHostname('DESKTOP-ABC123'))->toBeFalse();
});

it('drops a pair once one twin is in the recycle bin', function () {
    $a = dupDevice(['uuid' => 'gone']);
    $b = dupDevice(['uuid' => 'gone']);
    expect(dupMap()->has($a->id))->toBeTrue();

    $b->delete();

    expect(dupMap()->has($a->id))->toBeFalse();
});

it('leaves pending devices out', function () {
    $a = dupDevice(['uuid' => 'gate']);
    $pending = dupDevice(['uuid' => 'gate', 'status' => Device::STATUS_PENDING]);

    expect(dupMap()->count())->toBe(0)
        ->and(DuplicateDevices::of($pending, Device::query()->approved())->count())->toBe(0)
        ->and(DuplicateDevices::of($a, Device::query()->approved())->count())->toBe(0);
});

it('agrees between the fleet pass and the single-device pass', function () {
    $a = dupDevice(['uuid' => 'x1', 'hostname' => 'OPS-9', 'username' => 'ops']);
    $b = dupDevice(['uuid' => 'x1']);
    $c = dupDevice(['hostname' => 'ops-9', 'username' => 'ops']);

    $single = DuplicateDevices::of($a, Device::query()->approved());

    expect($single->rustdeskIdsOf($a->id))->toEqualCanonicalizing(dupMap()->rustdeskIdsOf($a->id))
        ->and($single->rustdeskIdsOf($a->id))->toEqualCanonicalizing([$b->rustdesk_id, $c->rustdesk_id]);
});

it('orders clusters so twins sit together', function () {
    $a1 = dupDevice(['uuid' => 'ca', 'last_online_at' => now()->subDays(3)]);
    $b1 = dupDevice(['uuid' => 'cb', 'last_online_at' => now()]);
    $a2 = dupDevice(['uuid' => 'ca', 'last_online_at' => now()->subDays(9)]);
    $b2 = dupDevice(['uuid' => 'cb', 'last_online_at' => now()->subDays(20)]);

    $order = dupMap()->clusterOrder();

    expect($order[$b1->id])->toBe($order[$b2->id])
        ->and($order[$a1->id])->toBe($order[$a2->id])
        ->and($order[$b1->id])->toBeLessThan($order[$a1->id]);
});

// ----------------------------------------------------------------- list ---

it('badges flagged rows and names the twin in the tooltip', function () {
    $old = dupDevice(['uuid' => 'tip']);
    $new = dupDevice(['uuid' => 'tip']);
    dupDevice();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceList::class)
        ->assertSee('Possible duplicate of '.$new->rustdesk_id.' (same device UUID)')
        ->assertSee('Possible duplicate of '.$old->rustdesk_id.' (same device UUID)')
        ->assertSee("filterByChip('duplicates')", false);
});

it('hides the chip when nothing is flagged', function () {
    dupDevice();
    dupDevice();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceList::class)
        ->assertDontSee('Possible duplicate of')
        ->assertDontSee("filterByChip('duplicates')", false);
});

it('filters to flagged devices with twins next to each other', function () {
    $a1 = dupDevice(['hostname' => 'ALPHA-PC', 'username' => 'a', 'last_online_at' => now()->subDays(2)]);
    $b1 = dupDevice(['hostname' => 'BRAVO-PC', 'username' => 'b', 'last_online_at' => now()]);
    $loner = dupDevice(['hostname' => 'CHARLIE-PC']);
    $a2 = dupDevice(['hostname' => 'ALPHA-PC', 'username' => 'a', 'last_online_at' => now()->subDays(30)]);
    $b2 = dupDevice(['hostname' => 'BRAVO-PC', 'username' => 'b', 'last_online_at' => now()->subDays(40)]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceList::class)
        ->call('filterByChip', 'duplicates')
        ->assertSet('status', 'duplicates')
        ->assertDontSee('CHARLIE-PC');

    $ids = $component->viewData('devices')->pluck('id')->all();
    expect($ids)->toBe([$b1->id, $b2->id, $a1->id, $a2->id]);
});

it('never reveals a twin outside the viewer fleet', function () {
    $role = Role::create(['name' => 'dup-viewer', 'permissions' => ['device' => 'rw']]);
    $scoped = User::factory()->create(['role_id' => $role->id]);
    $mine = dupDevice(['uuid' => 'cross', 'user_id' => $scoped->id]);
    $hidden = dupDevice(['uuid' => 'cross', 'hostname' => 'SECRET-HOST']);

    Livewire::actingAs($scoped)
        ->test(DeviceList::class)
        ->assertDontSee('Possible duplicate of')
        ->assertDontSee("filterByChip('duplicates')", false)
        ->assertDontSee($hidden->rustdesk_id);

    Livewire::actingAs($scoped)
        ->test(DeviceDetail::class, ['deviceId' => $mine->id])
        ->assertDontSee('Possible duplicate of')
        ->assertDontSee($hidden->rustdesk_id)
        ->call('confirmDeleteTwin', $hidden->id)
        ->assertNotFound();

    expect($hidden->fresh()->trashed())->toBeFalse();

    // An admin sees both.
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $mine->id])
        ->assertSee('Possible duplicate of '.$hidden->rustdesk_id);
});

it('renders the list with a fixed number of queries however many twins exist', function () {
    $admin = User::factory()->admin()->create();

    $count = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(DeviceList::class);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 2) as $i) {
        dupDevice(['uuid' => 'q'.$i]);
        dupDevice(['uuid' => 'q'.$i]);
    }
    $count(); // warm per-process caches
    $few = $count();

    foreach (range(3, 12) as $i) {
        dupDevice(['uuid' => 'q'.$i]);
        dupDevice(['hostname' => 'ROOM-'.$i, 'username' => 'x']);
        dupDevice(['uuid' => 'q'.$i, 'hostname' => 'ROOM-'.$i, 'username' => 'x']);
    }
    $many = $count();

    expect($many)->toBe($few);
});

// --------------------------------------------------------------- detail ---

it('shows the callout with the reason and last seen', function () {
    $old = dupDevice(['hostname' => 'RECEPTION', 'username' => 'desk', 'last_online_at' => now()->subDays(3)]);
    $new = dupDevice(['hostname' => 'RECEPTION', 'username' => 'desk', 'last_online_at' => now()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$new->id)
        ->assertOk()
        ->assertSee('Possible duplicate of '.$old->rustdesk_id)
        ->assertSee('Same hostname and user')
        ->assertSee('last seen 3 days ago')
        ->assertSee('Not a duplicate');
});

it('deletes the other device from the callout and stays on the page', function () {
    $old = dupDevice(['uuid' => 'del']);
    $new = dupDevice(['uuid' => 'del']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $new->id])
        ->call('confirmDeleteTwin', $old->id)
        ->assertSee('Delete device '.$old->rustdesk_id.'?')
        ->call('performDelete')
        ->assertNoRedirect()
        ->assertSet('duplicateResult', 'Device '.$old->rustdesk_id.' moved to the recycle bin.')
        ->assertDontSee('Possible duplicate of');

    expect($old->fresh()->trashed())->toBeTrue()
        ->and($new->fresh()->trashed())->toBeFalse();
});

it('still deletes this device and returns to the list', function () {
    $old = dupDevice(['uuid' => 'self']);
    $new = dupDevice(['uuid' => 'self']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $new->id])
        ->call('confirmDelete')
        ->call('performDelete')
        ->assertRedirect(route('devices'));

    expect($new->fresh()->trashed())->toBeTrue()
        ->and($old->fresh()->trashed())->toBeFalse();
});

it('refuses to delete a device that is not a twin through the callout', function () {
    $device = dupDevice();
    $stranger = dupDevice();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('confirmDeleteTwin', $stranger->id)
        ->assertNotFound();
});

it('dismisses a false positive, audits it, and stops flagging the pair', function () {
    $admin = User::factory()->admin()->create();
    $a = dupDevice(['hostname' => 'KIOSK-01', 'username' => 'kiosk']);
    $b = dupDevice(['hostname' => 'KIOSK-01', 'username' => 'kiosk']);
    $c = dupDevice(['hostname' => 'KIOSK-01', 'username' => 'kiosk']);

    Livewire::actingAs($admin)
        ->test(DeviceDetail::class, ['deviceId' => $a->id])
        ->call('dismissDuplicate', $b->id)
        ->assertSet('duplicateResult', 'Devices '.$a->rustdesk_id.' and '.$b->rustdesk_id.' will no longer be flagged.');

    expect(DeviceDuplicateDismissal::where(['device_id' => $a->id, 'other_device_id' => $b->id])->exists())->toBeTrue()
        ->and(dupMap()->rustdeskIdsOf($a->id))->toBe([$c->rustdesk_id])
        ->and(dupMap()->rustdeskIdsOf($b->id))->toBe([$c->rustdesk_id])
        ->and(ConsoleAudit::where('action', 'device.duplicate-dismiss')->value('summary'))
        ->toBe('Marked devices '.$a->rustdesk_id.' and '.$b->rustdesk_id.' as separate machines');

    // Dismissing from the other side is the same pair, not a second row.
    DeviceDuplicateDismissal::dismiss($b->id, $a->id, $admin->id);
    expect(DeviceDuplicateDismissal::count())->toBe(1);

    // Destroying a device removes its dismissals.
    $b->forceDelete();
    expect(DeviceDuplicateDismissal::count())->toBe(0);
});

it('needs device write to dismiss or delete from the callout', function () {
    $role = Role::create(['name' => 'dup-ro', 'permissions' => ['device' => 'r']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $a = dupDevice(['uuid' => 'ro', 'user_id' => $viewer->id]);
    $b = dupDevice(['uuid' => 'ro', 'user_id' => $viewer->id]);

    Livewire::actingAs($viewer)
        ->test(DeviceDetail::class, ['deviceId' => $a->id])
        ->assertSee('Possible duplicate of '.$b->rustdesk_id)
        ->assertDontSee('Not a duplicate')
        ->call('dismissDuplicate', $b->id)
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(DeviceDetail::class, ['deviceId' => $a->id])
        ->call('confirmDeleteTwin', $b->id)
        ->assertForbidden();

    expect(DeviceDuplicateDismissal::count())->toBe(0);
});

// ------------------------------------------------------- api, diagnostics ---

it('lists possible duplicates in admin API device payloads', function () {
    [, $plain] = ApiToken::issue(User::factory()->admin()->create(), 'dup', ['device' => 'r']);
    $headers = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $a = dupDevice(['uuid' => 'api']);
    $b = dupDevice(['uuid' => 'api']);
    $c = dupDevice();

    $list = collect($this->getJson('/api/v1/devices', $headers)->assertOk()->json('data'))->keyBy('id');

    expect($list[$a->id]['possible_duplicates'])->toBe([$b->rustdesk_id])
        ->and($list[$c->id]['possible_duplicates'])->toBe([]);

    $this->getJson('/api/v1/devices/'.$b->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.possible_duplicates', [$a->rustdesk_id]);
});

it('counts possible duplicates in diagnostics', function () {
    dupDevice(['uuid' => 'diag']);
    dupDevice(['uuid' => 'diag']);
    dupDevice();

    expect(app(FleetDiagnostics::class)->report()['fleet']['possible_duplicates'])->toBe(2);
});
