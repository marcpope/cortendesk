<?php

/**
 * Admin automation REST API (PLAN B1): guard, permission matrix, and one happy
 * + one denied path per endpoint group. Envelope: {code,data,message}.
 */

use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserGroup;

/** Create a token with the given permission matrix; return the plaintext. */
function apiTokenPlain(array $perms = [], ?User $creator = null): string
{
    $creator ??= User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'test-'.uniqid(), $perms);

    return $plain;
}

function apiHeaders(string $plain): array
{
    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

function apiMakeDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => (string) (100000000 + $n),
        'uuid' => 'uuid-'.$n,
        'hostname' => 'host-'.$n,
        'os' => 'Windows 10',
        'username' => 'u',
        'version' => '1.4.2',
    ], $attrs));
}

// ------------------------------------------------------------------ guard ---

it('rejects admin-api requests with no bearer token', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

it('rejects an unknown bearer token', function () {
    $this->getJson('/api/v1/users', ['Authorization' => 'Bearer cdk_nope'])
        ->assertUnauthorized();
});

it('rejects a revoked (inactive) token', function () {
    $creator = User::factory()->admin()->create();
    [$token, $plain] = ApiToken::issue($creator, 'revoked', ['user' => 'r']);
    $token->update(['is_active' => false]);

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertUnauthorized();
});

it('rejects an expired token', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'exp', ['user' => 'r'], now()->subDay());

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertUnauthorized();
});

it('rejects a token whose creator is disabled', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'orphan', ['user' => 'r']);
    $creator->update(['is_active' => false]);

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertUnauthorized();
});

it('stamps last_used_at on a successful authenticated call', function () {
    $creator = User::factory()->admin()->create();
    [$token, $plain] = ApiToken::issue($creator, 'used', ['user' => 'r']);
    expect($token->last_used_at)->toBeNull();

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertOk();

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

// ----------------------------------------------------- permission matrix ---

it('ranks permission levels so rw covers r but r does not cover rw', function () {
    $creator = User::factory()->admin()->create();
    [$rw] = ApiToken::issue($creator, 'rw', ['device' => 'rw']);
    [$r] = ApiToken::issue($creator, 'r', ['device' => 'r']);
    [$none] = ApiToken::issue($creator, 'none', ['device' => 'none']);

    expect($rw->allows('device', 'r'))->toBeTrue()
        ->and($rw->allows('device', 'rw'))->toBeTrue()
        ->and($r->allows('device', 'r'))->toBeTrue()
        ->and($r->allows('device', 'rw'))->toBeFalse()
        ->and($none->allows('device', 'r'))->toBeFalse();
});

it('normalizes an unknown or partial permission map to none defaults', function () {
    $perms = ApiToken::normalizePermissions(['device' => 'rw', 'bogus' => 'rw']);

    expect($perms)->toHaveKeys(ApiToken::RESOURCES)
        ->and($perms['device'])->toBe('rw')
        ->and($perms['user'])->toBe('none')
        ->and($perms)->not->toHaveKey('bogus');
});

it('read token can GET but a write is forbidden with a 403 envelope', function () {
    $plain = apiTokenPlain(['user' => 'r']);

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertOk();

    $this->postJson('/api/v1/users', [
        'username' => 'nope', 'password' => 'password123',
    ], apiHeaders($plain))
        ->assertForbidden()
        ->assertJsonPath('code', 403);
});

it('a token with none on a resource cannot even read it', function () {
    $plain = apiTokenPlain(['device' => 'rw']); // user = none

    $this->getJson('/api/v1/users', apiHeaders($plain))->assertForbidden();
});

// -------------------------------------------------------------- users -------

it('lists and creates users (happy) and denies create without rw', function () {
    User::factory()->create(['username' => 'findme']);
    $rw = apiTokenPlain(['user' => 'rw']);

    $this->getJson('/api/v1/users?name=findme', apiHeaders($rw))
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('data.0.username', 'findme');

    $this->postJson('/api/v1/users', [
        'username' => 'api-created', 'password' => 'password123', 'is_admin' => false,
    ], apiHeaders($rw))
        ->assertCreated()
        ->assertJsonPath('data.username', 'api-created');

    expect(User::where('username', 'api-created')->exists())->toBeTrue();

    // denied: read-only token
    $r = apiTokenPlain(['user' => 'r']);
    $this->postJson('/api/v1/users', [
        'username' => 'blocked', 'password' => 'password123',
    ], apiHeaders($r))->assertForbidden();
});

it('enables, disables, force-logs-out and deletes users', function () {
    $rw = apiTokenPlain(['user' => 'rw']);
    $user = User::factory()->create(['is_active' => true]);

    $this->postJson("/api/v1/users/{$user->id}/disable", [], apiHeaders($rw))->assertOk();
    expect($user->fresh()->is_active)->toBeFalse();

    $this->postJson("/api/v1/users/{$user->id}/enable", [], apiHeaders($rw))->assertOk();
    expect($user->fresh()->is_active)->toBeTrue();

    $this->postJson("/api/v1/users/{$user->id}/force-logout", [], apiHeaders($rw))->assertOk();

    $this->deleteJson("/api/v1/users/{$user->id}", [], apiHeaders($rw))->assertOk();
    expect(User::find($user->id))->toBeNull();
});

/*
 * Force-logout and disable must mean the same thing scripted as they do clicked
 * (PLAN A3 + D1). A trusted browser skips the emailed sign-in code, so leaving
 * that row behind lets a stolen laptop sign straight back in — the console path
 * deletes it, and the API path has to as well.
 */
it('drops trusted browsers on force-logout and on disable, like the console does', function () {
    $rw = apiTokenPlain(['user' => 'rw']);

    $user = User::factory()->create(['is_active' => true]);
    TrustedDevice::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'stolen-laptop'),
        'label' => 'Chrome on the stolen laptop',
        'expires_at' => now()->addDays(30),
    ]);

    $this->postJson("/api/v1/users/{$user->id}/force-logout", [], apiHeaders($rw))->assertOk();

    expect(TrustedDevice::where('user_id', $user->id)->count())->toBe(0);

    // Same for disable, which revokes everything as a side effect.
    $other = User::factory()->create(['is_active' => true]);
    TrustedDevice::create([
        'user_id' => $other->id,
        'token_hash' => hash('sha256', 'another-browser'),
        'label' => 'Firefox',
        'expires_at' => now()->addDays(30),
    ]);

    $this->postJson("/api/v1/users/{$other->id}/disable", [], apiHeaders($rw))->assertOk();

    expect(TrustedDevice::where('user_id', $other->id)->count())->toBe(0);
});

// ------------------------------------------------------------ devices -------

it('lists, views and denies device writes without rw', function () {
    $device = apiMakeDevice(['alias' => 'reception-pc']);
    $r = apiTokenPlain(['device' => 'r']);

    $this->getJson('/api/v1/devices?name=reception', apiHeaders($r))
        ->assertOk()
        ->assertJsonPath('data.0.alias', 'reception-pc');

    $this->getJson("/api/v1/devices/{$device->id}", apiHeaders($r))
        ->assertOk()
        ->assertJsonPath('data.rustdesk_id', $device->rustdesk_id);

    // denied: read token cannot disable
    $this->postJson("/api/v1/devices/{$device->id}/disable", [], apiHeaders($r))
        ->assertForbidden();
});

it('carries the full device inventory in API payloads (issue #16)', function () {
    apiMakeDevice([
        'alias' => 'inventory-pc',
        'cpu' => 'AMD Ryzen 7 5800X, 8 cores',
        'memory' => '32 GB',
        'last_online_ip' => '198.51.100.7',
    ]);
    $r = apiTokenPlain(['device' => 'r']);

    $this->getJson('/api/v1/devices?name=inventory', apiHeaders($r))
        ->assertOk()
        ->assertJsonPath('data.0.cpu', 'AMD Ryzen 7 5800X, 8 cores')
        ->assertJsonPath('data.0.memory', '32 GB')
        ->assertJsonPath('data.0.last_online_ip', '198.51.100.7')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonStructure(['data' => [0 => ['created_at', 'updated_at', 'note']]]);
});

it('assigns a device to a user and a device group', function () {
    $rw = apiTokenPlain(['device' => 'rw']);
    $device = apiMakeDevice();
    $owner = User::factory()->create(['username' => 'owner-x']);
    $group = DeviceGroup::create(['name' => 'Warehouse']);

    $this->postJson("/api/v1/devices/{$device->id}/assign", [
        'user_name' => 'owner-x', 'device_group_name' => 'Warehouse',
    ], apiHeaders($rw))->assertOk();

    $device->refresh();
    expect($device->user_id)->toBe($owner->id)
        ->and($device->device_group_id)->toBe($group->id);
});

it('disables (soft-deletes) then re-enables a device', function () {
    $rw = apiTokenPlain(['device' => 'rw']);
    $device = apiMakeDevice();

    $this->postJson("/api/v1/devices/{$device->id}/disable", [], apiHeaders($rw))->assertOk();
    expect(Device::find($device->id))->toBeNull()
        ->and(Device::withTrashed()->find($device->id)->trashed())->toBeTrue();

    $this->postJson("/api/v1/devices/{$device->id}/enable", [], apiHeaders($rw))->assertOk();
    expect(Device::find($device->id))->not->toBeNull();
});

// -------------------------------------------------------- user groups -------

it('creates a user group and manages membership (happy) and denies without rw', function () {
    $rw = apiTokenPlain(['group' => 'rw']);
    $user = User::factory()->create();

    $id = $this->postJson('/api/v1/user-groups', ['name' => 'Support'], apiHeaders($rw))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/user-groups/{$id}/members", ['user_id' => $user->id], apiHeaders($rw))
        ->assertOk();
    expect(UserGroup::find($id)->users()->count())->toBe(1);

    $this->deleteJson("/api/v1/user-groups/{$id}/members", ['user_id' => $user->id], apiHeaders($rw))
        ->assertOk();
    expect(UserGroup::find($id)->users()->count())->toBe(0);

    // denied
    $r = apiTokenPlain(['group' => 'r']);
    $this->postJson('/api/v1/user-groups', ['name' => 'Nope'], apiHeaders($r))->assertForbidden();
});

// ------------------------------------------------------ device groups -------

it('creates a device group and adds a device member (happy) and denies without rw', function () {
    $rw = apiTokenPlain(['group' => 'rw']);
    $device = apiMakeDevice();

    $id = $this->postJson('/api/v1/device-groups', ['name' => 'Kiosks'], apiHeaders($rw))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/device-groups/{$id}/members", ['device_id' => $device->id], apiHeaders($rw))
        ->assertOk();
    expect($device->fresh()->device_group_id)->toBe($id);

    // read token denied
    $r = apiTokenPlain(['group' => 'r']);
    $this->getJson('/api/v1/device-groups', apiHeaders($r))->assertOk();
    $this->postJson('/api/v1/device-groups', ['name' => 'X'], apiHeaders($r))->assertForbidden();
});

// ------------------------------------------------------ address books -------

it('CRUDs address books, peers, tags and rules (happy) and denies without rw', function () {
    $rw = apiTokenPlain(['address_book' => 'rw']);

    $abId = $this->postJson('/api/v1/address-books', ['name' => 'Shared AB'], apiHeaders($rw))
        ->assertCreated()->json('data.id');

    // peer
    $this->postJson("/api/v1/address-books/{$abId}/peers", [
        'rustdesk_id' => '900900900', 'alias' => 'server1', 'note' => 'Rack 3',
    ], apiHeaders($rw))->assertCreated()->assertJsonPath('data.note', 'Rack 3');
    expect(AddressBookEntry::where('address_book_id', $abId)->count())->toBe(1);

    // tag
    $this->postJson("/api/v1/address-books/{$abId}/tags", [
        'name' => 'prod', 'color' => '#ff0000',
    ], apiHeaders($rw))->assertCreated()->assertJsonPath('data.color', '#FF0000');
    expect(Tag::where('address_book_id', $abId)->count())->toBe(1)
        // stored as the client's u32 ARGB, not the string (500 on MySQL)
        ->and((int) Tag::where('address_book_id', $abId)->value('color'))->toBe(0xFFFF0000);
    $this->getJson("/api/v1/address-books/{$abId}/tags", apiHeaders($rw))
        ->assertOk()->assertJsonPath('data.0.color', '#FF0000');
    $this->postJson("/api/v1/address-books/{$abId}/tags", ['name' => 'bad', 'color' => 'red'], apiHeaders($rw))
        ->assertUnprocessable();

    // rule
    $this->postJson("/api/v1/address-books/{$abId}/rules", [
        'subject_type' => 'everyone', 'permission' => AddressBookRule::PERM_READ,
    ], apiHeaders($rw))->assertCreated();
    expect(AddressBookRule::where('address_book_id', $abId)->count())->toBe(1);

    $this->getJson("/api/v1/address-books/{$abId}/peers", apiHeaders($rw))
        ->assertOk()->assertJsonPath('data.0.alias', 'server1');

    // denied: read token cannot add a peer
    $r = apiTokenPlain(['address_book' => 'r']);
    $this->getJson('/api/v1/address-books', apiHeaders($r))->assertOk();
    $this->postJson("/api/v1/address-books/{$abId}/peers", [
        'rustdesk_id' => '111',
    ], apiHeaders($r))->assertForbidden();
});

// ------------------------------------------------------------- audits -------

it('queries audit logs with the audit permission and denies without it', function () {
    AuditConnection::create([
        'action' => 'new', 'rustdesk_id' => '555', 'from_peer' => '777', 'ip' => '1.2.3.4',
    ]);
    $r = apiTokenPlain(['audit' => 'r']);

    $this->getJson('/api/v1/audits/conn', apiHeaders($r))
        ->assertOk()
        ->assertJsonPath('data.0.rustdesk_id', '555');

    $this->getJson('/api/v1/audits/console', apiHeaders($r))->assertOk();

    // denied: token without audit permission
    $noAudit = apiTokenPlain(['device' => 'rw']);
    $this->getJson('/api/v1/audits/conn', apiHeaders($noAudit))->assertForbidden();
});

// --- security regressions (workflow security-review findings #2, #3) ---------

it('rejects an admin-API token whose owner is no longer an admin', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'demote-me', ['user' => 'rw']);

    // Works while the owner is an admin.
    $this->withHeaders(apiHeaders($plain))->getJson('/api/v1/users')->assertOk();

    // Demote the owner -> the token dies with the privilege.
    $creator->update(['is_admin' => false]);
    $this->withHeaders(apiHeaders($plain))->getJson('/api/v1/users')->assertUnauthorized();
});

it('rejects a token whose owner has been disabled', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'disable-me', ['user' => 'rw']);
    $creator->update(['is_active' => false]);

    $this->withHeaders(apiHeaders($plain))->getJson('/api/v1/users')->assertUnauthorized();
});

it('cannot create a console admin through the users API', function () {
    $plain = apiTokenPlain(['user' => 'rw']);

    $this->withHeaders(apiHeaders($plain))
        ->postJson('/api/v1/users', [
            'username' => 'sneaky-admin',
            'password' => 'password123',
            'is_admin' => true,          // must be ignored
            'is_active' => true,
        ])
        ->assertStatus(201);

    expect(User::where('username', 'sneaky-admin')->first()->is_admin)->toBeFalse();
});
