<?php

use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function actingSettingsAdmin(): User
{
    $user = User::create([
        'username' => 'settings-admin',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

it('defaults the Build Installers URL from config', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->assertSet('rdgenUrl', config('cortendesk.rdgen_url'));
});

it('saves a custom Build Installers URL', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('rdgenUrl', 'https://rdgen.example.com/')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('rdgen_url'))->toBe('https://rdgen.example.com');
});

it('rejects a non-URL Build Installers value', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('rdgenUrl', 'not a url')
        ->call('save')
        ->assertHasErrors(['rdgenUrl' => 'url']);
});

it('links the sidebar entry to the configured URL', function () {
    actingSettingsAdmin();
    Setting::put('rdgen_url', 'https://rdgen.example.com');

    $this->get('/')->assertSee('https://rdgen.example.com')
        ->assertSee('Build Installers');
});

it('hides the sidebar entry when the URL is empty', function () {
    actingSettingsAdmin();
    Setting::put('rdgen_url', '');

    $this->get('/')->assertDontSee('Build Installers');
});

it('saves relay server rows as JSON in the Setting model', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->call('addRelay')
        ->set('relayServers.0.address', 'relay-us.example.com:21117')
        ->set('relayServers.0.geo', 'US')
        ->call('addRelay')
        ->set('relayServers.1.address', 'relay-eu.example.com:21117')
        ->set('relayServers.1.geo', 'EU')
        ->call('save')
        ->assertHasNoErrors();

    expect(json_decode(Setting::get('relay_servers'), true))->toBe([
        ['address' => 'relay-us.example.com:21117', 'geo' => 'US'],
        ['address' => 'relay-eu.example.com:21117', 'geo' => 'EU'],
    ]);
});

it('drops blank relay rows on save', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->call('addRelay')
        ->set('relayServers.0.address', 'relay.example.com:21117')
        ->call('addRelay') // left blank
        ->call('save')
        ->assertHasNoErrors()
        ->assertCount('relayServers', 1);

    expect(json_decode(Setting::get('relay_servers'), true))->toBe([
        ['address' => 'relay.example.com:21117', 'geo' => ''],
    ]);
});

it('removes a relay row', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->call('addRelay')
        ->set('relayServers.0.address', 'a.example.com:21117')
        ->call('addRelay')
        ->set('relayServers.1.address', 'b.example.com:21117')
        ->call('removeRelay', 0)
        ->assertCount('relayServers', 1)
        ->assertSet('relayServers.0.address', 'b.example.com:21117');
});

it('rejects an over-long relay address', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->call('addRelay')
        ->set('relayServers.0.address', str_repeat('a', 256))
        ->call('save')
        ->assertHasErrors(['relayServers.0.address' => 'max']);
});

it('loads stored relay rows on mount', function () {
    actingSettingsAdmin();
    Setting::put('relay_servers', json_encode([
        ['address' => 'relay.example.com:21117', 'geo' => 'US'],
    ]));

    Livewire::test(SettingsPage::class)
        ->assertSet('relayServers.0.address', 'relay.example.com:21117')
        ->assertSet('relayServers.0.geo', 'US');
});

it('falls back to the single relay when no list is configured', function () {
    Setting::put('relay_server', 'single.example.com:21117');

    expect(Setting::relayServers())->toBe([
        ['address' => 'single.example.com:21117', 'geo' => ''],
    ]);
});

it('returns the configured relay list over the single fallback', function () {
    Setting::put('relay_server', 'single.example.com:21117');
    Setting::put('relay_servers', json_encode([
        ['address' => 'relay-us.example.com:21117', 'geo' => 'US'],
        ['address' => 'relay-eu.example.com:21117', 'geo' => 'EU'],
    ]));

    expect(Setting::relayServers())->toBe([
        ['address' => 'relay-us.example.com:21117', 'geo' => 'US'],
        ['address' => 'relay-eu.example.com:21117', 'geo' => 'EU'],
    ]);
});

it('falls back to the single relay when the stored list is empty', function () {
    Setting::put('relay_server', 'single.example.com:21117');
    Setting::put('relay_servers', json_encode([]));

    expect(Setting::relayServers())->toBe([
        ['address' => 'single.example.com:21117', 'geo' => ''],
    ]);
});

// --- Email tab (PLAN D1) ----------------------------------------------------

it('renders the Email tab with the SMTP card', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('tab', 'email')
        ->assertSee('Outbound Email')
        ->assertSee('Send a test email')
        ->assertSee('Require an emailed code on new devices');
});

it('will not arm emailed sign-in codes without a working relay', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('emailLoginVerification', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('emailLoginVerification', false);

    expect(Setting::get('email_login_verification'))->toBe('0');
});

it('arms emailed sign-in codes once email is enabled', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('smtpEnabled', true)
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpFromAddress', 'console@example.com')
        ->set('emailLoginVerification', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('emailLoginVerification', true);

    expect(Setting::get('email_login_verification'))->toBe('1');
});

/*
|--------------------------------------------------------------------------
| Server identity values are trimmed (#11 follow-up)
|--------------------------------------------------------------------------
| id_ed25519.pub is written with no trailing newline, so whitespace around a
| public key was picked up in transit — an editor, a copy-paste, a heredoc in
| a compose file. The key is compared exactly, so an untrimmed one fails in a
| way that is indistinguishable from a genuinely wrong key: clients just do
| not connect, and nothing on screen hints at why.
*/

it('trims whitespace from the public key on save', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('publicKey', "  GG2jFmQYGsGGC8L7YuiDu3KURGEAQEOdc7thqisIcz8=\n")
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('public_key'))->toBe('GG2jFmQYGsGGC8L7YuiDu3KURGEAQEOdc7thqisIcz8=');
});

it('trims whitespace from the id and relay server on save', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('idServer', "  hbbs.example.com:21116 ")
        ->set('relayServer', "hbbs.example.com:21117\n")
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('id_server'))->toBe('hbbs.example.com:21116')
        ->and(Setting::get('relay_server'))->toBe('hbbs.example.com:21117');
});

it('trims the public key coming from the environment', function () {
    // Re-evaluate the real config file with a whitespace-padded env value,
    // rather than asserting trim() against a value the test just set — that
    // would pass even if the config file dropped the trim entirely.
    // env() resolves through Laravel's own repository, populated at boot, so
    // putenv() alone does not reach it — the value has to be set there.
    $repo = \Illuminate\Support\Env::getRepository();
    $original = $repo->get('CORTENDESK_PUBLIC_KEY');
    $repo->set('CORTENDESK_PUBLIC_KEY', "  key-from-env  \n");

    try {
        $config = require config_path('cortendesk.php');
        expect($config['public_key'])->toBe('key-from-env');
    } finally {
        $original === null
            ? $repo->clear('CORTENDESK_PUBLIC_KEY')
            : $repo->set('CORTENDESK_PUBLIC_KEY', $original);
    }
});

// --- Cross-tab validation visibility (issue #18's second act) ---------------

it('jumps to the failing tab when validation blocks a save from another tab', function () {
    // One validate() spans every tab: an out-of-range field on the Server tab
    // silently vetoed a save clicked on the Security tab, with the error
    // rendered in a pane the operator wasn't looking at. Nothing was written
    // and nothing said so.
    actingSettingsAdmin();

    Livewire::withQueryParams(['tab' => 'security'])
        ->test(SettingsPage::class)
        ->set('onlineWindow', 86400)
        ->set('twoFactorRequired', true)
        ->call('save')
        ->assertHasErrors('onlineWindow')
        ->assertSet('tab', 'server');

    // The veto is total — the toggle the operator DID change was not saved.
    expect(Setting::get('two_factor_required'))->toBeNull();
});

it('jumps to the SSO tab when enabling SSO without its required fields', function () {
    actingSettingsAdmin();

    Livewire::withQueryParams(['tab' => 'security'])
        ->test(SettingsPage::class)
        ->set('oidcEnabled', true)
        ->call('save')
        ->assertHasErrors('oidcDiscoveryUrl')
        ->assertSet('tab', 'sso');
});

it('jumps to the Email tab when enabling SMTP without a host', function () {
    actingSettingsAdmin();

    Livewire::withQueryParams(['tab' => 'security'])
        ->test(SettingsPage::class)
        ->set('smtpEnabled', true)
        ->call('save')
        ->assertHasErrors('smtpHost')
        ->assertSet('tab', 'email');
});

it('saves the client URL scheme without its separator or capitals', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->assertSet('clientUrlScheme', 'rustdesk')
        ->set('clientUrlScheme', 'Level1-Tech-Support://')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('clientUrlScheme', 'level1-tech-support');

    expect(Setting::get('client_url_scheme'))->toBe('level1-tech-support');
});

it('rejects a client URL scheme a browser would not open', function () {
    actingSettingsAdmin();

    Livewire::test(SettingsPage::class)
        ->set('clientUrlScheme', 'level1 tech support')
        ->call('save')
        ->assertHasErrors('clientUrlScheme');
});

// With the bundled server the key is the one its private key yields (the
// entrypoint derives it at boot), so a key saved here earlier must not win
// and the field can't change it (#137).
it('uses the bundled server key over a saved one and keeps the field read-only', function () {
    actingSettingsAdmin();
    config(['cortendesk.embedded_server' => true, 'cortendesk.public_key' => 'bundled-key=']);
    Setting::put('public_key', 'stale-key=');

    expect(Setting::server('public_key'))->toBe('bundled-key=');

    Livewire::test(SettingsPage::class)
        ->assertSet('publicKey', 'bundled-key=')
        ->assertSeeHtml('readonly')
        ->assertSee(__('settings.public_key_from_bundled_server'))
        ->set('publicKey', 'typed-key=')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('public_key'))->toBe('stale-key=')
        ->and(Setting::server('public_key'))->toBe('bundled-key=');
});

it('keeps the saved key when the server runs elsewhere', function () {
    actingSettingsAdmin();
    config(['cortendesk.embedded_server' => false, 'cortendesk.public_key' => 'env-key=']);
    Setting::put('public_key', 'saved-key=');

    expect(Setting::server('public_key'))->toBe('saved-key=');
});
