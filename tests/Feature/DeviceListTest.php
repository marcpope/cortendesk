<?php

use App\Livewire\DeviceList;
use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function actingAdmin(): User
{
    $user = User::create([
        'username' => 'admin-test',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

function makeDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => "10000000$n",
        'uuid' => "uuid-$n",
        'hostname' => "host-$n",
        'os' => 'Windows 10',
        'username' => 'user',
        'version' => '1.4.2',
    ], $attrs));
}

it('renders the device list', function () {
    actingAdmin();
    makeDevice(['alias' => 'Front Desk']);

    Livewire::test(DeviceList::class)
        ->assertSee('Front Desk')
        ->assertSee('100000001');
});

it('filters by search', function () {
    actingAdmin();
    makeDevice(['alias' => 'Front Desk']);
    makeDevice(['alias' => 'Server Rack', 'hostname' => 'rack01']);

    Livewire::test(DeviceList::class)
        ->set('search', 'rack')
        ->assertSee('Server Rack')
        ->assertDontSee('Front Desk');
});

it('filters online devices by heartbeat window', function () {
    actingAdmin();
    makeDevice(['alias' => 'Fresh', 'last_online_at' => now()->subSeconds(10)]);
    makeDevice(['alias' => 'Stale', 'last_online_at' => now()->subMinutes(10)]);

    Livewire::test(DeviceList::class)
        ->set('status', 'online')
        ->assertSee('Fresh')
        ->assertDontSee('Stale');
});

it('honours the configured online window for presence', function () {
    // Regression: Settings → Server → "Online window" was ignored — the
    // resolver Device::onlineWindow() existed but isOnline() and the
    // online/offline scopes read the raw 60-second constant, so the setting
    // did nothing. Presence must follow the setting, in both directions.
    actingAdmin();
    $device = makeDevice(['alias' => 'Flaky-Link', 'last_online_at' => now()->subMinutes(5)]);

    // Default 60s window: 5 minutes ago is offline.
    expect($device->isOnline())->toBeFalse()
        ->and(Device::online()->count())->toBe(0)
        ->and(Device::offline()->count())->toBe(1);

    // Widen to 10 minutes: the same heartbeat now counts as online.
    Setting::put('online_window', '600');
    expect($device->fresh()->isOnline())->toBeTrue()
        ->and(Device::online()->count())->toBe(1)
        ->and(Device::offline()->count())->toBe(0);

    // Narrow it back: the memo must not stick to the old value.
    Setting::put('online_window', '60');
    expect($device->fresh()->isOnline())->toBeFalse()
        ->and(Device::online()->count())->toBe(0);
});

it('edits alias and note', function () {
    actingAdmin();
    $device = makeDevice();

    Livewire::test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formAlias', 'Renamed')
        ->set('formNote', 'important box')
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh())->alias->toBe('Renamed')->note->toBe('important box');
});

it('pre-registers a device by id', function () {
    actingAdmin();

    Livewire::test(DeviceList::class)
        ->call('create')
        ->set('formRustdeskId', '555444333')
        ->set('formAlias', 'Incoming laptop')
        ->call('save')
        ->assertHasNoErrors();

    expect(Device::where('rustdesk_id', '555444333')->exists())->toBeTrue();
});

it('rejects duplicate rustdesk ids on create', function () {
    actingAdmin();
    makeDevice(['rustdesk_id' => '777777777']);

    Livewire::test(DeviceList::class)
        ->call('create')
        ->set('formRustdeskId', '777777777')
        ->call('save')
        ->assertHasErrors('formRustdeskId');
});

it('soft deletes into the recycle bin, restores, and destroys', function () {
    actingAdmin();
    $device = makeDevice();

    $component = Livewire::test(DeviceList::class)
        ->call('deleteDevice', $device->id);

    expect(Device::count())->toBe(0)
        ->and(Device::onlyTrashed()->count())->toBe(1);

    $component->set('trashed', true)
        ->assertSee($device->rustdesk_id)
        ->call('restoreDevice', $device->id);

    expect(Device::count())->toBe(1);

    $component->call('deleteDevice', $device->id)
        ->call('forceDeleteDevice', $device->id);

    expect(Device::withTrashed()->count())->toBe(0);
});

it('shows a Web Client link carrying the device id when configured', function () {
    config(['cortendesk.webclient_url' => 'http://example.test/webclient/']);
    actingAdmin();
    $device = makeDevice(['rustdesk_id' => '987654321']);

    Livewire::test(DeviceList::class)
        ->assertSee('http://example.test/webclient/?id=987654321', false)
        ->assertSee('ri-global-line', false);
});

it('hides the Web Client link when no webclient_url is configured', function () {
    config(['cortendesk.webclient_url' => '']);
    actingAdmin();
    makeDevice();

    Livewire::test(DeviceList::class)->assertDontSee('ri-global-line', false);
});

it('shows a Terminal action next to Connect for the native web client', function () {
    config(['cortendesk.native_webclient' => true]);
    actingAdmin();
    makeDevice(['rustdesk_id' => '987654321']);

    Livewire::test(DeviceList::class)
        ->assertSee(route('webclient').'?id=987654321&amp;mode=terminal', false)
        ->assertSee('target="cortendesk-terminal"', false)
        ->assertSee('ri-terminal-box-line', false)
        ->assertSee('Open a terminal in the browser');
});

it('hides the Terminal action when the native web client is off', function () {
    config(['cortendesk.native_webclient' => false]);
    actingAdmin();
    makeDevice(['rustdesk_id' => '987654321']);

    Livewire::test(DeviceList::class)
        ->assertDontSee('mode=terminal', false)
        ->assertDontSee('ri-terminal-box-line', false);
});

it('offers no Terminal for phones, which have no terminal', function () {
    config(['cortendesk.native_webclient' => true]);
    actingAdmin();
    makeDevice(['rustdesk_id' => '555000111', 'os' => 'Android 14']);

    Livewire::test(DeviceList::class)
        ->assertSee('?id=555000111', false)
        ->assertDontSee('mode=terminal', false);
});

it('filters devices by owner and unassigned', function () {
    $admin = actingAdmin();
    $owner = User::factory()->create(['username' => 'owner-a']);
    $mine = makeDevice(['alias' => 'Owned', 'user_id' => $owner->id]);
    $free = makeDevice(['alias' => 'Free']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('owner', $owner->id)
        ->assertSee('Owned')->assertDontSee('Free');

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('owner', -1)
        ->assertSee('Free')->assertDontSee('Owned');
});

it('shows the owner column to admins', function () {
    $admin = actingAdmin();
    $owner = User::factory()->create(['username' => 'jdoe-owner']);
    makeDevice(['user_id' => $owner->id]);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertSee('Owner')->assertSee('jdoe-owner');
});

// --- CSV export + column picker (issue #16) ---------------------------------

it('exports the current view as CSV with lejianwen-compatible headers', function () {
    actingAdmin();
    makeDevice([
        'alias' => 'Front Desk', 'cpu' => 'Intel i5-9500, 6 cores', 'memory' => '16 GB',
        'last_online_ip' => '203.0.113.9', 'last_online_at' => now(),
    ]);
    makeDevice(['hostname' => 'other-host']);

    $export = Livewire::test(DeviceList::class)->call('exportCsv');
    $export->assertFileDownloaded('devices.csv');

    $csv = $export->effects['download']['content'];
    $csv = base64_decode($csv, true) ?: $csv;   // Livewire base64-encodes downloads

    // The first fifteen headers are lejianwen/rustdesk-api's export, in order —
    // external tooling reads them positionally, never reorder them.
    expect(explode("\n", $csv)[0])->toStartWith(
        'row_id,id,cpu,hostname,memory,os,username,uuid,version,last_online_time,last_online_ip,group_id,alias,created_at,updated_at'
    );
    expect($csv)->toContain('Front Desk')
        ->toContain('"Intel i5-9500, 6 cores"')
        ->toContain('16 GB')
        ->toContain('203.0.113.9')
        ->toContain('other-host');
});

it('export respects the active filters', function () {
    actingAdmin();
    makeDevice(['alias' => 'Front Desk']);
    makeDevice(['hostname' => 'warehouse-pc']);

    $export = Livewire::test(DeviceList::class)
        ->set('search', 'warehouse')
        ->call('exportCsv');

    $csv = $export->effects['download']['content'];
    $csv = base64_decode($csv, true) ?: $csv;

    expect($csv)->toContain('warehouse-pc')->not->toContain('Front Desk');
});

it('export is scoped: a non-admin gets only devices they can see', function () {
    $owner = User::create(['username' => 'plain-user', 'password' => 'secret-password']);
    makeDevice(['alias' => 'Mine', 'user_id' => $owner->id]);
    makeDevice(['alias' => 'NotMine']);

    test()->actingAs($owner);
    $export = Livewire::test(DeviceList::class)->call('exportCsv');

    $csv = $export->effects['download']['content'];
    $csv = base64_decode($csv, true) ?: $csv;

    expect($csv)->toContain('Mine')->not->toContain('NotMine');
});

it('shows the default columns and hides the optional ones until picked', function () {
    actingAdmin();
    makeDevice(['last_online_ip' => '203.0.113.9', 'memory' => '16 GB']);

    Livewire::test(DeviceList::class)
        ->assertSee('Last Seen')
        ->assertDontSee('203.0.113.9')
        ->assertDontSee('16 GB');
});

it('adds a column when picked and persists the choice on the account', function () {
    $admin = actingAdmin();
    makeDevice(['last_online_ip' => '203.0.113.9']);

    Livewire::test(DeviceList::class)
        ->set('columns', array_merge(DeviceList::DEFAULT_COLUMNS, ['ip']))
        ->assertSee('203.0.113.9');

    expect($admin->fresh()->devices_columns)->toContain('ip');

    // A fresh mount (new session, new page load) restores the saved selection.
    Livewire::test(DeviceList::class)->assertSee('203.0.113.9');
});

it('drops unknown column keys instead of storing them', function () {
    $admin = actingAdmin();

    Livewire::test(DeviceList::class)
        ->set('columns', ['alias', 'no-such-column']);

    expect($admin->fresh()->devices_columns)->toBe(['alias']);
});

it('resets columns to the defaults', function () {
    $admin = actingAdmin();
    $admin->forceFill(['devices_columns' => ['uuid']])->save();

    Livewire::test(DeviceList::class)
        ->call('resetColumns')
        ->assertSee('Last Seen');

    expect($admin->fresh()->devices_columns)->toBeNull();
});

it('never shows the owner column to a non-admin, even if saved', function () {
    $user = User::create([
        'username' => 'plain-user2', 'password' => 'secret-password',
        'devices_columns' => ['owner', 'alias'],
    ]);
    makeDevice(['user_id' => $user->id]);

    test()->actingAs($user);
    Livewire::test(DeviceList::class)->assertDontSee('Owner');
});

// --- Bulk selection: delete + add to address book (issue #15) ---------------

it('selects the whole page and bulk-deletes into the recycle bin', function () {
    actingAdmin();
    makeDevice(['alias' => 'Bulk-A']);
    makeDevice(['alias' => 'Bulk-B']);

    $c = Livewire::test(DeviceList::class)->call('selectPage');
    expect($c->get('selected'))->toHaveCount(2);

    $c->call('bulkDelete');

    expect(Device::count())->toBe(0)
        ->and(Device::onlyTrashed()->count())->toBe(2)
        ->and($c->get('selected'))->toBe([])
        ->and($c->get('bulkResult'))->toContain('2 devices moved');
});

it('bulk delete silently skips devices outside the user\'s scope', function () {
    $owner = User::create(['username' => 'scoped-user', 'password' => 'secret-password']);
    $mine = makeDevice(['user_id' => $owner->id]);
    $foreign = makeDevice();

    test()->actingAs($owner);
    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $mine->id, (string) $foreign->id])
        ->call('bulkDelete');

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($foreign->fresh()->trashed())->toBeFalse();
});

it('adds selected devices to the personal address book with mapped fields', function () {
    $admin = actingAdmin();
    $device = makeDevice(['alias' => 'Front Desk', 'os' => 'Windows 11 Pro', 'username' => 'reception']);

    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $device->id])
        ->call('openAbPicker')
        ->set('abBookId', AddressBook::personalFor($admin)->id)
        ->call('addSelectedToBook');

    $entry = AddressBook::personalFor($admin)->entries()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->rustdesk_id)->toBe($device->rustdesk_id)
        ->and($entry->alias)->toBe('Front Desk')
        ->and($entry->hostname)->toBe($device->hostname)
        ->and($entry->platform)->toBe('Windows')     // RustDesk-style, not the slug
        ->and($entry->username)->toBe('reception');
});

it('skips devices already present in the chosen book and says so', function () {
    $admin = actingAdmin();
    $a = makeDevice();
    $b = makeDevice();
    $book = AddressBook::personalFor($admin);
    $book->entries()->create(['rustdesk_id' => $a->rustdesk_id, 'tag_ids' => []]);

    $c = Livewire::test(DeviceList::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('openAbPicker')
        ->set('abBookId', $book->id)
        ->call('addSelectedToBook');

    expect($book->entries()->count())->toBe(2)
        ->and($c->get('bulkResult'))->toContain('Added 1')->toContain('1 already there');
});

it('refuses to add entries to a book shared read-only', function () {
    $bookOwner = User::create(['username' => 'book-owner', 'password' => 'secret-password']);
    $book = AddressBook::create(['name' => 'Team', 'owner_user_id' => $bookOwner->id, 'is_personal' => false]);

    $viewer = User::create(['username' => 'viewer', 'password' => 'secret-password']);
    $book->rules()->create(['subject_type' => 'user', 'subject_id' => $viewer->id, 'permission' => AddressBookRule::PERM_READ]);
    $device = makeDevice(['user_id' => $viewer->id]);

    test()->actingAs($viewer);
    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $device->id])
        ->call('openAbPicker')
        ->set('abBookId', $book->id)
        ->call('addSelectedToBook')
        ->assertHasErrors('abBookId');

    expect($book->entries()->count())->toBe(0);
});

it('clears the selection when filters or pages change', function () {
    actingAdmin();
    $device = makeDevice();

    Livewire::test(DeviceList::class)
        ->set('selected', [(string) $device->id])
        ->set('search', 'anything')
        ->assertSet('selected', []);
});

/*
 * Summary chips as filters (issue #26).
 */

it('filters the list from the online and offline chips', function () {
    $admin = actingAdmin();
    makeDevice(['rustdesk_id' => '100', 'last_online_at' => now()]);
    makeDevice(['rustdesk_id' => '200', 'last_online_at' => now()->subDays(2)]);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('filterByChip', 'online')
        ->assertSet('status', 'online')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['100'])
        ->call('filterByChip', 'offline')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['200'])
        ->call('filterByChip', 'all')
        ->assertViewHas('devices', fn ($d) => count($d) === 2);
});

it('leaves the recycle bin and pending tab when a chip is clicked', function () {
    $admin = actingAdmin();
    makeDevice(['rustdesk_id' => '100']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('trashed', true)
        ->call('filterByChip', 'online')
        ->assertSet('trashed', false)
        ->set('pendingTab', true)
        ->call('filterByChip', 'all')
        ->assertSet('pendingTab', false);
});

it('shows the pending chip only when something is waiting', function () {
    $admin = actingAdmin();
    makeDevice(['rustdesk_id' => '100']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertDontSee('Pending');

    makeDevice(['rustdesk_id' => '200', 'status' => Device::STATUS_PENDING]);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->assertSee('Pending')
        ->call('openPending')
        ->assertSet('pendingTab', true);
});

/*
 * OS column and the un-lumped Device cell (issue #33).
 */

it('offers an OS column that sorts with blanks last', function () {
    $admin = actingAdmin();
    makeDevice(['rustdesk_id' => '100', 'os' => 'windows / Windows 11 Pro']);
    makeDevice(['rustdesk_id' => '200', 'os' => null]);
    makeDevice(['rustdesk_id' => '300', 'os' => 'linux / Ubuntu 24.04']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('columns', ['device', 'os'])
        ->call('sortBy', 'os')
        ->assertSet('sortField', 'os')
        ->assertViewHas('devices', fn ($d) => $d->pluck('rustdesk_id')->all() === ['300', '100', '200']);
});

it('drops OS and user from the Device cell once their own columns are shown', function () {
    $admin = actingAdmin();
    makeDevice(['rustdesk_id' => '100', 'hostname' => 'FRONT-DESK', 'os' => 'windows / Windows 11 Pro', 'username' => 'reception']);

    // Without the columns, the subtitle carries both (family prefix dropped —
    // the platform icon already says it).
    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('columns', ['device'])
        ->assertSeeHtml('Windows 11 Pro · reception');

    // With them, the Device cell is just the hostname; the values move to
    // their own columns.
    Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('columns', ['device', 'os', 'username'])
        ->assertDontSeeHtml('Windows 11 Pro · reception')
        ->assertSee('Windows 11 Pro')
        ->assertSee('reception');
});
