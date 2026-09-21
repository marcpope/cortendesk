<?php

use App\Livewire\StrategyList;
use App\Models\Device;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\User;
use App\Services\StrategyImpact;
use Livewire\Livewire;

it('summarizes a strategy policy change before it is applied', function () {
    $strategy = Strategy::create([
        'name' => 'Restricted',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);
    $device = Device::create([
        'rustdesk_id' => '981000001',
        'uuid' => 'impact-981000001',
        'hostname' => 'impact-host',
        'status' => Device::STATUS_ACTIVE,
    ]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $preview = StrategyImpact::preview($strategy, [
        'name' => 'Restricted',
        'note' => null,
        'enabled' => true,
        'is_default' => false,
        'enforce' => false,
        'options' => ['enable-file-transfer' => 'Y', 'enable-terminal' => 'N'],
    ]);

    expect($preview['affected_count'])->toBe(1)
        ->and($preview['option_changes']['enable-file-transfer'])->toBe(['before' => 'N', 'after' => 'Y'])
        ->and($preview['dangerous'])->toContain(['key' => 'enable-terminal', 'before' => null, 'after' => 'N']);
});

it('requires preview confirmation before saving a strategy', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Preview first', 'enabled' => true]);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('save')
        ->assertSet('previewing', true)
        ->assertSee('Review strategy impact');

    expect($strategy->fresh()->optionMap())->toBe([]);
});

it('requires one preview confirmation before deleting a strategy', function () {
    $strategy = Strategy::create(['name' => 'Delete after review', 'enabled' => true]);
    $device = Device::create([
        'rustdesk_id' => '981000002',
        'uuid' => 'impact-981000002',
        'hostname' => 'delete-impact-host',
        'status' => Device::STATUS_ACTIVE,
    ]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test(StrategyList::class)
        ->call('deleteStrategy', $strategy->id)
        ->assertSet('previewing', true)
        ->assertSet('pendingDeleteId', $strategy->id)
        ->assertSet('impactPreview.affected_count', 1)
        ->assertSee('Review strategy deletion');

    expect($strategy->fresh())->not->toBeNull();

    $component->call('confirmSave')->assertHasNoErrors();

    expect(Strategy::find($strategy->id))->toBeNull();
});

it('requires preview confirmation before restoring a revision', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Restore after review', 'enabled' => true]);
    $strategy->setOptions(['enable-file-transfer' => 'N']);
    $strategy->save();
    $revision = StrategyRevision::capture($strategy, $admin->id, 'Restricted');
    $strategy->setOptions(['enable-file-transfer' => 'Y']);
    $strategy->save();

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('restoreRevision', $revision->id)
        ->assertSet('previewing', true)
        ->assertSet('restoreRevisionId', $revision->id)
        ->assertSee('Review revision restore');

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'Y']);

    $component->call('confirmSave')->assertHasNoErrors();

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N']);
});

it('keeps the quick enabled toggle instant', function () {
    $strategy = Strategy::create(['name' => 'Instant toggle', 'enabled' => true]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(StrategyList::class)
        ->call('toggleEnabled', $strategy->id)
        ->assertSet('previewing', false)
        ->assertHasNoErrors();

    expect($strategy->fresh()->enabled)->toBeFalse();
});

it('applies the reviewed save snapshot rather than later form changes', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Locked review', 'enabled' => true]);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('previewSave')
        ->set('formOptions.enable-file-transfer', 'Y')
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N']);
});

it('rejects a stale confirmation when fleet routing changes after preview', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Stale review', 'enabled' => true]);
    $device = Device::create([
        'rustdesk_id' => '981000003',
        'uuid' => 'impact-981000003',
        'hostname' => 'stale-impact-host',
        'status' => Device::STATUS_ACTIVE,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('previewSave');

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $strategy->id);

    $component->call('confirmSave')->assertHasErrors('preview');

    expect($strategy->fresh()->optionMap())->toBe([]);
});

it('applies a reviewed save only to its locked target', function () {
    $admin = User::factory()->admin()->create();
    $reviewed = Strategy::create(['name' => 'Reviewed target', 'enabled' => true]);
    $other = Strategy::create(['name' => 'Other target', 'enabled' => true]);

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $reviewed->id)
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('previewSave')
        ->set('editingId', $other->id)
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($reviewed->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N'])
        ->and($other->fresh()->optionMap())->toBe([]);
});

it('rejects confirmation when the timeout changes after preview', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create([
        'name' => 'Timeout review',
        'enabled' => true,
        'confirmation_timeout_minutes' => 15,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('edit', $strategy->id)
        ->set('formConfirmationTimeout', 30)
        ->call('previewSave')
        ->assertSet('impactPreview.metadata_changes.confirmation_timeout_minutes', [
            'before' => 15,
            'after' => 30,
        ]);

    $strategy->update(['confirmation_timeout_minutes' => 45]);

    $component->call('confirmSave')->assertHasErrors('preview');

    expect($strategy->fresh()->confirmation_timeout_minutes)->toBe(45);
});
