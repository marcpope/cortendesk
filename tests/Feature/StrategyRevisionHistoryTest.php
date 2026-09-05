<?php

use App\Livewire\StrategyList;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

it('captures an immutable first revision for a strategy', function () {
    $strategy = Strategy::create([
        'name' => 'Locked down',
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ]);

    $revision = StrategyRevision::capture($strategy, null, 'Initial policy');

    expect(Schema::hasTable('strategy_revisions'))->toBeTrue()
        ->and($revision->revision)->toBe(1)
        ->and($revision->snapshot)->toMatchArray([
            'name' => 'Locked down',
            'options' => ['enable-file-transfer' => 'N'],
        ])
        ->and(fn () => $revision->update(['change_note' => 'Tampered']))
        ->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())
        ->toThrow(LogicException::class);
});

it('retains revision evidence and the creator name after related rows are deleted', function () {
    $author = User::factory()->admin()->create();
    $authorName = $author->username;
    $strategy = Strategy::create(['name' => 'Retained history', 'enabled' => true]);
    $revision = StrategyRevision::capture($strategy, $author->id, 'Initial');

    $author->delete();
    $strategy->delete();

    expect($revision->fresh()->created_by)->toBeNull()
        ->and($revision->fresh()->created_by_name)->toBe($authorName)
        ->and(Strategy::find($strategy->id))->toBeNull()
        ->and(Strategy::withTrashed()->find($strategy->id)?->trashed())->toBeTrue()
        ->and(StrategyRevision::find($revision->id))->not->toBeNull();
});

it('creates a revision whenever the editor saves a strategy', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('create')
        ->set('formName', 'Editor policy')
        ->set('formOptions.enable-file-transfer', 'N')
        ->call('save')
        ->assertSet('previewing', true)
        ->call('confirmSave')
        ->assertHasNoErrors();

    $strategy = Strategy::query()->where('name', 'Editor policy')->firstOrFail();

    expect($strategy->revisions()->count())->toBe(1)
        ->and($strategy->active_revision_id)->toBe($strategy->revisions()->value('id'));
});

it('compares revisions and restores a historical snapshot as a new revision', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Rollback policy', 'enabled' => true]);
    $first = StrategyRevision::captureSnapshot($strategy, [...$strategy->snapshot(), 'options' => ['enable-file-transfer' => 'N']], $admin->id, 'First');
    $strategy->setOptions(['enable-file-transfer' => 'Y']);
    $strategy->save();
    $second = StrategyRevision::capture($strategy, $admin->id, 'Second');
    $strategy->forceFill(['active_revision_id' => $second->id])->saveQuietly();

    $component = Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('showHistory', $strategy->id)
        ->set('compareFromRevisionId', $first->id)
        ->set('compareToRevisionId', $second->id);

    expect($component->get('revisionComparison'))->toContain([
        'key' => 'options.enable-file-transfer', 'before' => 'N', 'after' => 'Y',
    ]);

    $component->call('restoreRevision', $first->id)
        ->assertSet('previewing', true)
        ->assertHasNoErrors();

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'Y']);

    $component->call('confirmSave')->assertHasNoErrors();

    expect($strategy->fresh()->optionMap())->toBe(['enable-file-transfer' => 'N'])
        ->and($strategy->revisions()->pluck('revision')->all())->toBe([3, 2, 1]);
});

it('renders revision history and comparison controls in the strategy UI', function () {
    $admin = User::factory()->admin()->create();
    $strategy = Strategy::create(['name' => 'Visible history', 'enabled' => true]);
    StrategyRevision::capture($strategy, $admin->id, 'Initial');

    Livewire::actingAs($admin)
        ->test(StrategyList::class)
        ->call('showHistory', $strategy->id)
        ->assertSee('Visible history revision history')
        ->assertSeeHtml('id="compare-from"')
        ->assertSee('Restoring creates a new revision');
});

it('allocates sequential revision numbers and keeps their database identity unique', function () {
    $strategy = Strategy::create(['name' => 'Serialized capture', 'enabled' => true]);

    $one = StrategyRevision::capture($strategy, null, 'One');
    $two = StrategyRevision::capture($strategy, null, 'Two');

    expect([$one->revision, $two->revision])->toBe([1, 2])
        ->and(fn () => DB::table('strategy_revisions')->insert([
            'strategy_id' => $strategy->id,
            'revision' => 2,
            'snapshot' => '{}',
            'affected_devices' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

it('keeps routing identity intact when restoring an earlier revision', function () {
    $admin = User::factory()->admin()->create();
    $historical = Strategy::create(['name' => 'Historical default', 'enabled' => true, 'is_default' => true]);
    $historicalRevision = StrategyRevision::capture($historical, $admin->id, 'Was default');
    $historical->forceFill(['active_revision_id' => $historicalRevision->id])->saveQuietly();

    $current = Strategy::create(['name' => 'Current default', 'enabled' => true, 'is_default' => true]);
    $currentRevision = StrategyRevision::capture($current, $admin->id, 'Current default');
    $current->forceFill(['active_revision_id' => $currentRevision->id])->saveQuietly();

    Livewire::actingAs($admin)->test(StrategyList::class)
        ->call('restoreRevision', $historicalRevision->id)
        ->assertSet('previewing', true)
        ->call('confirmSave')
        ->assertHasNoErrors();

    expect($historical->fresh()->is_default)->toBeFalse()
        ->and($current->fresh()->is_default)->toBeTrue()
        ->and($historical->revisions()->count())->toBe(2)
        ->and($current->revisions()->count())->toBe(1);
});

it('backfills valid legacy strategies and rolls the history schema back cleanly', function () {
    $path = database_path('strategy-revision-history-test.sqlite');
    File::delete($path);
    File::put($path, '');
    config(['database.connections.revision_history' => [
        'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge('revision_history');
    $previous = config('database.default');
    config(['database.default' => 'revision_history']);

    try {
        Schema::create('users', fn ($table) => $table->id());
        Schema::create('strategies', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('note')->nullable();
            $table->boolean('enabled');
            $table->boolean('is_default');
            $table->boolean('enforce');
            $table->json('options')->nullable();
            $table->timestamps();
        });
        Schema::create('devices', function ($table): void {
            $table->id();
            $table->foreignId('strategy_id_resolved')->nullable();
        });
        DB::table('strategies')->insert([
            'name' => 'Legacy policy', 'enabled' => true, 'is_default' => false, 'enforce' => false,
            'options' => json_encode(['enable-file-transfer' => 'N']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_24_000010_create_strategy_revision_history.php');
        $migration->up();

        expect(Schema::hasTable('strategy_revisions'))->toBeTrue()
            ->and(DB::table('strategy_revisions')->value('revision'))->toBe(1)
            ->and(json_decode(DB::table('strategy_revisions')->value('snapshot'), true)['options'])->toBe(['enable-file-transfer' => 'N'])
            ->and(DB::table('strategies')->value('active_revision_id'))->not->toBeNull();

        $migration->down();

        expect(Schema::hasTable('strategy_revisions'))->toBeFalse()
            ->and(Schema::hasColumn('strategies', 'active_revision_id'))->toBeFalse()
            ->and(Schema::hasColumn('strategies', 'deleted_at'))->toBeFalse();
    } finally {
        config(['database.default' => $previous]);
        DB::purge('revision_history');
        File::delete($path);
    }
});

it('fails legacy preflight before making schema changes for invalid strategy options', function (string $legacyOptions) {
    $path = database_path('strategy-revision-preflight-test.sqlite');
    File::delete($path);
    File::put($path, '');
    config(['database.connections.revision_preflight' => [
        'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge('revision_preflight');
    $previous = config('database.default');
    config(['database.default' => 'revision_preflight']);

    try {
        Schema::create('strategies', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('note')->nullable();
            $table->boolean('enabled');
            $table->boolean('is_default');
            $table->boolean('enforce');
            $table->text('options')->nullable();
            $table->timestamps();
        });
        Schema::create('devices', fn ($table) => $table->id());
        DB::table('strategies')->insert([
            'name' => 'Broken legacy policy', 'enabled' => true, 'is_default' => false, 'enforce' => false,
            'options' => $legacyOptions, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_24_000010_create_strategy_revision_history.php');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class)
            ->and(Schema::hasTable('strategy_revisions'))->toBeFalse()
            ->and(Schema::hasColumn('strategies', 'deleted_at'))->toBeFalse();
    } finally {
        config(['database.default' => $previous]);
        DB::purge('revision_preflight');
        File::delete($path);
    }
})->with([
    'malformed json' => '{not-json}',
    'false' => 'false',
    'zero' => '0',
    'empty string value' => '""',
    'null' => 'null',
    'list-shaped options' => '["N"]',
]);

it('remains retryable after legacy preflight rejects a row', function () {
    $path = database_path('strategy-revision-retry-test.sqlite');
    File::delete($path);
    File::put($path, '');
    config(['database.connections.revision_retry' => [
        'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge('revision_retry');
    $previous = config('database.default');
    config(['database.default' => 'revision_retry']);

    try {
        Schema::create('users', fn ($table) => $table->id());
        Schema::create('strategies', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('note')->nullable();
            $table->boolean('enabled');
            $table->boolean('is_default');
            $table->boolean('enforce');
            $table->text('options')->nullable();
            $table->timestamps();
        });
        Schema::create('devices', function ($table): void {
            $table->id();
            $table->foreignId('strategy_id_resolved')->nullable();
        });
        $strategyId = DB::table('strategies')->insertGetId([
            'name' => 'Retryable legacy policy', 'enabled' => true, 'is_default' => false, 'enforce' => false,
            'options' => '{broken}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_24_000010_create_strategy_revision_history.php');

        expect(fn () => $migration->up())->toThrow(RuntimeException::class)
            ->and(Schema::hasTable('strategy_revisions'))->toBeFalse()
            ->and(Schema::hasColumn('strategies', 'active_revision_id'))->toBeFalse();

        DB::table('strategies')->where('id', $strategyId)->update(['options' => '{"enable-audio":"N"}']);
        $migration->up();

        expect(Schema::hasTable('strategy_revisions'))->toBeTrue()
            ->and(DB::table('strategy_revisions')->where('strategy_id', $strategyId)->count())->toBe(1)
            ->and(DB::table('strategies')->where('id', $strategyId)->value('active_revision_id'))->not->toBeNull();
    } finally {
        config(['database.default' => $previous]);
        DB::purge('revision_retry');
        File::delete($path);
    }
});
