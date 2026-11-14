<?php

use App\Livewire\ActiveSessions;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Operator-requested session termination (#9)
|--------------------------------------------------------------------------
| A live session runs peer-to-peer through hbbs/hbbr and never touches this
| application, so the console cannot close one directly. The only channel is
| the heartbeat response's `disconnect` array (docs/client-api.md §8), which
| means termination is asynchronous by nature — the request is recorded, the
| device acts on its next heartbeat.
*/

function liveSession(array $attrs = []): AuditConnection
{
    return AuditConnection::create(array_merge([
        'action' => 'new',
        'conn_id' => 42,
        'rustdesk_id' => '900000001',
        'from_peer' => '900000002',
        'from_name' => 'operator',
        'conn_type' => 0,
    ], $attrs));
}

it('records a disconnect request from the sessions list', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $session = liveSession();

    Livewire::actingAs($admin)->test(ActiveSessions::class)
        ->call('disconnect', $session->id);

    expect($session->fresh()->disconnect_requested_at)->not->toBeNull();
});

it('hands the connection id to the device on its next heartbeat', function () {
    Device::create(['rustdesk_id' => '900000001', 'uuid' => 'dGVzdA==', 'hostname' => 'HOST-1']);
    $session = liveSession();
    $session->forceFill(['disconnect_requested_at' => now()])->save();

    $this->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'dGVzdA=='])
        ->assertOk()
        ->assertJsonPath('disconnect', [42]);
});

it('does not repeat the instruction on every heartbeat', function () {
    Device::create(['rustdesk_id' => '900000001', 'uuid' => 'dGVzdA==', 'hostname' => 'HOST-1']);
    $session = liveSession();
    $session->forceFill(['disconnect_requested_at' => now()])->save();

    $this->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'dGVzdA=='])
        ->assertJsonPath('disconnect', [42]);

    // A 15s heartbeat must not re-issue it while the client is acting.
    $this->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'dGVzdA=='])
        ->assertJsonMissingPath('disconnect');
});

it('retries when the heartbeat that carried it was lost', function () {
    Device::create(['rustdesk_id' => '900000001', 'uuid' => 'dGVzdA==', 'hostname' => 'HOST-1']);
    $session = liveSession();
    $session->forceFill([
        'disconnect_requested_at' => now()->subMinutes(5),
        'disconnect_sent_at' => now()->subSeconds(AuditConnection::DISCONNECT_RETRY_SECONDS + 5),
    ])->save();

    $this->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'dGVzdA=='])
        ->assertJsonPath('disconnect', [42]);
});

it('stops asking once the session is closed', function () {
    Device::create(['rustdesk_id' => '900000001', 'uuid' => 'dGVzdA==', 'hostname' => 'HOST-1']);
    $session = liveSession();
    $session->forceFill(['disconnect_requested_at' => now(), 'closed_at' => now()])->save();

    $this->postJson('/api/heartbeat', ['id' => '900000001', 'uuid' => 'dGVzdA=='])
        ->assertJsonMissingPath('disconnect');
});

it('leaves the heartbeat untouched for a device with nothing to close', function () {
    // The response has to stay byte-identical for the whole fleet, since every
    // device in the field speaks this endpoint.
    Device::create(['rustdesk_id' => '900000009', 'uuid' => 'dGVzdA==', 'hostname' => 'HOST-9']);

    $this->postJson('/api/heartbeat', ['id' => '900000009', 'uuid' => 'dGVzdA=='])
        ->assertOk()
        ->assertExactJson([]);
});

it('refuses a disconnect from someone without device write access', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $session = liveSession();

    Livewire::actingAs($user)->test(ActiveSessions::class)
        ->call('disconnect', $session->id);

    expect($session->fresh()->disconnect_requested_at)->toBeNull();
});

it('refuses a disconnect on a device outside the operator scope', function () {
    // Non-admin with device rw, but the session is on a device they cannot see.
    $user = User::factory()->create(['is_admin' => false]);
    $session = liveSession(['rustdesk_id' => '900000777']);

    Livewire::actingAs($user)->test(ActiveSessions::class)
        ->call('disconnect', $session->id);

    expect($session->fresh()->disconnect_requested_at)->toBeNull();
});
