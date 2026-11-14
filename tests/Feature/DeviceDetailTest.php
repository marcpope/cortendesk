<?php

use App\Livewire\DeviceDetail;
use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/*
 * Device detail page (issue #35).
 */

function detailDevice(array $attrs = []): Device
{
    return Device::create(array_merge([
        'rustdesk_id' => '123456789',
        'uuid' => 'detail-uuid',
        'hostname' => 'FRONT-DESK',
        'os' => 'windows / Windows 11 Pro',
        'cpu' => 'Ryzen 5, 6/6 cores',
        'memory' => '16GB',
        'version' => '1.4.2',
        'note' => 'Reception machine',
    ], $attrs));
}

it('shows the facts the client reported', function () {
    $device = detailDevice();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertOk()
        ->assertSee('FRONT-DESK')
        ->assertSee('Windows 11 Pro')
        ->assertSee('Ryzen 5')
        ->assertSee('16GB')
        ->assertSee('Reception machine');
});

it('404s a device outside the viewer fleet', function () {
    $device = detailDevice(); // unowned, in no group

    $this->actingAs(User::factory()->create())
        ->get('/devices/'.$device->id)
        ->assertNotFound();
});

it('edits the note and audits it', function () {
    $device = detailDevice();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('editNote')
        ->set('note', 'Moved to the back office')
        ->call('saveNote');

    expect($device->fresh()->note)->toBe('Moved to the back office');
    $this->assertDatabaseHas('console_audits', ['action' => 'device.update', 'target_id' => $device->rustdesk_id]);
});

it('shows recent activity only with the audit permission', function () {
    $device = detailDevice(['user_id' => null]);
    AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => $device->rustdesk_id, 'from_peer' => '555', 'from_name' => 'tech-laptop', 'ip' => '10.0.0.9', 'uuid' => 'c1']);
    AlarmLog::create(['rustdesk_id' => $device->rustdesk_id, 'uuid' => 'a1', 'typ' => 1, 'info' => '{}']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('tech-laptop');

    // A role with devices but no audit: the history sections are withheld.
    $role = Role::create(['name' => 'devices-only', 'permissions' => ['device' => 'rw']]);
    $limited = User::factory()->create(['role_id' => $role->id]);
    $device->update(['user_id' => $limited->id]);

    $this->actingAs($limited)
        ->get('/devices/'.$device->id)
        ->assertOk()
        ->assertDontSee('tech-laptop')
        ->assertSee('Activity history needs the audit permission');
});

it('lists the sessions and transfers a device started, naming the device it reached (#149)', function () {
    $phone = detailDevice(['rustdesk_id' => '111222333', 'uuid' => 'phone', 'hostname' => 'iPhone', 'user_id' => null]);
    $desk = detailDevice(['rustdesk_id' => '444555666', 'uuid' => 'desk', 'hostname' => 'DESK-1', 'alias' => 'Front desk', 'user_id' => null]);
    $hidden = detailDevice(['rustdesk_id' => '777888999', 'uuid' => 'hidden', 'hostname' => 'SECRET-PC', 'user_id' => null]);
    AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => $desk->rustdesk_id, 'from_peer' => $phone->rustdesk_id, 'from_name' => 'iPhone', 'ip' => '10.0.0.9', 'uuid' => 'o1']);
    AuditConnection::create(['action' => 'new', 'conn_id' => 2, 'rustdesk_id' => $hidden->rustdesk_id, 'from_peer' => $phone->rustdesk_id, 'from_name' => 'iPhone', 'ip' => '10.0.0.9', 'uuid' => 'o2']);
    AuditFileTransfer::create([
        'rustdesk_id' => $desk->rustdesk_id, 'from_peer' => $phone->rustdesk_id, 'from_name' => 'iPhone', 'path' => 'C:\\Users\\Public\\payroll.xlsx',
        'info' => '{}', 'is_file' => true, 'direction' => 1, 'file_count' => 1, 'ip' => '10.0.0.9', 'uuid' => 'f1',
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$phone->id)
        ->assertSee(__('devices.outgoing'))
        ->assertSee('Front desk')
        ->assertSee(route('devices.show', $desk->id), false)
        ->assertSee('SECRET-PC')
        ->assertSee('payroll.xlsx');

    // Someone who sees only some devices sees outgoing sessions to those only.
    $role = Role::create(['name' => 'scoped', 'permissions' => ['device' => 'r', 'audit' => 'r']]);
    $scoped = User::factory()->create(['role_id' => $role->id]);
    $phone->update(['user_id' => $scoped->id]);
    $desk->update(['user_id' => $scoped->id]);

    $this->actingAs($scoped)
        ->get('/devices/'.$phone->id)
        ->assertOk()
        ->assertSee('Front desk')
        ->assertDontSee('777888999')
        ->assertDontSee('SECRET-PC');
});

it('lists only address books the viewer could open', function () {
    $device = detailDevice();
    $owner = User::factory()->create(['username' => 'someone-else']);
    $book = AddressBook::create(['name' => 'Private team book', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $book->entries()->create(['rustdesk_id' => $device->rustdesk_id]);

    // Admin sees it; the book is invisible to an unrelated user page-wide
    // (they cannot even open the device, being unowned — give them the device).
    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('Private team book');

    $viewer = User::factory()->create();
    $device->update(['user_id' => $viewer->id]);
    $this->actingAs($viewer)
        ->get('/devices/'.$device->id)
        ->assertOk()
        ->assertDontSee('Private team book');
});

/*
 * Registration IP (issue #46): captured once when the device first appears,
 * never rewritten by later traffic.
 */

it('records the IP a device registers from and keeps it', function () {
    $this->postJson('/api/sysinfo', [
        'id' => '555000111', 'uuid' => 'reg-ip-uuid', 'hostname' => 'new-box',
    ], ['REMOTE_ADDR' => '203.0.113.7']);

    $device = Device::where('rustdesk_id', '555000111')->sole();
    expect($device->registered_ip)->not->toBeNull()
        ->and($device->registered_ip)->toBe($device->last_online_ip);
    $registered = $device->registered_ip;

    // Later sysinfo and heartbeats move last_online_ip, not registered_ip.
    $this->postJson('/api/sysinfo', [
        'id' => '555000111', 'uuid' => 'reg-ip-uuid', 'hostname' => 'new-box-renamed',
    ]);
    $this->postJson('/api/heartbeat', ['id' => '555000111', 'uuid' => 'reg-ip-uuid']);

    expect($device->fresh()->registered_ip)->toBe($registered);
});

it('shows the registration IP on the detail page', function () {
    $device = detailDevice(['registered_ip' => '198.51.100.44']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('Registered from')
        ->assertSee('198.51.100.44');
});

/*
 * Detail page feedback (issue #54).
 */

it('links the alias in the device list to the detail page', function () {
    $device = detailDevice(['alias' => 'Reception PC']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices')
        ->assertOk()
        ->assertSee('href="'.route('devices.show', $device->id).'" class="rd-cell-link">Reception PC</a>', false);
});

it('edits the device from the detail page with the list editor', function () {
    $device = detailDevice();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/devices/'.$device->id)
        ->assertSee('wire:click="editDevice"', false)
        ->assertSee('wire:click="confirmDelete"', false);

    Livewire::actingAs($admin)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('editDevice')
        ->assertSet('editingId', $device->id)
        ->assertSee('Edit Device')
        ->set('formAlias', 'Front desk')
        ->call('save')
        ->assertSet('editingId', null)
        ->assertSee('Front desk');

    expect($device->fresh()->alias)->toBe('Front desk');
    $this->assertDatabaseHas('console_audits', ['action' => 'device.update', 'summary' => 'Updated device '.$device->rustdesk_id]);
});

it('hides edit and delete from a view-only role and refuses them', function () {
    $role = Role::create(['name' => 'viewer', 'permissions' => ['device' => 'r']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $device = detailDevice(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->get('/devices/'.$device->id)
        ->assertOk()
        ->assertDontSee('wire:click="editDevice"', false)
        ->assertDontSee('wire:click="confirmDelete"', false);

    Livewire::actingAs($viewer)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('editDevice')
        ->assertForbidden();
});

it('shows the full UUID and the IP without the monospace class', function () {
    $uuid = 'd2f1c0de-4b5a-4e3b-9c1d-0123456789ab-extra-long-tail';
    $device = detailDevice(['uuid' => $uuid, 'last_online_ip' => '203.0.113.9']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee($uuid)
        ->assertSee('<dd>203.0.113.9</dd>', false);
});

it('colours file-transfer arrows by direction, matching the transfer log', function () {
    $device = detailDevice();
    AuditFileTransfer::create(['rustdesk_id' => $device->rustdesk_id, 'path' => '/tmp/sent.txt', 'direction' => 0]);
    AuditFileTransfer::create(['rustdesk_id' => $device->rustdesk_id, 'path' => '/tmp/got.txt', 'direction' => 1]);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('ri-arrow-up-line text-info', false)
        ->assertSee('ri-arrow-down-line text-warning', false);
});

it('points the arrow from this device on an outgoing transfer (#149)', function () {
    $phone = detailDevice(['rustdesk_id' => '111222333', 'uuid' => 'phone', 'hostname' => 'iPhone']);
    $desk = detailDevice(['rustdesk_id' => '444555666', 'uuid' => 'desk', 'hostname' => 'DESK-1']);
    // Type 0: the file left the controlled machine (the desk) for the phone.
    AuditFileTransfer::create(['rustdesk_id' => $desk->rustdesk_id, 'from_peer' => $phone->rustdesk_id, 'path' => '/tmp/a.txt', 'direction' => 0]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/devices/'.$desk->id)
        ->assertSee('ri-arrow-up-line text-info', false)
        ->assertDontSee('ri-arrow-down-line text-warning', false);
    $this->actingAs($admin)->get('/devices/'.$phone->id)
        ->assertSee('ri-arrow-down-line text-warning', false)
        ->assertDontSee('ri-arrow-up-line text-info', false);
});

it('names a known device that connected in by its alias, linked (#149)', function () {
    $desk = detailDevice(['rustdesk_id' => '444555666', 'uuid' => 'desk', 'hostname' => 'DESK-1']);
    $laptop = detailDevice(['rustdesk_id' => '111222333', 'uuid' => 'laptop', 'hostname' => 'LT-0042', 'alias' => 'Marc laptop', 'user_id' => null]);
    AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => $desk->rustdesk_id, 'from_peer' => $laptop->rustdesk_id, 'from_name' => 'lt-0042', 'ip' => '10.0.0.9', 'uuid' => 'i1']);
    AuditConnection::create(['action' => 'new', 'conn_id' => 2, 'rustdesk_id' => $desk->rustdesk_id, 'from_peer' => '999000111', 'from_name' => 'stranger-pc', 'ip' => '10.0.0.8', 'uuid' => 'i2']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$desk->id)
        ->assertSee('Marc laptop')
        ->assertSee(route('devices.show', $laptop->id), false)
        ->assertSee('stranger-pc');

    // A device the viewer cannot open stays as the name its client reported.
    $role = Role::create(['name' => 'scoped', 'permissions' => ['device' => 'r', 'audit' => 'r']]);
    $scoped = User::factory()->create(['role_id' => $role->id]);
    $desk->update(['user_id' => $scoped->id]);
    $this->actingAs($scoped)
        ->get('/devices/'.$desk->id)
        ->assertSee('lt-0042')
        ->assertDontSee('Marc laptop');
});

it('lists notifications sent about this device', function () {
    $device = detailDevice();
    $alarm = AlarmLog::create(['rustdesk_id' => $device->rustdesk_id, 'uuid' => 'a1', 'typ' => 1, 'info' => '{}']);
    NotificationDelivery::create(['event' => 'device.offline', 'subject' => 'device:'.$device->rustdesk_id, 'status' => 'sent', 'title' => 'Device offline']);
    NotificationDelivery::create(['event' => 'security.alarm', 'subject' => 'alarm:'.$alarm->id, 'status' => 'failed', 'title' => 'Alarm', 'error' => 'HTTP 500']);
    NotificationDelivery::create(['event' => 'device.online', 'subject' => 'device:999999999', 'status' => 'sent', 'title' => 'Other device']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('Notifications')
        ->assertSee('Device offline')
        ->assertSee('Security alarm')
        ->assertSee('HTTP 500')
        ->assertDontSee('Device recovered');
});

it('adds the device to a writable book and removes it again, audited', function () {
    $user = User::factory()->create();
    $device = detailDevice(['user_id' => $user->id]);
    $owner = User::factory()->create();
    $shared = AddressBook::create(['name' => 'Ops shared', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $shared->rules()->create(['subject_type' => 'user', 'subject_id' => $user->id, 'permission' => AddressBookRule::PERM_READ_WRITE]);
    $readOnly = AddressBook::create(['name' => 'Read only book', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $readOnly->rules()->create(['subject_type' => 'user', 'subject_id' => $user->id, 'permission' => AddressBookRule::PERM_READ]);

    $c = Livewire::actingAs($user)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('openAbPicker')
        ->assertSee('Ops shared')
        ->assertDontSee('Read only book')
        ->set('abBookId', $shared->id)
        ->call('addToBook')
        ->assertSet('abResult', 'Added to Ops shared.');

    $entry = $shared->entries()->sole();
    expect($entry->rustdesk_id)->toBe($device->rustdesk_id)
        ->and($entry->hostname)->toBe('FRONT-DESK');
    $this->assertDatabaseHas('console_audits', ['action' => 'address-book.peer-add', 'target_id' => 'Ops shared']);

    // A read-only book can neither be a target nor lose the entry.
    $c->set('abBookId', $readOnly->id)->call('addToBook')->assertHasErrors('abBookId');
    $readOnly->entries()->create(['rustdesk_id' => $device->rustdesk_id]);
    $c->call('removeFromBook', $readOnly->id)->assertForbidden();
    expect($readOnly->entries()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('removeFromBook', $shared->id)
        ->assertSet('abResult', 'Removed from Ops shared.');
    expect($shared->entries()->count())->toBe(0);
    $this->assertDatabaseHas('console_audits', ['action' => 'address-book.peer-delete', 'target_id' => 'Ops shared']);
});

it('rejects a note longer than the column', function () {
    $device = detailDevice();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('editNote')
        ->set('note', str_repeat('x', 501))
        ->call('saveNote')
        ->assertHasErrors('note');
});

it('opens the client with the ID in the path, case kept', function () {
    // Issue #105: rustdesk://<id> puts the ID in the host part, which the
    // client lowercases, so a mixed-case custom ID was "not found".
    $device = detailDevice(['rustdesk_id' => 'FrontDesk01']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('href="rustdesk://connection/new/FrontDesk01"', false);
});

it('uses the scheme an rdgen-built client registers', function () {
    Setting::put('client_url_scheme', 'level1-tech-support');
    $device = detailDevice();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertSee('href="level1-tech-support://connection/new/123456789"', false);
});

it('reads the hardware tiles out of the sysinfo strings', function () {
    $hw = DeviceDetail::hardware(detailDevice([
        'cpu' => 'Intel(R) Xeon(R) CPU E5-2620 0 @ 2.00GHz, 1.95GHz, 24/12 cores',
        'memory' => '31.94GB',
        'os' => 'windows / Windows 10 Pro - 10 (19045)',
    ]));

    expect($hw)->toBe([
        'cpu' => 'Intel Xeon E5-2620',
        'cpu_detail' => '12 cores · 24 threads · 1.95 GHz',
        'memory' => '32 GB',
        'os' => 'Windows 10 Pro',
        'os_detail' => 'Build 19045',
    ]);

    // A brand-only CPU from an older client keeps its base clock.
    expect(DeviceDetail::hardware(detailDevice(['rustdesk_id' => '2', 'uuid' => 'u2', 'cpu' => 'Intel(R) Core(TM) i5-4440 CPU @ 3.10GHz', 'os' => 'macos / macOS 15.6']))
    )->toMatchArray(['cpu' => 'Intel Core i5-4440', 'cpu_detail' => '3.10 GHz', 'os' => 'macOS 15.6', 'os_detail' => '']);
});

it('maps recent sessions into incoming and outgoing peers, busiest first', function () {
    $device = detailDevice();
    $other = detailDevice(['rustdesk_id' => '222222222', 'uuid' => 'other', 'hostname' => 'BACK-OFFICE']);
    foreach ([['555', 'Admin'], ['555', 'Admin'], ['777', 'Laptop']] as $i => [$from, $name]) {
        AuditConnection::create(['action' => 'new', 'conn_id' => $i + 1, 'rustdesk_id' => $device->rustdesk_id, 'from_peer' => $from, 'from_name' => $name, 'guid' => 'flow-in-'.$i]);
    }
    AuditConnection::create(['action' => 'new', 'conn_id' => 9, 'rustdesk_id' => $other->rustdesk_id, 'from_peer' => $device->rustdesk_id, 'guid' => 'flow-out']);

    $page = Livewire::actingAs(User::factory()->admin()->create())
        ->test(DeviceDetail::class, ['deviceId' => $device->id]);
    $flow = $page->viewData('flow');

    expect(array_column($flow['in'], 'label'))->toBe(['Admin', 'Laptop'])
        ->and(array_column($flow['in'], 'count'))->toBe([2, 1])
        ->and($flow['out'][0])->toMatchArray(['label' => 'BACK-OFFICE', 'id' => $other->id, 'platform' => 'windows', 'count' => 1])
        ->and([$flow['in_count'], $flow['out_count']])->toBe([3, 1]);
    $page->assertSee('3 incoming')->assertSee('1 outgoing');
});

it('switches tabs, rejects unknown ones, and opens Details for ?share=1', function () {
    $device = detailDevice();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->assertSet('tab', 'overview')
        ->call('setTab', 'activity')->assertSet('tab', 'activity')
        ->call('setTab', 'bogus')->assertSet('tab', 'overview')
        ->call('openNote')->assertSet('tab', 'details')->assertSet('editingNote', true);

    $this->actingAs($admin)->get('/devices/'.$device->id.'?share=1')->assertOk();
    Livewire::withQueryParams(['share' => '1'])->actingAs($admin)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->assertSet('tab', 'details');
});
