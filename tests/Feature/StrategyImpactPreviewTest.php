<?php

use App\Livewire\DeviceList;
use App\Livewire\StrategyList;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\GroupAccess;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\User;
use App\Services\StrategyImpact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Strategy::flushCache();
});

function impactAdmin(): User
{
    return User::factory()->admin()->create();
}

function impactDevice(string $id, array $attributes = []): Device
{
    return Device::create([
        'rustdesk_id' => $id,
        'uuid' => 'impact-'.$id,
        'hostname' => 'host-'.$id,
        'status' => Device::STATUS_ACTIVE,
        ...$attributes,
    ]);
}

it('previews a policy save before it can mutate the strategy', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create([
        'name' => 'Preview policy',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $device = impactDevice('981000001');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'Y')
        ->set('formOptions.enable-terminal', 'N')
        ->call('save')
        ->assertSet('previewing', true)
        ->assertSet('impactPreview.affected_count', 1)
        ->assertSet('impactPreview.option_changes.enable-file-transfer.before', 'N')
        ->assertSet('impactPreview.option_changes.enable-file-transfer.after', 'Y')
        ->assertSet('impactPreview.dangerous.0.key', 'enable-terminal')
        ->assertSee('Review strategy impact');

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N']);
});

it('confirms a reviewed default change and captures both immutable revisions', function () {
    $admin = impactAdmin();
    $currentDefault = Strategy::create(['name' => 'Current reviewed default', 'enabled' => true, 'is_default' => true]);
    $candidate = Strategy::create(['name' => 'Candidate reviewed default', 'enabled' => true]);
    foreach ([$currentDefault, $candidate] as $strategy) {
        $revision = StrategyRevision::capture($strategy, $admin->id, 'Initial');
        $strategy->forceFill(['active_revision_id' => $revision->id])->saveQuietly();
    }

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $candidate->id)
        ->set('formIsDefault', true)
        ->call('previewSave')
        ->call('confirmSave')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    expect($candidate->fresh()->is_default)->toBeTrue()
        ->and($candidate->revisions()->count())->toBe(2)
        ->and($currentDefault->fresh()->is_default)->toBeFalse()
        ->and($currentDefault->revisions()->count())->toBe(2);
});

it('rejects a policy confirmation after its reviewed assignment state changes', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create([
        'name' => 'Reviewed policy',
        'enabled' => true,
        'options' => ['enable-audio' => 'N'],
    ]);
    $replacement = Strategy::create(['name' => 'Replacement policy', 'enabled' => true]);
    $device = impactDevice('981000002');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-audio', 'Y')
        ->call('previewSave')
        ->assertSet('previewing', true);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $replacement->id);

    $component->call('confirmSave')->assertHasErrors('preview');

    expect($strategy->fresh()->optionMap())->toBe(['enable-audio' => 'N']);
});

it('previews default state changes against every active device', function () {
    impactDevice('981000003');
    impactDevice('981000004');

    $preview = StrategyImpact::preview(null, [
        'name' => 'Fleet default',
        'note' => null,
        'enabled' => true,
        'is_default' => true,
        'enforce' => false,
        'options' => ['enable-audio' => 'N'],
    ]);

    expect($preview['affected_count'])->toBe(2)
        ->and($preview['metadata_changes']['is_default']['after'])->toBeTrue();
});

it('rejects a disabled default before producing an impact preview', function () {
    Livewire::actingAs(impactAdmin())
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Invalid disabled default')
        ->set('formEnabled', false)
        ->set('formIsDefault', true)
        ->call('previewSave')
        ->assertHasErrors('formIsDefault')
        ->assertSet('previewing', false);
});

it('turns off default status when the quick toggle disables a strategy', function () {
    $strategy = Strategy::create(['name' => 'Toggleable default', 'enabled' => true, 'is_default' => true]);

    Livewire::actingAs(impactAdmin())
        ->test(StrategyList::class)
        ->call('toggleEnabled', $strategy->id)
        ->assertSet('previewing', true)
        ->assertSet('formEnabled', false)
        ->assertSet('formIsDefault', false)
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($strategy->fresh()->enabled)->toBeFalse()
        ->and($strategy->fresh()->is_default)->toBeFalse();
});

it('previews strategy deletion before changing fleet routing', function () {
    $strategy = Strategy::create(['name' => 'Reviewed deletion', 'enabled' => true]);
    $device = impactDevice('981000011');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $component = Livewire::actingAs(impactAdmin())
        ->test(StrategyList::class)
        ->call('deleteStrategy', $strategy->id)
        ->assertSet('previewing', true)
        ->assertHasNoErrors();

    expect($strategy->fresh())->not->toBeNull();

    $component->call('confirmSave')->assertHasNoErrors();

    expect(Strategy::find($strategy->id))->toBeNull()
        ->and(Strategy::withTrashed()->find($strategy->id)?->trashed())->toBeTrue();
});

it('invalidates a policy fingerprint when any effective fleet policy changes', function () {
    $strategy = Strategy::create(['name' => 'Reviewed fingerprint', 'enabled' => true]);
    $other = Strategy::create(['name' => 'Other fleet policy', 'enabled' => true]);
    $snapshot = [
        'name' => 'Reviewed fingerprint',
        'note' => null,
        'enabled' => true,
        'is_default' => false,
        'enforce' => false,
        'options' => ['enable-audio' => 'N'],
    ];

    $reviewed = StrategyImpact::preview($strategy, $snapshot);
    $other->setOptions(['enable-file-transfer' => 'N']);
    $other->save();
    $current = StrategyImpact::preview($strategy->fresh(), $snapshot);

    expect($current['fingerprint'])->not->toBe($reviewed['fingerprint']);
});

it('previews assignment changes before it reassigns devices', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create(['name' => 'Assignment target', 'enabled' => true]);
    $fallback = Strategy::create(['name' => 'Assignment fallback', 'enabled' => true, 'is_default' => true]);
    $device = impactDevice('981000005');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('saveAssign')
        ->assertSet('assignPreviewing', true)
        ->assertSet('assignmentImpact.affected_count', 1)
        ->assertSet('assignmentImpact.assignment_changes.device.added_count', 1)
        ->assertSee('Review assignment impact');

    expect($device->fresh()->assignedStrategyId())->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);
});

it('rejects assignment confirmation after the reviewed assignment state changes', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create(['name' => 'Assignment review', 'enabled' => true]);
    $replacement = Strategy::create(['name' => 'Other assignment', 'enabled' => true]);
    $device = impactDevice('981000006');

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('previewAssign')
        ->assertSet('assignPreviewing', true);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $replacement->id);

    $component->call('confirmAssign')->assertHasErrors('assignment');

    expect($device->fresh()->assignedStrategyId())->toBe($replacement->id);
});

it('serializes owner changes through the strategy context and enforces token scope', function () {
    $fallback = Strategy::create(['name' => 'Context fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'Owner policy', 'enabled' => true]);
    $owner = User::factory()->create();
    $device = impactDevice('981000008');
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);

    expect(fn () => Device::updateWithStrategyContext($device, ['user_id' => $owner->id], false))
        ->toThrow(AuthorizationException::class)
        ->and($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);

    $device = Device::updateWithStrategyContext($device, ['user_id' => $owner->id]);

    expect($device->fresh()->user_id)->toBe($owner->id)
        ->and($device->fresh()->strategy_id_resolved)->toBe($ownerPolicy->id);
});

it('rejects API owner changes that alter policy without strategy permission', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'impact-device-only', ['device' => 'rw', 'strategy' => 'none']);
    $fallback = Strategy::create(['name' => 'API fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'API owner policy', 'enabled' => true]);
    $owner = User::factory()->create();
    $device = impactDevice('981000009');
    Strategy::assignTo(Strategy::LEVEL_USER, $owner->id, $ownerPolicy->id);

    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
        ->postJson('/api/v1/devices/'.$device->id.'/assign', ['user_id' => $owner->id])
        ->assertForbidden();

    expect($device->fresh()->user_id)->toBeNull()
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);
});

it('rejects CLI strategy assignment without an interactive impact confirmation', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'impact-cli-strategy', ['device' => 'rw', 'strategy' => 'rw']);
    $strategy = Strategy::create(['name' => 'CLI reviewed policy', 'enabled' => true]);
    $device = impactDevice('981000014');

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->post('/api/devices/cli', [
            'id' => $device->rustdesk_id,
            'uuid' => $device->uuid,
            'strategy_name' => $strategy->name,
        ])
        ->assertStatus(409)
        ->assertSee('reviewed impact confirmation');

    expect($device->fresh()->assignedStrategyId())->toBeNull();
});

it('keeps fleet assignments and device samples admin-only', function () {
    $role = Role::create(['name' => 'Strategy manager', 'permissions' => ['strategy' => 'rw']]);
    $manager = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    $strategy = Strategy::create(['name' => 'Delegated policy', 'enabled' => true]);
    $hidden = impactDevice('981000010');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $hidden->id, $strategy->id);

    Livewire::actingAs($manager)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-audio', 'N')
        ->call('previewSave')
        ->assertSet('impactPreview.affected_count', 1)
        ->assertSet('impactPreview.affected_devices', [])
        ->assertDontSee('981000010');
});

it('requires strategy permission when a console owner change alters effective policy', function () {
    $role = Role::create(['name' => 'Device manager', 'permissions' => ['device' => 'rw']]);
    $manager = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    $newOwner = User::factory()->create();
    $fallback = Strategy::create(['name' => 'Console fallback', 'enabled' => true, 'is_default' => true]);
    $ownerPolicy = Strategy::create(['name' => 'Console owner policy', 'enabled' => true]);
    Strategy::assignTo(Strategy::LEVEL_USER, $newOwner->id, $ownerPolicy->id);
    $group = DeviceGroup::create(['name' => 'Managed devices']);
    GroupAccess::create([
        'accessor_type' => GroupAccess::ACCESSOR_USER,
        'accessor_id' => $manager->id,
        'target_type' => GroupAccess::TARGET_DEVICE_GROUP,
        'target_id' => $group->id,
    ]);
    $device = impactDevice('981000012', ['user_id' => $manager->id, 'device_group_id' => $group->id]);

    Livewire::actingAs($manager)
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formUserId', $newOwner->id)
        ->call('save')
        ->assertForbidden();

    expect($device->fresh()->user_id)->toBe($manager->id)
        ->and($device->fresh()->strategy_id_resolved)->toBe($fallback->id);
});

it('blocks direct strategy assignment in the device editor in favor of reviewed fleet assignment', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create(['name' => 'Reviewed assignment only', 'enabled' => true]);
    $device = impactDevice('981000013');

    Livewire::actingAs($admin)
        ->test(DeviceList::class)
        ->call('edit', $device->id)
        ->set('formStrategyId', $strategy->id)
        ->call('save')
        ->assertHasErrors('formStrategyId');

    expect($device->assignedStrategyId())->toBeNull();
});

it('removes soft-deleted device assignments exactly as previewed', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create(['name' => 'Soft-deleted assignment', 'enabled' => true]);
    $device = impactDevice('981000015');
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);
    Device::deleteWithStrategyContext($device);

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->call('previewAssign')
        ->assertSet('assignmentImpact.assignment_changes.device.removed_count', 1)
        ->call('confirmAssign')
        ->assertHasNoErrors();

    expect(DB::table('device_strategy')->where('device_id', $device->id)->exists())->toBeFalse();
});

it('confirms a reviewed assignment without changing existing editor behavior', function () {
    $admin = impactAdmin();
    $strategy = Strategy::create(['name' => 'Confirmed assignment', 'enabled' => true]);
    $device = impactDevice('981000007');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('openAssign', $strategy->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('previewAssign')
        ->call('confirmAssign')
        ->assertHasNoErrors()
        ->assertSet('assigningId', null);

    expect($device->fresh()->assignedStrategyId())->toBe($strategy->id);
});
