<?php

/**
 * Strategies console UI (PLAN C4): the Strategies screen, the option editor,
 * the three assignment editors and the device-editor "effective strategy"
 * inspector. Delivery itself is covered by StrategyTest.
 */

use App\Livewire\DeviceList;
use App\Livewire\StrategyList;
use App\Livewire\UserList;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    // Strategy memoizes "is there any enabled strategy" in a static, which
    // RefreshDatabase does not reset.
    Strategy::flushCache();
});

function uiAdmin(): User
{
    return User::factory()->admin()->create();
}

function uiDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => (string) (960000000 + $n),
        'uuid' => "ui-uuid-$n",
        'hostname' => "ui-host-$n",
    ], $attrs));
}

// ------------------------------------------------------------------ access ---

it('renders the strategies page for an admin', function () {
    $this->actingAs(uiAdmin())
        ->get('/strategies')
        ->assertOk()
        ->assertSeeLivewire(StrategyList::class);
});

it('keeps a non-admin off the strategies page', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('/strategies')
        ->assertRedirect(route('overview'));
});

it('refuses the strategies component itself to a non-admin with a 403', function () {
    $user = User::factory()->create(['is_admin' => false]);

    // The route middleware only guards the page; the Livewire update endpoint
    // is reachable on its own, so the component has to say no as well.
    Livewire::actingAs($user)
        ->test(StrategyList::class)
        ->assertForbidden();
});

it('shows the strategies link in the sidebar only for admins', function () {
    $this->actingAs(uiAdmin())->get('/')->assertSee('Strategies');

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get('/')
        ->assertDontSee('Strategies');
});

// ----------------------------------------------------------------- editing ---

it('creates a strategy with grouped options and audits it', function () {
    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->assertSet('editingId', 0)
        ->set('formName', 'Locked down')
        ->set('formNote', 'No file transfer')
        ->set('formOptions.enable-file-transfer', 'N')
        ->set('formOptions.approve-mode', 'password')
        ->set('formOptions.allow-remove-wallpaper', 'Y')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    $strategy = Strategy::where('name', 'Locked down')->first();

    expect($strategy)->not->toBeNull()
        ->and($strategy->note)->toBe('No file transfer')
        ->and($strategy->enabled)->toBeTrue()
        ->and($strategy->optionMap())->toBe([
            'allow-remove-wallpaper' => 'Y',
            'approve-mode' => 'password',
            'enable-file-transfer' => 'N',
        ]);

    expect(ConsoleAudit::where('action', 'strategy.create')->where('target_id', 'Locked down')->exists())
        ->toBeTrue();
});

it('offers only allowlisted option keys, grouped as the protocol doc groups them', function () {
    $catalog = StrategyList::catalog();

    // The first three mirror docs/strategy-protocol.md. 'client' is ours: it
    // holds options that are not about an incoming session at all (currently
    // allow-auto-update, #11), which do not belong under permissions, security
    // or capture. 'network' holds the one §3.3 key we expose, ice-servers.
    // Keep this assertion exact — the point of the test is that a group
    // cannot appear by accident.
    expect(array_keys($catalog))->toBe(['permissions', 'security', 'display', 'client', 'network']);

    $offered = [];
    foreach ($catalog as $group) {
        $offered = array_merge($offered, array_keys($group['options']));
    }

    sort($offered);
    $allowed = array_keys(Strategy::OPTION_KEYS);
    sort($allowed);

    expect($offered)->toBe($allowed)
        // The keys that must never gain a control, whatever the editor grows.
        ->and($offered)->not->toContain('stop-service')
        ->and($offered)->not->toContain('api-server')
        ->and($offered)->not->toContain('2fa');
});

it('refuses an ICE server the client would skip and names it', function () {
    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'WebRTC')
        ->set('formOptions.ice-servers', 'stun:stun.example.com, turn:turn.example.com:3478')
        ->call('save')
        ->assertHasErrors('formOptions.ice-servers')
        ->assertSee('turn:turn.example.com:3478');

    expect(Strategy::where('name', 'WebRTC')->exists())->toBeFalse();
});

it('saves an ICE server list in canonical form', function () {
    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'WebRTC')
        ->set('formOptions.ice-servers', ' STUN:stun.example.com:3478 ,, turn://rd:s%2Cecret@turn.example.com ')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect(Strategy::where('name', 'WebRTC')->first()->optionMap())->toBe([
        'ice-servers' => 'stun:stun.example.com:3478,turn://rd:s%2Cecret@turn.example.com',
    ]);
});

it('loads a strategy into the editor and saves the edits', function () {
    $strategy = Strategy::create([
        'name' => 'Office',
        'enabled' => true,
        'options' => ['enable-audio' => 'N', 'enable-clipboard' => 'N'],
    ]);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->assertSet('formName', 'Office')
        ->assertSet('formOptions.enable-audio', 'N')
        ->assertSet('formOptions.enable-clipboard', 'N')
        ->assertSet('formOptions.enable-camera', '')
        ->set('formName', 'Office (revised)')
        // Back to "Not managed": the key leaves the map entirely.
        ->set('formOptions.enable-clipboard', '')
        ->set('formOptions.enable-camera', 'N')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    $strategy->refresh();

    expect($strategy->name)->toBe('Office (revised)')
        ->and($strategy->optionMap())->toBe(['enable-audio' => 'N', 'enable-camera' => 'N']);
});

it('duplicates a strategy into a new one and leaves the original alone (#159)', function () {
    $source = Strategy::create([
        'name' => 'Base',
        'enabled' => true,
        'is_default' => true,
        'options' => ['enable-audio' => 'N', 'enable-clipboard' => 'N'],
    ]);
    Strategy::create(['name' => 'Copy of Base', 'enabled' => true]);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('duplicate', $source->id)
        ->assertSet('editingId', 0)
        ->assertSet('formName', 'Copy of Base 2')
        ->assertSet('formIsDefault', false)
        ->assertSet('formOptions.enable-audio', 'N')
        ->set('formOptions.enable-camera', 'N')
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    $copy = Strategy::where('name', 'Copy of Base 2')->firstOrFail();

    expect($copy->optionMap())->toBe(['enable-audio' => 'N', 'enable-camera' => 'N', 'enable-clipboard' => 'N'])
        ->and($copy->is_default)->toBeFalse()
        ->and($source->fresh()->optionMap())->toBe(['enable-audio' => 'N', 'enable-clipboard' => 'N'])
        ->and($source->fresh()->is_default)->toBeTrue();
});

it('rejects a duplicate name and an out-of-range numeric option', function () {
    Strategy::create(['name' => 'Taken', 'enabled' => true]);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Taken')
        ->call('save')
        ->assertHasErrors('formName');

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Timeouts')
        ->set('formOptions.auto-disconnect-timeout', '9999')
        ->call('save')
        ->assertHasErrors('formOptions.auto-disconnect-timeout');

    // The failed save must not have written a half-built strategy.
    expect(Strategy::where('name', 'Timeouts')->exists())->toBeFalse();
});

it('keeps exactly one default when the editor marks a second one', function () {
    $first = Strategy::create(['name' => 'First', 'enabled' => true, 'is_default' => true]);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Second')
        ->set('formIsDefault', true)
        ->call('save')
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(Strategy::where('is_default', true)->pluck('name')->all())->toBe(['Second']);
});

it('toggles enabled from the list, audits it, and re-resolves devices', function () {
    $strategy = Strategy::create(['name' => 'Toggle me', 'enabled' => true, 'is_default' => true]);
    $device = uiDevice();

    expect($device->fresh()->strategy_id_resolved)->toBe($strategy->id);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('toggleEnabled', $strategy->id);

    expect($strategy->fresh()->enabled)->toBeFalse()
        ->and($device->fresh()->strategy_id_resolved)->toBeNull()
        ->and(ConsoleAudit::where('action', 'strategy.toggle')->exists())->toBeTrue();
});

it('deletes a strategy, releases its devices and audits it', function () {
    $default = Strategy::create(['name' => 'Fallback', 'enabled' => true, 'is_default' => true]);
    $doomed = Strategy::create(['name' => 'Doomed', 'enabled' => true]);
    $device = uiDevice();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $doomed->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($doomed->id);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('deleteStrategy', $doomed->id)
        ->call('confirmSave');

    expect(Strategy::find($doomed->id))->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($default->id)
        ->and(ConsoleAudit::where('action', 'strategy.delete')->where('target_id', 'Doomed')->exists())->toBeTrue();
});

// -------------------------------------------------------------- assignment ---

it('assigns devices, users and device groups, and changes what resolves', function () {
    $admin = uiAdmin();
    $strategy = Strategy::create(['name' => 'Field team', 'enabled' => true]);

    $owner = User::create(['username' => 'field-owner', 'password' => 'secret-password']);
    $group = DeviceGroup::create(['name' => 'Field']);

    $direct = uiDevice();
    $owned = uiDevice(['user_id' => $owner->id]);
    $grouped = uiDevice(['device_group_id' => $group->id]);

    expect($direct->fresh()->strategy_id_resolved)->toBeNull();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->assertSet('assignDeviceIds', [])
        ->set('assignDeviceIds', [$direct->id])
        ->set('assignUserIds', [$owner->id])
        ->set('assignGroupIds', [$group->id])
        ->call('saveAssign')
        ->assertHasNoErrors()
        ->assertSet('assigningId', null);

    expect($direct->fresh()->strategy_id_resolved)->toBe($strategy->id)
        ->and($owned->fresh()->strategy_id_resolved)->toBe($strategy->id)
        ->and($grouped->fresh()->strategy_id_resolved)->toBe($strategy->id)
        ->and($strategy->assignmentCounts())->toBe([
            Strategy::LEVEL_DEVICE => 1,
            Strategy::LEVEL_USER => 1,
            Strategy::LEVEL_DEVICE_GROUP => 1,
        ]);

    expect(ConsoleAudit::where('action', 'strategy.assign')->where('target_id', 'Field team')->exists())
        ->toBeTrue();
});

it('lists a strategy with its assignment counts on both layouts', function () {
    $strategy = Strategy::create(['name' => 'Counted', 'enabled' => true, 'is_default' => true, 'enforce' => true]);
    $device = uiDevice();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $html = Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->assertSee('Counted')
        ->assertSee('Default')
        ->assertSee('Enforced')
        ->assertSee('1 device')
        ->html();

    // Mobile card fallback must exist alongside the table (390px requirement).
    expect($html)->toContain('d-none d-md-block')->toContain('d-md-none');
});

it('releases a target that is unchecked in the assignment editor', function () {
    $strategy = Strategy::create(['name' => 'Temporary', 'enabled' => true]);
    $device = uiDevice();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->assertSet('assignDeviceIds', [$device->id])
        ->set('assignDeviceIds', [])
        ->call('saveAssign')
        ->assertHasNoErrors();

    expect($device->fresh()->strategy_id_resolved)->toBeNull()
        ->and(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $device->id))->toBeNull();
});

it('moves a device between strategies rather than assigning it twice', function () {
    $a = Strategy::create(['name' => 'A', 'enabled' => true]);
    $b = Strategy::create(['name' => 'B', 'enabled' => true]);
    $device = uiDevice();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $a->id);

    Livewire::actingAs(uiAdmin())
        ->test(StrategyList::class)
        ->call('openAssign', $b->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('saveAssign')
        ->assertHasNoErrors();

    expect($device->fresh()->strategy_id_resolved)->toBe($b->id)
        ->and($a->assignmentCounts()[Strategy::LEVEL_DEVICE])->toBe(0)
        ->and($b->assignmentCounts()[Strategy::LEVEL_DEVICE])->toBe(1);
});

// ------------------------------------------- device editor: the inspector ---

it('explains which assignment level won, in resolution order', function () {
    $default = Strategy::create(['name' => 'Default policy', 'enabled' => true, 'is_default' => true]);
    $userPolicy = Strategy::create(['name' => 'Owner policy', 'enabled' => true]);
    $groupPolicy = Strategy::create(['name' => 'Group policy', 'enabled' => true]);

    $owner = User::create(['username' => 'explained', 'password' => 'secret-password']);
    $group = DeviceGroup::create(['name' => 'Explained']);
    $device = uiDevice(['user_id' => $owner->id, 'device_group_id' => $group->id]);

    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $userPolicy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $groupPolicy->id);

    $explain = Strategy::explainFor($device->fresh());

    expect(array_column($explain['steps'], 'state'))
        ->toBe(['none', 'applied', 'overridden', 'overridden'])
        ->and($explain['winner']['level'])->toBe(Strategy::LEVEL_USER)
        ->and($explain['resolved']->id)->toBe($userPolicy->id)
        // The inspector must agree with what the delivery engine will do.
        ->and(Strategy::resolve($device->fresh()))->toBe($userPolicy->id);
});

it('reports a disabled assignment as skipped rather than winning', function () {
    $off = Strategy::create(['name' => 'Switched off', 'enabled' => false]);
    $default = Strategy::create(['name' => 'Fallback', 'enabled' => true, 'is_default' => true]);
    $device = uiDevice();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $off->id);

    $explain = Strategy::explainFor($device->fresh());

    expect($explain['steps'][0]['state'])->toBe('disabled')
        ->and($explain['winner']['level'])->toBe('default')
        ->and($explain['resolved']->id)->toBe($default->id);
});

it('shows the inspector in the device editor for an admin', function () {
    $strategy = Strategy::create(['name' => 'Inspector policy', 'enabled' => true, 'is_default' => true]);
    $device = uiDevice();

    Livewire::actingAs(uiAdmin())
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSee('In force now')
        ->assertSee('Inspector policy')
        ->assertSee('Default strategy');
});

it('hides the inspector from a non-admin editing their own device', function () {
    $user = User::factory()->create(['is_admin' => false]);
    Strategy::create(['name' => 'Hidden policy', 'enabled' => true, 'is_default' => true]);
    $device = uiDevice(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSet('formStrategyId', 0)
        ->assertDontSee('In force now')
        ->assertDontSee('Hidden policy');
});

it('assigns a strategy from the device editor and audits it', function () {
    $default = Strategy::create(['name' => 'Everyone', 'enabled' => true, 'is_default' => true]);
    $special = Strategy::create(['name' => 'Special', 'enabled' => true]);
    $device = uiDevice();

    expect($device->fresh()->strategy_id_resolved)->toBe($default->id);

    Livewire::actingAs(uiAdmin())
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSet('formStrategyId', 0)
        ->set('formStrategyId', $special->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh()->strategy_id_resolved)->toBe($special->id)
        ->and(ConsoleAudit::where('action', 'strategy.assign')->where('target_id', $device->rustdesk_id)->exists())
        ->toBeTrue();

    // …and clearing it puts the device back on the default.
    Livewire::actingAs(uiAdmin())
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->assertSet('formStrategyId', $special->id)
        ->set('formStrategyId', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect($device->fresh()->strategy_id_resolved)->toBe($default->id);
});

it('ignores a strategy assignment posted by a non-admin', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $strategy = Strategy::create(['name' => 'Not yours', 'enabled' => true]);
    $device = uiDevice(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formStrategyId', $strategy->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $device->id))->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBeNull();
});

// --------------------------------------------------- cached-column repair ---

it('re-resolves devices after a bulk owner reassignment', function () {
    $policy = Strategy::create(['name' => 'Owner policy', 'enabled' => true]);
    $owner = User::create(['username' => 'bulk-owner', 'password' => 'secret-password']);
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $policy->id);

    $device = uiDevice();
    expect($device->fresh()->strategy_id_resolved)->toBeNull();

    // Bulk assignment is a query-builder update: no model events, so the cached
    // column only stays honest because saveAssign() rebuilds it.
    Livewire::actingAs(uiAdmin())
        ->test(UserList::class)
        ->call('openAssign', $owner->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('saveAssign');

    expect($device->fresh()->strategy_id_resolved)->toBe($policy->id);
});
