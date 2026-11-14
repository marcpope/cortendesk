<?php

use App\Livewire\DeviceShares;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceShare;
use App\Models\DeviceShareSession;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Services\ServerLink;
use App\Support\IceServers;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Share links (PR #142, docs/share-links.md): a secret link opens the web
 * client for one device, for someone without an account.
 */

const SHARE_SECRET = 'share-test-secret';

/** A share for $this->device, made through the console, and its link token. */
function makeShare(User $user, Device $device, string $duration = '1h', int $viewers = 1): array
{
    $link = Livewire::actingAs($user)->test(DeviceShares::class, ['deviceId' => $device->id])
        ->call('startCreate')
        ->set('label', 'Support call')
        ->set('duration', $duration)
        ->set('maxViewers', $viewers)
        ->call('create')
        ->assertHasNoErrors()
        ->get('newLink');
    app('auth')->forgetGuards();

    return [DeviceShare::latest('id')->firstOrFail(), substr($link, strpos($link, '#') + 1)];
}

function startShare(string $token)
{
    return test()->postJson('/s/start', ['token' => $token]);
}

function pingShare(string $token, string $session)
{
    return test()->postJson('/s/ping', ['token' => $token, 'session' => $session]);
}

beforeEach(function () {
    config(['cortendesk.share_links' => true, 'cortendesk.server_secret' => SHARE_SECRET]);
    $this->device = Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u-1', 'hostname' => 'pc']);
    $this->other = Device::create(['rustdesk_id' => '987654321', 'uuid' => 'u-2', 'hostname' => 'other']);
    $this->admin = User::factory()->admin()->create();
});

/** The fields of a cdw2 ticket, after checking its HMAC. */
function boundTicket(string $ticket): array
{
    [$signed, $sig] = [substr($ticket, 0, strrpos($ticket, '.')), substr($ticket, strrpos($ticket, '.') + 1)];
    expect(hash_equals(hash_hmac('sha256', $signed, SHARE_SECRET), $sig))->toBeTrue();
    [$prefix, $exp, $target, $subject] = explode('.', $signed);

    return compact('prefix', 'exp', 'target', 'subject');
}

it('creates a link shown once and stored only as a hash', function () {
    [$share, $token] = makeShare($this->admin, $this->device);

    expect($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($share->token_hash)->toBe(hash('sha256', $token))
        ->and($share->max_viewers)->toBe(1)
        ->and((int) round(now()->diffInMinutes($share->expires_at)))->toBe(60)
        ->and(ConsoleAudit::where('action', 'device.share.create')->count())->toBe(1);
});

it('needs write access to the device to create a link', function () {
    $role = Role::create(['name' => 'Device viewer', 'permissions' => Role::normalizePermissions(['device' => 'r'])]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $this->device->forceFill(['user_id' => $viewer->id])->save();

    Livewire::actingAs($viewer)->test(DeviceShares::class, ['deviceId' => $this->device->id])
        ->call('startCreate')
        ->assertForbidden();
    expect(DeviceShare::count())->toBe(0);
});

it('opens a link with a ticket bound to the shared device', function () {
    [$share, $token] = makeShare($this->admin, $this->device);

    $res = startShare($token)->assertOk()->assertJsonPath('client.peerId', '123456789');
    $ticket = boundTicket($res->json('client.signalToken'));

    expect($ticket['prefix'])->toBe('cdw2')
        ->and($ticket['target'])->toBe('123456789')
        ->and($ticket['subject'])->toBe('share-'.$share->id)
        // Minutes, not the link's hour.
        ->and((int) $ticket['exp'])->toBeLessThanOrEqual(time() + ServerLink::SHARE_TICKET_TTL_SECONDS)
        ->and(ConsoleAudit::where('action', 'device.share.open')->count())->toBe(1)
        ->and(DeviceShareSession::count())->toBe(1);
});

it('never lets a ticket outlive its link', function () {
    [$share] = makeShare($this->admin, $this->device, '15m');
    $share->forceFill(['expires_at' => now()->addSeconds(60)])->save();

    expect((int) boundTicket(ServerLink::shareTicket($share->fresh()))['exp'])->toBe($share->expires_at->getTimestamp());
});

it('gives a share viewer the STUN servers but not the TURN credentials', function () {
    Setting::put(IceServers::SETTING_WEB_CLIENT_SERVERS, 'stun:stun.example.com:3478,turn://user:pass@turn.example.com:3478');
    [, $token] = makeShare($this->admin, $this->device);

    startShare($token)->assertOk()->assertJsonPath('client.webrtc.iceServers', 'stun:stun.example.com:3478');
});

it('refuses unknown, expired and ended links', function () {
    startShare(str_repeat('a', 64))->assertNotFound()->assertJsonPath('reason', 'link_invalid');
    startShare('not-a-token')->assertNotFound();

    [$share, $token] = makeShare($this->admin, $this->device);
    $share->forceFill(['expires_at' => now()->subMinute()])->save();
    startShare($token)->assertNotFound();

    [$share, $token] = makeShare($this->admin, $this->device);
    Livewire::actingAs($this->admin)->test(DeviceShares::class, ['deviceId' => $this->device->id])->call('revoke', $share->id);
    startShare($token)->assertNotFound();
    expect(ConsoleAudit::where('action', 'device.share.revoke')->count())->toBe(1);
});

it('stops working when its creator loses access', function () {
    [, $token] = makeShare($this->admin, $this->device);
    $this->admin->forceFill(['is_active' => false])->save();

    startShare($token)->assertNotFound();
});

it('holds the viewer limit, and a lapsed viewer does not come back over it', function () {
    [, $token] = makeShare($this->admin, $this->device);

    $first = startShare($token)->assertOk()->json('session');
    startShare($token)->assertStatus(409)->assertJsonPath('reason', 'link_full');

    // The first page stops pinging; its slot lapses and someone else takes it.
    Carbon::setTestNow(now()->addSeconds(DeviceShareSession::LIVE_SECONDS + 5));
    $second = startShare($token)->assertOk()->json('session');

    pingShare($token, $first)->assertStatus(410)->assertJsonPath('reason', 'link_full');
    pingShare($token, $second)->assertOk();
    Carbon::setTestNow();
});

it('lets a lapsed viewer back in when there is room', function () {
    [, $token] = makeShare($this->admin, $this->device);
    $session = startShare($token)->json('session');

    Carbon::setTestNow(now()->addSeconds(DeviceShareSession::LIVE_SECONDS + 5));
    pingShare($token, $session)->assertOk();
    expect(DeviceShareSession::query()->live()->count())->toBe(1);
    Carbon::setTestNow();
});

it('hands out a fresh ticket on every ping and 410 once the link ends', function () {
    [$share, $token] = makeShare($this->admin, $this->device);
    $session = startShare($token)->json('session');

    Carbon::setTestNow(now()->addSeconds(40));
    $ticket = boundTicket(pingShare($token, $session)->assertOk()->json('signal_token'));
    expect((int) $ticket['exp'])->toBe(time() + ServerLink::SHARE_TICKET_TTL_SECONDS)
        ->and($ticket['target'])->toBe('123456789');
    Carbon::setTestNow();

    Livewire::actingAs($this->admin)->test(DeviceShares::class, ['deviceId' => $this->device->id])->call('revoke', $share->id);
    pingShare($token, $session)->assertStatus(410);
    // A session key does not work with another link's token.
    pingShare(str_repeat('b', 64), $session)->assertStatus(410);
});

it('frees the slot when the page closes', function () {
    [, $token] = makeShare($this->admin, $this->device);
    $session = startShare($token)->json('session');

    $this->postJson('/s/end', ['token' => $token, 'session' => $session])->assertOk();
    startShare($token)->assertOk();
});

it('is off entirely when share links are disabled', function () {
    [, $token] = makeShare($this->admin, $this->device);
    config(['cortendesk.share_links' => false]);

    $this->get('/s')->assertNotFound();
    startShare($token)->assertNotFound();
});

it('serves the share page without caching or framing', function () {
    $this->get('/s')->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('/rdclient/app.js', false);
});

it('keeps the expiry when the share row is saved again', function () {
    [$share] = makeShare($this->admin, $this->device);
    $expires = $share->expires_at->getTimestamp();

    Carbon::setTestNow(now()->addMinutes(5));
    $share->forceFill(['last_used_at' => now()])->save();
    Carbon::setTestNow();

    expect($share->fresh()->expires_at->getTimestamp())->toBe($expires);
});

it('warns when the device strategy allows password logins only', function () {
    $warning = __('shares.password_only', ['option' => __('labels.strategy_opt_approve_mode')]);
    $card = fn () => Livewire::actingAs($this->admin)->test(DeviceShares::class, ['deviceId' => $this->device->id]);
    $card()->assertDontSee($warning);

    $strategy = Strategy::create(['name' => 'Locked', 'enabled' => true, 'options' => ['approve-mode' => 'password']]);
    $this->device->forceFill(['strategy_id_resolved' => $strategy->id])->saveQuietly();
    $card()->assertSee($warning);

    // What the device last confirmed wins over what it was told.
    $this->device->forceFill(['strategy_acked_options' => ['approve-mode' => 'password-click']])->saveQuietly();
    $card()->assertDontSee($warning);
});
