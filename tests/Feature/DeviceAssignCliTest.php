<?php

/**
 * RustDesk client `--assign` support (PLAN B2) — POST /api/devices/cli.
 *
 * Contract: docs/assign-protocol.md. Auth: B1 API token (Device rw; AB fields
 * need address_book rw). Device matched/upserted by body id+uuid. Success = an
 * empty 200 body; errors return a non-empty body.
 */

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

function assignToken(array $perms = ['device' => 'rw']): string
{
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'assign-'.uniqid(), $perms);

    return $plain;
}

function assignHeaders(string $plain): array
{
    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

function existingDevice(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => (string) (900000000 + $n),
        'uuid' => 'uuid-assign-'.$n,
        'hostname' => 'host-'.$n,
        'os' => 'Windows 10',
        'username' => 'reported-user',
        'version' => '1.4.2',
    ], $attrs));
}

// ------------------------------------------------------------------ auth ---

it('rejects an --assign with no token', function () {
    $this->postJson('/api/devices/cli', ['id' => '1', 'uuid' => 'u', 'user_name' => 'x'])
        ->assertUnauthorized();
});

it('rejects an --assign with an unknown token', function () {
    $this->postJson('/api/devices/cli', ['id' => '1', 'uuid' => 'u', 'user_name' => 'x'], [
        'Authorization' => 'Bearer cdk_nope', 'Accept' => 'application/json',
    ])->assertUnauthorized();
});

it('rejects a token without Device rw (read-only device token)', function () {
    $token = assignToken(['device' => 'r']);
    $device = existingDevice();
    $user = User::factory()->create(['username' => 'alice']);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'user_name' => 'alice',
    ], assignHeaders($token))->assertForbidden();

    expect($device->fresh()->user_id)->toBeNull();
});

// ---------------------------------------------------------------- params ---

it('assigns a device to a user by user_name', function () {
    $token = assignToken();
    $device = existingDevice();
    $user = User::factory()->create(['username' => 'alice']);

    $res = $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'user_name' => 'alice',
    ], assignHeaders($token));

    $res->assertOk();
    expect($res->getContent())->toBe('');            // empty body = success
    expect($device->fresh()->user_id)->toBe($user->id);
});

it('returns 404 for an unknown user_name and mutates nothing', function () {
    $token = assignToken();
    $device = existingDevice();

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'user_name' => 'ghost',
    ], assignHeaders($token))->assertNotFound();

    expect($device->fresh()->user_id)->toBeNull();
});

it('assigns a device to a device group by device_group_name', function () {
    $token = assignToken();
    $device = existingDevice();
    $group = DeviceGroup::create(['name' => 'Warehouse']);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'device_group_name' => 'Warehouse',
    ], assignHeaders($token))->assertOk();

    expect($device->fresh()->device_group_id)->toBe($group->id);
});

it('returns 404 for an unknown device_group_name', function () {
    $token = assignToken();
    $device = existingDevice();

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'device_group_name' => 'Nope',
    ], assignHeaders($token))->assertNotFound();
});

it('sets note, device_name and device_username overrides', function () {
    $token = assignToken();
    $device = existingDevice();

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'note' => 'Front desk PC',
        'device_name' => 'FRONTDESK',
        'device_username' => 'kiosk',
    ], assignHeaders($token))->assertOk();

    $fresh = $device->fresh();
    expect($fresh->note)->toBe('Front desk PC')
        ->and($fresh->hostname)->toBe('FRONTDESK')
        ->and($fresh->username)->toBe('kiosk');
});

// Was "accepts and ignores strategy_name" until PLAN C2 gave strategies a
// model: the flag now creates a real device-level assignment, and an unknown
// name is rejected like every other named lookup here (user_name,
// device_group_name, address_book_name) instead of being silently dropped.
it('applies strategy_name as a device-level assignment', function () {
    $token = assignToken(['device' => 'rw', 'strategy' => 'rw']);
    $device = existingDevice();
    $policy = Strategy::create(['name' => 'Locked down', 'options' => ['enable-audio' => 'N']]);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'strategy_name' => 'Locked down',
    ], assignHeaders($token))->assertOk();

    expect($device->fresh()->strategy_id_resolved)->toBe($policy->id);
});

it('rejects an unknown strategy_name before mutating anything', function () {
    $token = assignToken(['device' => 'rw', 'strategy' => 'rw']);
    $device = existingDevice(['note' => 'untouched']);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid,
        'strategy_name' => 'Does not exist', 'note' => 'changed',
    ], assignHeaders($token))->assertNotFound();

    expect($device->fresh()->note)->toBe('untouched');
});

// Pushing a policy is a strategy write, and a device-level one at that — the
// highest-precedence level there is. `device: rw` alone must not buy it: no
// other route reads the strategy permission, so this check is the whole of it.
it('rejects strategy_name when the token lacks strategy rw', function () {
    $token = assignToken(['device' => 'rw', 'strategy' => 'none']);
    $device = existingDevice();
    Strategy::create(['name' => 'Wide open', 'options' => ['access-mode' => 'full']]);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'strategy_name' => 'Wide open',
    ], assignHeaders($token))->assertForbidden();

    expect($device->fresh()->assignedStrategyId())->toBeNull();
});

it('refuses a read-only strategy token too, and leaves the device alone', function () {
    $token = assignToken(['device' => 'rw', 'strategy' => 'r']);
    $device = existingDevice(['note' => 'untouched']);
    Strategy::create(['name' => 'Read only please', 'options' => ['enable-audio' => 'N']]);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid,
        'strategy_name' => 'Read only please', 'note' => 'changed',
    ], assignHeaders($token))->assertForbidden();

    expect($device->fresh()->note)->toBe('untouched');
});

it('combines user, group and note in one call', function () {
    $token = assignToken();
    $device = existingDevice();
    $user = User::factory()->create(['username' => 'bob']);
    $group = DeviceGroup::create(['name' => 'Floor 2']);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'user_name' => 'bob',
        'device_group_name' => 'Floor 2',
        'note' => 'combined',
    ], assignHeaders($token))->assertOk();

    $fresh = $device->fresh();
    expect($fresh->user_id)->toBe($user->id)
        ->and($fresh->device_group_id)->toBe($group->id)
        ->and($fresh->note)->toBe('combined');
});

// ------------------------------------------------------------- upsert ---

it('registers a new device when id+uuid are unknown (upsert)', function () {
    $token = assignToken();
    $user = User::factory()->create(['username' => 'carol']);

    $this->postJson('/api/devices/cli', [
        'id' => '999888777', 'uuid' => 'brand-new-uuid', 'user_name' => 'carol',
    ], assignHeaders($token))->assertOk();

    $device = Device::where('rustdesk_id', '999888777')->where('uuid', 'brand-new-uuid')->first();
    expect($device)->not->toBeNull()
        ->and($device->user_id)->toBe($user->id);
});

it('matches an existing device by rustdesk_id and refreshes its uuid', function () {
    $token = assignToken();
    $device = existingDevice(['rustdesk_id' => '111222333', 'uuid' => 'old-uuid']);
    $user = User::factory()->create(['username' => 'dave']);

    // Same id, a rotated uuid (e.g. OS reinstall) → same device, not a dup.
    $this->postJson('/api/devices/cli', [
        'id' => '111222333', 'uuid' => 'new-uuid', 'user_name' => 'dave',
    ], assignHeaders($token))->assertOk();

    $fresh = $device->fresh();
    expect($fresh->user_id)->toBe($user->id)
        ->and($fresh->uuid)->toBe('new-uuid');
    expect(Device::where('rustdesk_id', '111222333')->count())->toBe(1);
});

// ------------------------------------------------------- address book ---

it('adds the device to an address book (device + address_book rw)', function () {
    $token = assignToken(['device' => 'rw', 'address_book' => 'rw']);
    $device = existingDevice();
    $owner = User::factory()->create();
    $book = AddressBook::create(['name' => 'Shared AB', 'owner_user_id' => $owner->id]);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'address_book_name' => 'Shared AB',
        'address_book_alias' => 'Kiosk 1',
        'address_book_password' => 's3cret',
        'address_book_tag' => 'lobby',
        'address_book_note' => 'Lobby kiosk',
    ], assignHeaders($token))->assertOk();

    $entry = AddressBookEntry::where('address_book_id', $book->id)
        ->where('rustdesk_id', $device->rustdesk_id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->alias)->toBe('Kiosk 1')
        ->and($entry->note)->toBe('Lobby kiosk')
        ->and(Crypt::decryptString($entry->password_enc))->toBe('s3cret');

    $tag = Tag::where('address_book_id', $book->id)->where('name', 'lobby')->first();
    expect($tag)->not->toBeNull()
        ->and($entry->tag_ids)->toContain($tag->id);
});

it('rejects address_book_* fields when the token lacks address_book rw', function () {
    $token = assignToken(['device' => 'rw', 'address_book' => 'r']);
    $device = existingDevice();
    $owner = User::factory()->create();
    $book = AddressBook::create(['name' => 'RO AB', 'owner_user_id' => $owner->id]);

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'address_book_name' => 'RO AB',
        'address_book_alias' => 'nope',
    ], assignHeaders($token))->assertForbidden();

    expect(AddressBookEntry::where('address_book_id', $book->id)->count())->toBe(0);
});

it('returns 404 for an unknown address_book_name', function () {
    $token = assignToken(['device' => 'rw', 'address_book' => 'rw']);
    $device = existingDevice();

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid, 'address_book_name' => 'Missing',
    ], assignHeaders($token))->assertNotFound();
});

// ------------------------------------------------------------- guards ---

it('rejects a request missing id/uuid', function () {
    $token = assignToken();

    $this->postJson('/api/devices/cli', ['user_name' => 'x'], assignHeaders($token))
        ->assertStatus(400);
});

it('rejects a request with no assignment parameters', function () {
    $token = assignToken();
    $device = existingDevice();

    $this->postJson('/api/devices/cli', [
        'id' => $device->rustdesk_id, 'uuid' => $device->uuid,
    ], assignHeaders($token))->assertStatus(400);
});
