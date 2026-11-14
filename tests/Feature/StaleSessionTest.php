<?php

use App\Models\AuditConnection;
use App\Models\Device;

/*
|--------------------------------------------------------------------------
| Stale Active sessions (#10)
|--------------------------------------------------------------------------
| A session used to be closed by exactly one thing: the client posting
| action=close on the audit endpoint. Every end that skipped that post — the
| remote machine rebooting, the network dropping, the service being stopped —
| left the row open and the console reported it as Active forever, accumulating
| one dead row per interrupted session.
|
| Two mechanisms replace that single path, and the split matters: the heartbeat
| covers connections that died while the device kept running, the sweep covers
| devices that stopped talking at all. Neither one alone closes the report.
*/

function staleDevice(array $attrs = []): Device
{
    return Device::create(array_merge([
        'rustdesk_id' => '900000001',
        'uuid' => 'dGVzdA==',
        'hostname' => 'HOST-1',
        'last_online_at' => now(),
    ], $attrs));
}

function openSession(array $attrs = []): AuditConnection
{
    $session = AuditConnection::create(array_merge([
        'action' => 'new',
        'conn_id' => 42,
        'rustdesk_id' => '900000001',
        'from_peer' => '900000002',
        'from_name' => 'operator',
        'conn_type' => 0,
    ], $attrs));

    // Past the reconciliation grace window unless a test says otherwise.
    $session->forceFill(['created_at' => now()->subMinutes(10)])->save();

    return $session->fresh();
}

function staleBeat(array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/heartbeat', array_merge([
        'id' => '900000001',
        'uuid' => 'dGVzdA==',
    ], $extra));
}

// ---------------------------------------------------------------- heartbeat

it('closes a session the device no longer reports as live', function () {
    staleDevice();
    $session = openSession();

    // The peer rebooted: the device is still heartbeating and now lists
    // connection 43 only. 42 is gone and no close was ever posted for it.
    staleBeat(['conns' => [43]])->assertOk();

    expect($session->fresh())
        ->closed_at->not->toBeNull()
        ->action->toBe('close');
});

it('treats an absent conns key as no live connections', function () {
    staleDevice();
    $session = openSession();

    // The critical case. The client omits `conns` entirely when empty
    // (docs/client-api.md §8), so this is what a machine with nothing running
    // sends — and reading it as "no information" is what left #10 open.
    staleBeat()->assertOk();

    expect($session->fresh()->closed_at)->not->toBeNull();
});

it('leaves a genuinely live session alone', function () {
    staleDevice();
    $session = openSession();

    staleBeat(['conns' => [42]])->assertOk();

    expect($session->fresh()->closed_at)->toBeNull();
});

it('does not close a session that has only just opened', function () {
    staleDevice();
    $session = openSession();
    $session->forceFill(['created_at' => now()])->save();

    // The audit POST that opens a session and the heartbeat that lists it are
    // separate requests, so a new session can briefly be absent from `conns`.
    // Without the grace window this heartbeat would kill a live session.
    staleBeat()->assertOk();

    expect($session->fresh()->closed_at)->toBeNull();
});

it('does not touch sessions belonging to another device', function () {
    staleDevice();
    $other = openSession(['rustdesk_id' => '900000777']);

    staleBeat()->assertOk();

    expect($other->fresh()->closed_at)->toBeNull();
});

it('ignores conns from a sender whose uuid does not match', function () {
    staleDevice();
    $session = openSession();

    // Same spoof guard as presence: this endpoint is tokenless, so an
    // unverified sender must not be able to close someone else's sessions.
    test()->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'd3Jvbmc='])->assertOk();

    expect($session->fresh()->closed_at)->toBeNull();
});

it('does not re-close an already closed session', function () {
    staleDevice();
    $closedAt = now()->subHour();
    $session = openSession();
    $session->forceFill(['action' => 'close', 'closed_at' => $closedAt])->save();

    staleBeat()->assertOk();

    expect($session->fresh()->closed_at->timestamp)->toBe($closedAt->timestamp);
});

// -------------------------------------------------------------------- sweep

it('closes sessions on a device that has stopped heartbeating', function () {
    staleDevice(['last_online_at' => now()->subMinutes(30)]);
    $session = openSession();

    // Powered off mid-session: no heartbeat will ever arrive to reconcile
    // against, so only the timer can close this.
    expect(AuditConnection::closeStaleSessions())->toBe(1);
    expect($session->fresh()->closed_at)->not->toBeNull();
});

it('leaves sessions on a device that is still heartbeating', function () {
    staleDevice(['last_online_at' => now()]);
    $session = openSession();

    expect(AuditConnection::closeStaleSessions())->toBe(0);
    expect($session->fresh()->closed_at)->toBeNull();
});

it('closes sessions on a device that has never been seen', function () {
    staleDevice(['last_online_at' => null]);
    $session = openSession();

    expect(AuditConnection::closeStaleSessions())->toBe(1);
});

it('runs from the scheduled command', function () {
    staleDevice(['last_online_at' => now()->subMinutes(30)]);
    $session = openSession();

    $this->artisan('cortendesk:close-stale-sessions')->assertSuccessful();

    expect($session->fresh()->closed_at)->not->toBeNull();
});

it('closes sessions whose device row no longer exists', function () {
    // No Device row at all: the device was deleted while a session was open.
    // A sweep written as "find silent devices" has nothing to match here and
    // would leave this row Active forever.
    $orphan = openSession(['rustdesk_id' => '900000999']);

    expect(AuditConnection::closeStaleSessions())->toBe(1);
    expect($orphan->fresh()->closed_at)->not->toBeNull();
});

it('closes sessions on a recycled (soft-deleted) device', function () {
    $device = staleDevice(['last_online_at' => now()]);
    $device->delete();
    $session = openSession();

    // Recycled devices are invisible and stop recording presence, so their
    // sessions must not keep showing as Active.
    expect(AuditConnection::closeStaleSessions())->toBe(1);
    expect($session->fresh()->closed_at)->not->toBeNull();
});
