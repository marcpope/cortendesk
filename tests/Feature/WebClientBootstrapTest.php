<?php

use App\Models\AddressBook;
use App\Models\Setting;
use App\Models\User;

it('serves the webclient bootstrap script without auth', function () {
    $this->get('/webclient-config/index.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript')
        ->assertSee("localStorage.setItem('api-server'", false)
        ->assertSee('http://localhost', false);
});

it('returns server config with the personal book as peer cards', function () {
    config([
        'cortendesk.id_server' => '198.51.100.7',
        'cortendesk.public_key' => 'test-public-key=',
    ]);

    $user = apiUser(['username' => 'webby']);
    $book = AddressBook::personalFor($user);
    $book->entries()->create([
        'rustdesk_id' => '123456789',
        'alias' => 'Studio PC',
        'hostname' => 'STUDIO-01',
        'platform' => 'Windows',
        'username' => 'operator',
        'hash' => 'abc123hash',
    ]);

    $this->postJson('/api/server-config', [], bearerFor($user))
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('data.id_server', '198.51.100.7')
        ->assertJsonPath('data.key', 'test-public-key=')
        ->assertJsonPath('data.peers.123456789.info.hostname', 'STUDIO-01')
        ->assertJsonPath('data.peers.123456789.info.id', '123456789')
        ->assertJsonPath('data.peers.123456789.view-style', 'shrink');
});

it('returns an empty peers object for a user with no entries', function () {
    $user = apiUser(['username' => 'empty-webby']);

    $response = $this->postJson('/api/server-config', [], bearerFor($user))->assertOk();

    // Must serialize as {} (object), not [] — the client indexes it by peer id.
    expect($response->getContent())->toContain('"peers":{}');
});

it('rejects server-config without a token', function () {
    $this->postJson('/api/server-config')->assertStatus(401);
});

/*
 * WebSocket endpoints for the native client. These were hardcoded to
 * wss://<id server host> with no port (#23), so any console not behind TLS on
 * 443 handed the browser an address it could not reach. The bridge lives on
 * the console's own origin, so that is where the URLs have to point.
 */

it('derives ws endpoints from a plain-http console on a non-standard port', function () {
    config(['app.url' => 'http://192.168.7.50:8080', 'cortendesk.ws_id_url' => '', 'cortendesk.ws_relay_url' => '']);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('ws:\/\/192.168.7.50:8080\/ws\/id', false)
        ->assertSee('ws:\/\/192.168.7.50:8080\/ws\/relay', false);
});

it('uses wss and drops the implied port for an https console', function () {
    config(['app.url' => 'https://console.example.com', 'cortendesk.ws_id_url' => '', 'cortendesk.ws_relay_url' => '']);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('wss:\/\/console.example.com\/ws\/id', false);
});

it('keeps a non-default https port', function () {
    config(['app.url' => 'https://console.example.com:8443', 'cortendesk.ws_id_url' => '', 'cortendesk.ws_relay_url' => '']);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('wss:\/\/console.example.com:8443\/ws\/id', false);
});

it('does not follow the ID server host, which need not be the console host', function () {
    config([
        'app.url' => 'https://console.example.com',
        'cortendesk.id_server' => '198.51.100.7:21116',
        'cortendesk.ws_id_url' => '',
        'cortendesk.ws_relay_url' => '',
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('wss:\/\/console.example.com\/ws\/id', false)
        ->assertDontSee('198.51.100.7\/ws\/id', false);
});

it('lets explicit config win over anything derived', function () {
    config([
        'app.url' => 'http://192.168.7.50:8080',
        'cortendesk.ws_id_url' => 'wss://edge.example.com/id',
        'cortendesk.ws_relay_url' => 'wss://edge.example.com/relay',
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('wss:\/\/edge.example.com\/id', false)
        ->assertDontSee('192.168.7.50:8080\/ws\/id', false);
});

/*
 * The key and ID server the web client uses must be the ones the Settings
 * page shows. Both used to come from config alone, so a key entered on
 * Settings (or the setup wizard) never reached the browser, and an external
 * server with CORTENDESK_PUBLIC_KEY unset made every session fail with
 * LICENSE_MISMATCH (#75).
 */

it('hands the web client page the key saved on the Settings page', function () {
    config(['cortendesk.public_key' => 'env-key=']);
    Setting::put('public_key', 'settings-key=');

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('serverKeyB64: "settings-key="', false)
        ->assertDontSee('env-key=', false);
});

it('falls back to the env key when nothing is saved on Settings', function () {
    config(['cortendesk.public_key' => 'env-key=']);

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('serverKeyB64: "env-key="', false);
});

it('falls back to the env key when Settings saved a blank one', function () {
    config(['cortendesk.public_key' => 'env-key=']);
    Setting::put('public_key', '');

    $this->actingAs(User::factory()->create())
        ->get('/webclient')
        ->assertOk()
        ->assertSee('serverKeyB64: "env-key="', false);
});

it('serves the Settings-page key and ID server from server-config', function () {
    config(['cortendesk.id_server' => 'env.example.com:21116', 'cortendesk.public_key' => 'env-key=']);
    Setting::put('id_server', 'hbbs.example.com:21116');
    Setting::put('public_key', " settings-key=\n");

    $this->postJson('/api/server-config', [], bearerFor(apiUser(['username' => 'keyed'])))
        ->assertOk()
        ->assertJsonPath('data.id_server', 'hbbs.example.com:21116')
        ->assertJsonPath('data.key', 'settings-key=');
});

// RustDesk 1.5 WebRTC: on by default, with the console's own ICE servers.
it('hands the web client its WebRTC settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/webclient')->assertOk()
        ->assertSee('webrtc: {"iceServers":""}', false);

    Setting::put('webclient_ice_servers', 'stun:stun.example.com:3478');
    $this->actingAs($user)->get('/webclient')->assertOk()
        ->assertSee('webrtc: {"iceServers":"stun:stun.example.com:3478"}', false);

    Setting::put('webclient_webrtc', '0');
    $this->actingAs($user)->get('/webclient')->assertOk()
        ->assertSee('webrtc: null', false);
});

it('saves the web client WebRTC settings and refuses an ICE server the client cannot use', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(App\Livewire\SettingsPage::class)
        ->set('webClientIceServers', 'turn:turn.example.com')
        ->call('save')
        ->assertHasErrors('webClientIceServers');
    expect(Setting::get('webclient_ice_servers'))->toBeNull();

    Livewire\Livewire::test(App\Livewire\SettingsPage::class)
        ->set('webClientWebRtc', false)
        ->set('webClientIceServers', ' STUN:stun.example.com , turn://rd:pw@turn.example.com ')
        ->call('save')
        ->assertHasNoErrors();
    expect(Setting::get('webclient_webrtc'))->toBe('0')
        ->and(Setting::get('webclient_ice_servers'))->toBe('stun:stun.example.com,turn://rd:pw@turn.example.com');
});
