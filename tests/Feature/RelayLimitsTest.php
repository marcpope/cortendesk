<?php

use App\Contracts\RelayConsole;
use App\Livewire\SettingsPage;
use App\Models\ConsoleAudit;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\RelayLimits;
use Livewire\Livewire;

/** Stands in for hbbr's loopback console: remembers what it was sent, answers reads. */
class FakeRelayConsole implements RelayConsole
{
    public array $sent = [];

    public bool $up = true;

    public array $values = ['sb' => '128Mb/s', 'tb' => '1024Mb/s', 't' => '1800s', 'dt' => '0.66', 'ls' => '32Mb/s'];

    public function command(string $command): ?string
    {
        if (! $this->up) {
            return null;
        }
        $this->sent[] = $command;
        [$name, $value] = array_pad(explode(' ', $command, 2), 2, null);
        if ($value === null) {
            return ($this->values[$name] ?? '')."\n";
        }
        $this->values[$name] = $value.(in_array($name, ['sb', 'tb', 'ls'], true) ? 'Mb/s' : ($name === 't' ? 's' : ''));

        return '';
    }
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/cd-relay-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    config(['cortendesk.embedded_server' => true, 'cortendesk.rustdesk_dir' => $this->dir]);
    $this->relay = new FakeRelayConsole;
    app()->instance(RelayConsole::class, $this->relay);
    $this->actingAs(User::create(['username' => 'relay-admin', 'password' => 'secret-password', 'is_admin' => true]));
});

afterEach(function () {
    array_map('unlink', array_filter(glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
    @rmdir($this->dir);
});

it('shows what the running relay uses before anything is saved', function () {
    $this->relay->values['sb'] = '256Mb/s';

    Livewire::test(SettingsPage::class)
        ->assertSet('relayLimits.single', 256)
        ->assertSet('relayLimits.total', 1024)
        ->assertSet('relayLimits.slowAfter', 30)
        ->assertSet('relayLimits.slowAbove', 66)
        ->assertSee(__('settings.relay_bandwidth'));
});

it('pushes saved limits to the relay and writes its .env file', function () {
    file_put_contents($this->dir.'/.env', "RUST_LOG=info\nSINGLE_BANDWIDTH=50\n");

    Livewire::test(SettingsPage::class)
        ->set('relayLimits.single', 500)
        ->set('relayLimits.total', 1000)
        ->set('relayLimits.slowAfter', 120)
        ->set('relayLimits.slowAbove', 29)
        ->set('relayLimits.slowTo', 64)
        ->call('saveRelayLimits')
        ->assertHasNoErrors()
        ->assertSet('relayLimitsResult', 'applied')
        ->assertSet('relayLimitsDrift', false);

    expect($this->relay->sent)->toContain('sb 500', 'tb 1000', 't 7200', 'dt 0.291', 'ls 64')
        ->and(file_get_contents($this->dir.'/.env'))->toBe(
            "RUST_LOG=info\nSINGLE_BANDWIDTH=500\nTOTAL_BANDWIDTH=1000\nDOWNGRADE_START_CHECK=7200\nDOWNGRADE_THRESHOLD=0.291\nLIMIT_SPEED=64\n"
        )
        ->and(json_decode(Setting::get(RelayLimits::SETTING), true))->toMatchArray(['single' => 500, 'slowAbove' => 29])
        ->and(ConsoleAudit::query()->where('action', 'settings.relay-limits')->exists())->toBeTrue();
});

it('turns the slowdown off by pushing its start out of reach', function () {
    Livewire::test(SettingsPage::class)
        ->set('relayLimits.slowDown', false)
        ->call('saveRelayLimits')
        ->assertHasNoErrors()
        ->assertSet('relayLimits.slowDown', false);

    expect($this->relay->sent)->toContain('t 315360000');
});

it('keeps the saved limits for the next start when the relay does not answer', function () {
    $this->relay->up = false;

    Livewire::test(SettingsPage::class)
        ->assertSee(__('settings.relay_bandwidth_unreachable'))
        ->set('relayLimits.single', 300)
        ->call('saveRelayLimits')
        ->assertSet('relayLimitsResult', 'pending');

    expect(file_get_contents($this->dir.'/.env'))->toContain('SINGLE_BANDWIDTH=300');
});

it('flags a relay running other limits than the saved ones', function () {
    Setting::put(RelayLimits::SETTING, json_encode(RelayLimits::normalize(['single' => 500])));

    Livewire::test(SettingsPage::class)
        ->assertSet('relayLimits.single', 500)
        ->assertSet('relayLimitsDrift', true)
        ->assertSee('128 Mb/s');
});

it('rejects a limit of zero', function () {
    Livewire::test(SettingsPage::class)
        ->set('relayLimits.single', 0)
        ->call('saveRelayLimits')
        ->assertHasErrors(['relayLimits.single']);

    expect($this->relay->sent)->not->toContain('sb 0');
});

it('points to environment variables when the relay is not bundled', function () {
    config(['cortendesk.embedded_server' => false]);

    Livewire::test(SettingsPage::class)
        ->assertSee('SINGLE_BANDWIDTH')
        ->call('saveRelayLimits');

    expect($this->relay->sent)->toBe([])
        ->and(Setting::get(RelayLimits::SETTING))->toBeNull();
});

it('does not let a view-only role change the relay limits', function () {
    $role = Role::create(['name' => 'Settings viewer', 'permissions' => Role::normalizePermissions(['setting' => 'r'])]);
    $viewer = User::factory()->create(['role_id' => $role->id]);

    Livewire::actingAs($viewer)
        ->test(SettingsPage::class)
        ->assertSee(__('settings.relay_bandwidth'))
        ->set('relayLimits.single', 999)
        ->call('saveRelayLimits')
        ->assertForbidden();

    expect($this->relay->sent)->not->toContain('sb 999')
        ->and(Setting::get(RelayLimits::SETTING))->toBeNull();
});
