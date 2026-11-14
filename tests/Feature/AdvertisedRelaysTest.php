<?php

use App\Contracts\IdServerConsole;
use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdvertisedRelays;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * #181: the relay list saved on Settings → Server reaches the ID server
 * bundled in the container: pushed over hbbs's loopback console
 * (`relay-servers a,b`) and written to the .env file hbbs reads at start.
 */

/** Stands in for hbbs's loopback console: takes a relay list, prints it back. */
class FakeRelayListConsole implements IdServerConsole
{
    public array $sent = [];

    public bool $up = true;

    public array $list = ['console.example.com:21117'];

    public function command(string $command): ?string
    {
        if (! $this->up) {
            return null;
        }
        $this->sent[] = $command;
        [, $value] = array_pad(explode(' ', $command, 2), 2, null);
        if ($value === null) {
            return implode("\n", $this->list)."\n";
        }
        $this->list = explode(',', $value);

        return '';
    }
}

function relayEnvFile(string $dir): ?string
{
    return is_file($dir.'/.env') ? file_get_contents($dir.'/.env') : null;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/cd-relays-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    config([
        'cortendesk.embedded_server' => true,
        'cortendesk.rustdesk_dir' => $this->dir,
        'cortendesk.relay_server' => 'console.example.com:21117',
    ]);
    $this->hbbs = new FakeRelayListConsole;
    app()->instance(IdServerConsole::class, $this->hbbs);
    $this->actingAs(User::create(['username' => 'relay-admin', 'password' => 'secret-password', 'is_admin' => true]));
});

afterEach(function () {
    array_map('unlink', array_filter(glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
    @rmdir($this->dir);
});

it('hands out the environment address when nothing is saved', function () {
    $relays = app(AdvertisedRelays::class);

    expect($relays->saved())->toBe([])
        ->and($relays->addresses())->toBe(['console.example.com:21117'])
        ->and($relays->apply())->toBeTrue()
        ->and($this->hbbs->sent)->toBe(['relay-servers console.example.com:21117'])
        ->and(relayEnvFile($this->dir))->toBeNull('nothing saved, so hbbs keeps reading its environment');
});

it('prefers the pool over the relay field', function () {
    Setting::put('relay_server', 'single.example.com:21117');
    $relays = app(AdvertisedRelays::class);

    expect($relays->saved())->toBe(['single.example.com:21117']);

    Setting::put('relay_servers', json_encode([
        ['address' => 'relay-us.example.com:21117', 'geo' => 'US'],
        ['address' => ' relay-eu.example.com ', 'geo' => 'EU'],
        ['address' => 'relay-us.example.com:21117', 'geo' => ''],
    ]));

    expect($relays->saved())->toBe(['relay-us.example.com:21117', 'relay-eu.example.com']);
});

it('leaves out an address that would break the list', function () {
    Setting::put('relay_servers', json_encode([
        ['address' => 'good.example.com:21117', 'geo' => ''],
        ['address' => 'a.example.com,b.example.com', 'geo' => ''],
        ['address' => "c.example.com\nRENDEZVOUS_SERVERS=x", 'geo' => ''],
        ['address' => '[2001:db8::1]:21117', 'geo' => ''],
    ]));

    expect(app(AdvertisedRelays::class)->saved())->toBe(['good.example.com:21117', '[2001:db8::1]:21117']);
});

it('pushes a saved pool to the ID server and writes it for the next start', function () {
    file_put_contents($this->dir.'/.env', "SINGLE_BANDWIDTH=64\nRELAY-SERVERS=old.example.com\n");

    Livewire::test(SettingsPage::class)
        ->assertSee('console.example.com:21117')
        ->call('addRelay')
        ->set('relayServers.0.address', 'relay-us.example.com:21117')
        ->call('addRelay')
        ->set('relayServers.1.address', 'relay-eu.example.com:21117')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('relaysLive', ['relay-us.example.com:21117', 'relay-eu.example.com:21117']);

    expect($this->hbbs->list)->toBe(['relay-us.example.com:21117', 'relay-eu.example.com:21117'])
        ->and(relayEnvFile($this->dir))->toBe("SINGLE_BANDWIDTH=64\nRELAY_SERVERS=relay-us.example.com:21117,relay-eu.example.com:21117\n");
});

it('goes back to the environment address when the pool is emptied', function () {
    Setting::put('relay_servers', json_encode([['address' => 'relay.example.com:21117', 'geo' => '']]));
    app(AdvertisedRelays::class)->apply();
    expect(relayEnvFile($this->dir))->toBe("RELAY_SERVERS=relay.example.com:21117\n");

    Livewire::test(SettingsPage::class)
        ->call('removeRelay', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->hbbs->list)->toBe(['console.example.com:21117'])
        ->and(relayEnvFile($this->dir))->toBe('');
});

it('does not save the relay field while it only shows the environment address', function () {
    Livewire::test(SettingsPage::class)
        ->assertSet('relayServer', 'console.example.com:21117')
        ->set('onlineWindow', 45)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('relay_server'))->toBe('')
        ->and(relayEnvFile($this->dir))->toBeNull();

    // The environment changes (a new hostname): nothing stale is in the way.
    config(['cortendesk.relay_server' => 'new.example.com:21117']);
    expect(app(AdvertisedRelays::class)->addresses())->toBe(['new.example.com:21117']);
});

it('hands out a relay typed into the relay field', function () {
    Livewire::test(SettingsPage::class)
        ->set('relayServer', 'relay.example.com:21117')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('relay_server'))->toBe('relay.example.com:21117')
        ->and($this->hbbs->list)->toBe(['relay.example.com:21117'])
        ->and(relayEnvFile($this->dir))->toBe("RELAY_SERVERS=relay.example.com:21117\n");
});

it('still writes the list when the ID server is not answering', function () {
    $this->hbbs->up = false;
    Setting::put('relay_servers', json_encode([['address' => 'relay.example.com:21117', 'geo' => '']]));

    expect(app(AdvertisedRelays::class)->apply())->toBeFalse()
        ->and(relayEnvFile($this->dir))->toBe("RELAY_SERVERS=relay.example.com:21117\n");
});

it('does nothing when the ID server runs elsewhere', function () {
    config(['cortendesk.embedded_server' => false]);
    Setting::put('relay_servers', json_encode([['address' => 'relay.example.com:21117', 'geo' => '']]));

    Livewire::test(SettingsPage::class)
        ->assertSet('relaysLive', null)
        ->set('relayServer', 'other.example.com:21117')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->hbbs->sent)->toBe([])
        ->and(relayEnvFile($this->dir))->toBeNull()
        ->and(Setting::get('relay_server'))->toBe('other.example.com:21117');

    $this->artisan('cortendesk:relay-servers')
        ->expectsOutputToContain('nothing to do')
        ->assertSuccessful();
});

it('applies the saved list when the container starts', function () {
    $this->hbbs->up = false; // hbbs is not running yet at that point
    Setting::put('relay_servers', json_encode([['address' => 'relay.example.com:21117', 'geo' => '']]));

    $this->artisan('cortendesk:relay-servers')
        ->expectsOutputToContain('relay.example.com:21117 (saved in Settings, Server)')
        ->assertSuccessful();

    expect(relayEnvFile($this->dir))->toBe("RELAY_SERVERS=relay.example.com:21117\n");
});

it('clears a relay field saved before it had any effect, once, on a bundled install', function () {
    $migration = require database_path('migrations/2026_11_14_000010_clear_saved_relay_server_on_bundled_installs.php');
    Setting::put('relay_server', 'stale.example.com:21117');
    Setting::put('relay_servers', json_encode([['address' => 'relay.example.com:21117', 'geo' => '']]));

    config(['cortendesk.embedded_server' => false]);
    $migration->up();
    expect(DB::table('settings')->where('key', 'relay_server')->value('value'))->toBe('stale.example.com:21117');

    config(['cortendesk.embedded_server' => true]);
    $migration->up();
    expect(DB::table('settings')->where('key', 'relay_server')->exists())->toBeFalse()
        ->and(DB::table('settings')->where('key', 'relay_servers')->exists())->toBeTrue();
});
