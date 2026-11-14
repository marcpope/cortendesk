<?php

use App\Contracts\TcpProbe;
use App\Livewire\SettingsPage;
use App\Models\ClientToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use App\Services\FleetDiagnostics;
use App\Services\ServerLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * The link with CortenDesk Server (issues #81, #82, #76): the policy hbbs
 * pulls, the LAN addresses it posts, the web client ticket, and where the
 * console shows the link's state. Contract: docs/server-link.md.
 */

const LINK_SECRET = 'link-test-secret';

function linkOn(): void
{
    config(['cortendesk.server_secret' => LINK_SECRET]);
}

function linkHeaders(string $secret = LINK_SECRET): array
{
    return ['Authorization' => 'Bearer '.$secret, 'User-Agent' => 'cortendesk-server/1.1.0'];
}

function linkDevice(string $id, array $attrs = []): Device
{
    return Device::create(['rustdesk_id' => $id, 'uuid' => 'u-'.$id] + $attrs);
}

// ---- GET /api/server/policy ------------------------------------------------

it('does not exist while no secret is configured', function () {
    config(['cortendesk.server_secret' => '']);

    $this->getJson('/api/server/policy', linkHeaders())->assertNotFound();
    $this->postJson('/api/server/local-addrs', ['addrs' => []], linkHeaders())->assertNotFound();
});

it('rejects a missing or wrong secret', function () {
    linkOn();

    $this->getJson('/api/server/policy')->assertUnauthorized();
    $this->getJson('/api/server/policy', linkHeaders('wrong'))->assertUnauthorized();
    $this->postJson('/api/server/local-addrs', ['addrs' => []], linkHeaders('wrong'))->assertUnauthorized();
});

it('lists approved devices by whether they may start sessions', function () {
    linkOn();
    linkDevice('100000001');
    linkDevice('100000002')->update(['can_initiate' => false]);
    linkDevice('100000003', ['status' => Device::STATUS_PENDING]);
    linkDevice('100000004')->delete();

    $policy = $this->getJson('/api/server/policy', linkHeaders())->assertOk()->json();

    expect($policy['mode'])->toBe('open')
        ->and($policy['allow'])->toBe(['100000001'])
        ->and($policy['incoming_only'])->toBe(['100000002']);
});

it('sends the approved-only mode', function () {
    linkOn();
    Setting::put('server_access_mode', ServerLink::MODE_APPROVED);

    expect($this->getJson('/api/server/policy', linkHeaders())->json('mode'))->toBe('approved');
});

it('sends ip_match off unless settings turn it on', function () {
    linkOn();
    expect($this->getJson('/api/server/policy', linkHeaders())->json('ip_match'))->toBeFalse();

    Setting::put('server_ip_match', '1');
    expect($this->getJson('/api/server/policy', linkHeaders())->json('ip_match'))->toBeTrue();
});

it('maps hashed access tokens to the device they were issued to', function () {
    linkOn();
    $user = User::factory()->create();
    $live = ClientToken::issue($user, ['id' => '100000001']);
    ClientToken::issue($user, ['id' => '100000002'])->update(['expires_at' => now()->subDay()]);
    ClientToken::issue($user);

    $tokens = $this->getJson('/api/server/policy', linkHeaders())->json('tokens');

    expect($tokens)->toBe([hash('sha256', $live->token) => '100000001']);
});

it('answers 304 while the policy is unchanged', function () {
    linkOn();
    linkDevice('100000001');

    $first = $this->getJson('/api/server/policy', linkHeaders())->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeEmpty();

    $this->getJson('/api/server/policy', linkHeaders() + ['If-None-Match' => $etag])
        ->assertStatus(304);

    Device::where('rustdesk_id', '100000001')->update(['can_initiate' => false]);
    $this->getJson('/api/server/policy', linkHeaders() + ['If-None-Match' => $etag])
        ->assertOk()
        ->assertJsonPath('incoming_only', ['100000001']);
});

it('records the pull for settings and diagnostics', function () {
    linkOn();
    expect(ServerLink::status()['last_pull_at'])->toBeNull();

    $this->getJson('/api/server/policy', linkHeaders())->assertOk();

    $status = ServerLink::status();
    expect($status['fresh'])->toBeTrue()
        ->and($status['ok'])->toBeTrue()
        ->and($status['agent'])->toBe('cortendesk-server/1.1.0');
});

it('is rate limited', function () {
    $route = Route::getRoutes()->match(Request::create('/api/server/local-addrs', 'POST'));

    expect($route->gatherMiddleware())->toContain('throttle:600,1,server-link');
});

// ---- POST /api/server/local-addrs -------------------------------------------

it('stores the reported LAN address', function () {
    linkOn();
    $device = linkDevice('100000001');
    $seen = now()->subMinute()->startOfSecond();

    $this->postJson('/api/server/local-addrs', ['addrs' => [
        ['id' => '100000001', 'ip' => '192.168.1.20', 'seen_at' => $seen->timestamp],
        ['id' => '999999999', 'ip' => '10.0.0.9', 'seen_at' => $seen->timestamp],
    ]], linkHeaders())->assertOk()->assertJson(['updated' => 1]);

    $device->refresh();
    expect($device->lan_ip)->toBe('192.168.1.20')
        ->and($device->lan_ip_seen_at->timestamp)->toBe($seen->timestamp);
});

it('ignores recycled devices and older reports', function () {
    linkOn();
    $device = linkDevice('100000001');
    $device->forceFill(['lan_ip' => '192.168.1.30', 'lan_ip_seen_at' => now()])->saveQuietly();
    linkDevice('100000002')->delete();

    $this->postJson('/api/server/local-addrs', ['addrs' => [
        ['id' => '100000001', 'ip' => '192.168.1.20', 'seen_at' => now()->subHour()->timestamp],
        ['id' => '100000002', 'ip' => '192.168.1.21'],
    ]], linkHeaders())->assertOk()->assertJson(['updated' => 0]);

    expect($device->fresh()->lan_ip)->toBe('192.168.1.30')
        ->and(Device::withTrashed()->where('rustdesk_id', '100000002')->value('lan_ip'))->toBeNull();
});

it('clamps a report from the future to now', function () {
    linkOn();
    $device = linkDevice('100000001');

    $this->postJson('/api/server/local-addrs', ['addrs' => [
        ['id' => '100000001', 'ip' => 'fd00::20', 'seen_at' => now()->addDay()->timestamp],
    ]], linkHeaders())->assertOk();

    expect($device->fresh()->lan_ip)->toBe('fd00::20')
        ->and($device->fresh()->lan_ip_seen_at->lte(now()))->toBeTrue();
});

it('validates reports', function () {
    linkOn();

    $this->postJson('/api/server/local-addrs', [], linkHeaders())->assertUnprocessable();
    $this->postJson('/api/server/local-addrs', ['addrs' => [['id' => '1', 'ip' => 'not-an-ip']]], linkHeaders())
        ->assertUnprocessable();
    $this->postJson('/api/server/local-addrs', ['addrs' => [['ip' => '10.0.0.1']]], linkHeaders())
        ->assertUnprocessable();

    $tooMany = array_fill(0, 501, ['id' => '1', 'ip' => '10.0.0.1']);
    $this->postJson('/api/server/local-addrs', ['addrs' => $tooMany], linkHeaders())->assertUnprocessable();
});

// ---- web client ticket ------------------------------------------------------

it('hands the web client a ticket signed with the secret', function () {
    linkOn();
    $user = User::factory()->admin()->create();

    $html = $this->actingAs($user)->get('/webclient?id=100000001')->assertOk()->getContent();

    preg_match('/signalToken: "([^"]+)"/', $html, $m);
    expect($m)->toHaveCount(2);
    [$prefix, $expiry, $subject, $sig] = explode('.', $m[1]);
    expect($prefix)->toBe('cdw1')
        ->and($subject)->toBe('web-'.$user->id)
        ->and((int) $expiry)->toBeGreaterThan(time())
        ->and($sig)->toBe(hash_hmac('sha256', "cdw1.$expiry.$subject", LINK_SECRET));
});

it('hands out no ticket without a secret', function () {
    config(['cortendesk.server_secret' => '']);
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/webclient')->assertOk()->assertSee('signalToken: ""', false);
});

// ---- settings ----------------------------------------------------------------

it('saves the server access mode and audits the change', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->assertSet('serverAccessMode', 'open')
        ->set('serverAccessMode', 'approved')
        ->call('save')
        ->assertHasNoErrors();

    expect(ServerLink::mode())->toBe('approved')
        ->and(ConsoleAudit::where('action', 'settings.server-access')->count())->toBe(1);
});

it('saves IP matching for signed-out devices and audits the change', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->assertSet('serverIpMatch', false)
        ->set('serverIpMatch', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(ServerLink::ipMatch())->toBeTrue()
        ->and(ConsoleAudit::where('action', 'settings.server-ip-match')->count())->toBe(1);
});

it('rejects an unknown server access mode', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->set('serverAccessMode', 'locked')
        ->call('save')
        ->assertHasErrors(['serverAccessMode' => 'in']);

    expect(ServerLink::mode())->toBe('open');
});

it('warns in settings when the mode needs a link that is missing', function () {
    config(['cortendesk.server_secret' => '']);
    $admin = User::factory()->admin()->create();
    Setting::put('server_access_mode', 'approved');

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->assertSee('Approved-only mode and incoming-only devices are not enforced.')
        ->assertSee('Needs CortenDesk Server 1.1.0 or later');
});

// ---- diagnostics -------------------------------------------------------------

it('reports the link state in diagnostics', function () {
    $this->app->instance(TcpProbe::class, new class implements TcpProbe
    {
        public function check(string $host, int $port, float $timeout): array
        {
            return ['ok' => true, 'latency_ms' => 1, 'error' => null];
        }
    });
    $admin = User::factory()->admin()->create();

    config(['cortendesk.server_secret' => '']);
    expect(app(FleetDiagnostics::class)->report()['server_link']['ok'])->toBeNull();

    // Something to enforce and nobody enforcing it.
    linkDevice('100000001')->update(['can_initiate' => false]);
    expect(app(FleetDiagnostics::class)->report()['server_link']['ok'])->toBeFalse();

    linkOn();
    expect(app(FleetDiagnostics::class)->report()['server_link']['ok'])->toBeFalse();
    $this->getJson('/api/server/policy', linkHeaders())->assertOk();
    $report = app(FleetDiagnostics::class)->report();
    expect($report['server_link']['ok'])->toBeTrue()
        ->and($report['server_link']['mode'])->toBe('open');

    $this->actingAs($admin)->get('/diagnostics')
        ->assertOk()
        ->assertSee('ID server link')
        ->assertSee('cortendesk-server/1.1.0');

    $this->travel(2)->minutes();
    expect(app(FleetDiagnostics::class)->report()['server_link']['ok'])->toBeFalse();
});
