<?php

/**
 * Contract tests for the RustDesk client API.
 * Oracle: docs/client-api.md §25 "Cross-cutting quirks checklist".
 */

use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Crypt;

function apiUser(array $attrs = []): User
{
    static $n = 0;
    $n++;

    return User::create(array_merge([
        'username' => "client-user-$n",
        'password' => 'secret-password',
        'is_active' => true,
    ], $attrs));
}

function bearerFor(User $user): array
{
    $token = ClientToken::issue($user);

    return ['Authorization' => "Bearer {$token->token}"];
}

// ---------------------------------------------------------------- auth ----

it('answers login-options with an empty provider list (GET and HEAD)', function () {
    $this->getJson('/api/login-options')->assertOk()->assertExactJson([]);
    $this->call('HEAD', '/api/login-options')->assertOk();
});

it('logs in with valid credentials and returns a complete AuthBody', function () {
    apiUser(['username' => 'alice']);

    $response = $this->postJson('/api/login', [
        'username' => 'alice',
        'password' => 'secret-password',
        'id' => '123456789',
        'uuid' => 'b64-uuid',
        'type' => 'account',
        'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'PC'],
    ]);

    // access_token, type and user are REQUIRED on success (spec §3).
    $response->assertOk()
        ->assertJsonStructure(['access_token', 'type', 'tfa_type', 'secret', 'user' => ['name', 'info']])
        ->assertJsonPath('type', 'access_token')
        ->assertJsonPath('user.name', 'alice')
        ->assertJsonPath('user.is_admin', false);

    expect(ClientToken::count())->toBe(1);
});

it('rejects bad credentials with an error key, not an HTTP error (spec §0.4)', function () {
    apiUser(['username' => 'alice']);

    $this->postJson('/api/login', ['username' => 'alice', 'password' => 'wrong'])
        ->assertOk()
        ->assertJsonStructure(['error']);
});

it('rejects disabled users at login', function () {
    apiUser(['username' => 'off', 'is_active' => false]);

    $this->postJson('/api/login', ['username' => 'off', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonStructure(['error']);
});

it('returns the UserPayload at top level from currentUser with an info object', function () {
    $user = apiUser(['username' => 'bob', 'is_admin' => true]);

    $this->postJson('/api/currentUser', ['id' => 'x', 'uuid' => 'y'], bearerFor($user))
        ->assertOk()
        ->assertJsonPath('name', 'bob')
        ->assertJsonPath('is_admin', true)
        ->assertJsonPath('status', 1)
        ->assertJsonPath('info', []);
});

it('rejects missing bearer tokens with 401 (triggers client logout only when real)', function () {
    $this->postJson('/api/currentUser', ['id' => 'x', 'uuid' => 'y'])->assertUnauthorized();
});

it('revokes the token on logout', function () {
    $user = apiUser();
    $headers = bearerFor($user);

    $this->postJson('/api/logout', ['id' => 'x', 'uuid' => 'y'], $headers)->assertOk();
    expect(ClientToken::count())->toBe(0);

    // The request guard memoizes per app instance; reset between simulated requests.
    app('auth')->forgetGuards();

    $this->postJson('/api/currentUser', [], $headers)->assertUnauthorized();
});

// ---------------------------------------------------- heartbeat/sysinfo ----

it('asks unknown devices for sysinfo via the heartbeat response (presence-only key)', function () {
    $this->postJson('/api/heartbeat', ['id' => '999999999', 'uuid' => 'u1', 'ver' => 1004090])
        ->assertOk()
        ->assertJsonStructure(['sysinfo']);
});

it('registers a device from sysinfo with the exact plain-text ack', function () {
    $response = $this->postJson('/api/sysinfo', [
        'id' => '111222333',
        'uuid' => 'u-abc',
        'cpu' => 'Apple M1, 3.2GHz, 8/8 cores',
        'memory' => '16GB',
        'os' => 'macos / macOS 14.5',
        'hostname' => 'mac-host',
        'username' => 'jsmith',
        'version' => '1.4.9',
    ]);

    // Exact string, not JSON (spec §9).
    expect($response->getContent())->toBe('SYSINFO_UPDATED');

    $device = Device::where('rustdesk_id', '111222333')->first();
    expect($device)->not->toBeNull()
        ->and($device->hostname)->toBe('mac-host')
        ->and($device->platform())->toBe('macos');
});

it('updates presence on heartbeat for known devices and stops requesting sysinfo', function () {
    Device::create(['rustdesk_id' => '111', 'uuid' => 'u1', 'hostname' => 'known']);

    $response = $this->postJson('/api/heartbeat', ['id' => '111', 'uuid' => 'u1', 'ver' => 1004090]);

    $response->assertOk();
    expect($response->json())->not->toHaveKey('sysinfo')
        ->and(Device::first()->isOnline())->toBeTrue();
});

it('ignores heartbeats with a mismatched uuid (spoof guard)', function () {
    Device::create(['rustdesk_id' => '111', 'uuid' => 'real-uuid', 'hostname' => 'h']);

    $this->postJson('/api/heartbeat', ['id' => '111', 'uuid' => 'fake-uuid'])->assertOk();

    expect(Device::first()->last_online_at)->toBeNull();
});

it('keeps recycled devices invisible: heartbeat swallowed, sysinfo acked without changes', function () {
    $device = Device::create(['rustdesk_id' => '222', 'uuid' => 'u2', 'hostname' => 'old-name']);
    $device->delete();

    $hb = $this->postJson('/api/heartbeat', ['id' => '222', 'uuid' => 'u2']);
    expect($hb->json())->not->toHaveKey('sysinfo');

    $si = $this->postJson('/api/sysinfo', ['id' => '222', 'uuid' => 'u2', 'hostname' => 'new-name']);
    expect($si->getContent())->toBe('SYSINFO_UPDATED')
        ->and(Device::withTrashed()->first()->hostname)->toBe('old-name');
});

// ------------------------------------------------------------- audits ----

it('records the full connection audit lifecycle: new, authorized (peer tuple), close', function () {
    $this->postJson('/api/audit/conn', [
        'action' => 'new', 'ip' => '1.2.3.4', 'id' => '111', 'uuid' => 'u',
        'conn_id' => 5, 'session_id' => 1234567890,
    ])->assertOk();

    // Authorized posts have NO action key; peer is a [id, name] tuple (spec §21).
    $this->postJson('/api/audit/conn', [
        'peer' => ['999888777', 'Front Desk'], 'type' => 0,
        'id' => '111', 'uuid' => 'u', 'conn_id' => 5, 'session_id' => 1234567890,
    ])->assertOk();

    $this->postJson('/api/audit/conn', [
        'action' => 'close', 'id' => '111', 'uuid' => 'u',
        'conn_id' => 5, 'session_id' => 1234567890,
    ])->assertOk();

    $audit = AuditConnection::sole();
    expect($audit->from_peer)->toBe('999888777')
        ->and($audit->from_name)->toBe('Front Desk')
        ->and($audit->conn_type)->toBe(0)
        ->and($audit->closed_at)->not->toBeNull();
});

it('stores file audits with the JSON-string info field parsed for metadata', function () {
    $this->postJson('/api/audit/file', [
        'id' => '111', 'uuid' => 'u', 'peer_id' => '222', 'conn_id' => 5,
        'type' => 0, 'path' => '/tmp/dir', 'is_file' => false,
        'info' => json_encode(['ip' => '1.2.3.4', 'name' => 'ctrl', 'num' => 3, 'files' => [['a.txt', 12]]]),
    ])->assertOk();

    $row = AuditFileTransfer::sole();
    expect($row->from_name)->toBe('ctrl')->and($row->file_count)->toBe(3);
});

// ------------------------------------------------------- address books ----

it('negotiates new-AB mode: personal probe returns a guid and creates the book', function () {
    $user = apiUser();

    $this->postJson('/api/ab/personal', [], bearerFor($user))
        ->assertOk()
        ->assertJsonStructure(['guid']);

    expect(AddressBook::where('owner_user_id', $user->id)->where('is_personal', true)->exists())->toBeTrue();
});

it('accepts the POST-as-read endpoints with empty bodies (Content-Length: 0)', function () {
    $user = apiUser();
    $headers = bearerFor($user);

    $this->call('POST', '/api/ab/settings', [], [], [], $this->transformHeadersToServerVars($headers))
        ->assertOk()
        ->assertJsonPath('max_peer_one_ab', 0);

    $this->call('POST', '/api/ab/shared/profiles?current=1&pageSize=100', [], [], [], $this->transformHeadersToServerVars($headers))
        ->assertOk()
        ->assertJsonStructure(['total', 'data']);
});

it('signals mutation success with an EMPTY 200 body (spec §25.3)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');

    $add = $this->postJson("/api/ab/tag/add/$guid", ['name' => 'work', 'color' => 4278238420], $headers);
    $add->assertOk();
    expect($add->getContent())->toBe('');

    $peer = $this->postJson("/api/ab/peer/add/$guid", [
        'id' => '123456789', 'alias' => 'office', 'tags' => ['work'], 'hash' => 'h1',
    ], $headers);
    expect($peer->getContent())->toBe('');
});

it('lists tags as a bare array with color always an int (spec §25.14)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');
    $this->postJson("/api/ab/tag/add/$guid", ['name' => 'work', 'color' => 4278238420], $headers);

    $tags = $this->postJson("/api/ab/tags/$guid", [], $headers)->assertOk()->json();

    expect($tags)->toBe([['name' => 'work', 'color' => 4278238420]]);
});

it('serializes peers with forceAlwaysRelay as a STRING (spec §25.15)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');
    $this->postJson("/api/ab/peer/add/$guid", ['id' => '42', 'alias' => 'x', 'hash' => 'h'], $headers);

    $data = $this->postJson("/api/ab/peers?current=1&pageSize=100&ab=$guid", [], $headers)->json('data');

    expect($data[0]['forceAlwaysRelay'])->toBeString()->toBe('false')
        ->and($data[0]['hash'])->toBe('h');
});

it('partially updates peers leaving absent fields unchanged (spec §16)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');
    $this->postJson("/api/ab/peer/add/$guid", ['id' => '42', 'alias' => 'before', 'hash' => 'h'], $headers);

    $this->putJson("/api/ab/peer/update/$guid", ['id' => '42', 'username' => 'osuser'], $headers);

    $data = $this->postJson("/api/ab/peers?ab=$guid", [], $headers)->json('data');
    expect($data[0]['alias'])->toBe('before')->and($data[0]['username'])->toBe('osuser');
});

it('keeps the note the client sets on an entry (#167)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');
    $this->postJson("/api/ab/peer/add/$guid", ['id' => '42', 'alias' => 'x', 'hash' => 'h'], $headers);
    $this->postJson("/api/ab/peer/add/$guid", ['id' => '43', 'note' => 'added with a note', 'hash' => 'h'], $headers);

    $peers = fn () => collect($this->postJson("/api/ab/peers?ab=$guid", [], $headers)->json('data'))->keyBy('id');
    expect($peers()['42']['note'])->toBe('')
        ->and($peers()['43']['note'])->toBe('added with a note');

    // The client sends only the id and the changed field.
    $this->putJson("/api/ab/peer/update/$guid", ['id' => '42', 'note' => 'Server room, rack 3'], $headers)->assertOk();
    expect($peers()['42']['note'])->toBe('Server room, rack 3')
        ->and($peers()['42']['alias'])->toBe('x');

    // Another field changes: the note stays.
    $this->putJson("/api/ab/peer/update/$guid", ['id' => '42', 'alias' => 'y'], $headers)->assertOk();
    expect($peers()['42']['note'])->toBe('Server room, rack 3');

    // An empty note clears it.
    $this->putJson("/api/ab/peer/update/$guid", ['id' => '42', 'note' => ''], $headers)->assertOk();
    expect($peers()['42']['note'])->toBe('');

    // A huge note is cut, not refused: a refused sync would loop in the client.
    $this->putJson("/api/ab/peer/update/$guid", ['id' => '42', 'note' => str_repeat('n', 5000)], $headers)->assertOk();
    expect(strlen($peers()['42']['note']))->toBe(2000);
});

it('deletes peers via a bare JSON array body', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');
    $this->postJson("/api/ab/peer/add/$guid", ['id' => '42', 'hash' => 'h'], $headers);

    $response = $this->call(
        'DELETE', "/api/ab/peer/$guid", [], [], [],
        $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']),
        json_encode(['42'])
    );

    $response->assertOk();
    expect(AddressBook::where('guid', $guid)->first()->entries()->count())->toBe(0);
});

it('round-trips the legacy triple-encoded address book (spec §25.1)', function () {
    $user = apiUser();
    $headers = bearerFor($user);

    $inner = [
        'tags' => ['work'],
        'peers' => [[
            'id' => '123', 'username' => 'u', 'hostname' => 'h', 'platform' => 'Windows',
            'alias' => 'al', 'note' => 'legacy note', 'tags' => ['work'], 'hash' => 'hh',
        ]],
        'tag_colors' => json_encode(['work' => 4278238420]),
    ];

    $this->postJson('/api/ab', ['data' => json_encode($inner)], $headers)->assertOk();

    $pull = $this->getJson('/api/ab', $headers)->assertOk()->json();
    $decoded = json_decode($pull['data'], true);
    $colors = json_decode($decoded['tag_colors'], true);

    expect($pull['data'])->toBeString()
        ->and($decoded['tags'])->toBe(['work'])
        ->and($decoded['peers'][0]['id'])->toBe('123')
        ->and($decoded['peers'][0]['note'])->toBe('legacy note')
        ->and($decoded['peers'][0]['tags'])->toBe(['work'])
        ->and($colors)->toBe(['work' => 4278238420]);

    // Sciter pull alias.
    $this->postJson('/api/ab/get', [], $headers)->assertOk()->assertJsonStructure(['data']);
});

it('enforces share permissions: read-only members cannot mutate', function () {
    $owner = apiUser();
    $member = apiUser();

    $book = AddressBook::create(['name' => 'Team', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $book->rules()->create(['subject_type' => 'everyone', 'permission' => AddressBookRule::PERM_READ]);

    $headers = bearerFor($member);

    // Visible in profiles with rule 1…
    $profiles = $this->postJson('/api/ab/shared/profiles', [], $headers)->json('data');
    expect($profiles[0]['rule'])->toBe(1);

    // …but mutations come back as an error body.
    $this->postJson("/api/ab/peer/add/{$book->guid}", ['id' => '1'], $headers)
        ->assertOk()
        ->assertJsonStructure(['error']);
});

/*
|--------------------------------------------------------------------------
| B4 — ro / rw / full tiers enforced across every write endpoint.
| Pro-shaped rejection = HTTP 200 with a JSON {error} body.
|--------------------------------------------------------------------------
*/

/** Build a shared book granting $member the given tier, return [$book, $headers]. */
function sharedBookWithTier(int $permission): array
{
    $owner = apiUser();
    $member = apiUser();
    $book = AddressBook::create(['name' => 'Team', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $book->rules()->create(['subject_type' => 'everyone', 'permission' => $permission]);

    // Seed a tag + peer so update/delete/rename targets exist.
    $book->tags()->create(['name' => 'work', 'color' => 0]);
    $book->entries()->create(['rustdesk_id' => '42']);

    return [$book, bearerFor($member)];
}

it('rejects every AB write for a READ-ONLY member with the Pro-shaped error body', function () {
    [$book, $headers] = sharedBookWithTier(AddressBookRule::PERM_READ);
    $g = $book->guid;

    // Entry endpoints (would need RW).
    $this->postJson("/api/ab/peer/add/$g", ['id' => '1'], $headers)->assertOk()->assertJsonStructure(['error']);
    $this->putJson("/api/ab/peer/update/$g", ['id' => '42', 'alias' => 'x'], $headers)->assertOk()->assertJsonStructure(['error']);
    $this->call('DELETE', "/api/ab/peer/$g", [], [], [],
        $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']), json_encode(['42']))
        ->assertOk()->assertJsonStructure(['error']);

    // Tag endpoints (would need FULL).
    $this->postJson("/api/ab/tag/add/$g", ['name' => 'n', 'color' => 0], $headers)->assertOk()->assertJsonStructure(['error']);
    $this->putJson("/api/ab/tag/rename/$g", ['old' => 'work', 'new' => 'w2'], $headers)->assertOk()->assertJsonStructure(['error']);
    $this->putJson("/api/ab/tag/update/$g", ['name' => 'work', 'color' => 1], $headers)->assertOk()->assertJsonStructure(['error']);
    $this->call('DELETE', "/api/ab/tag/$g", [], [], [],
        $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']), json_encode(['work']))
        ->assertOk()->assertJsonStructure(['error']);

    // Nothing changed.
    expect($book->fresh()->entries()->count())->toBe(1)
        ->and($book->fresh()->tags()->pluck('name')->all())->toBe(['work']);
});

it('lets a READ/WRITE member manage entries but NOT tags (tags need FULL)', function () {
    [$book, $headers] = sharedBookWithTier(AddressBookRule::PERM_READ_WRITE);
    $g = $book->guid;

    // Entries: allowed → empty 200 body.
    $add = $this->postJson("/api/ab/peer/add/$g", ['id' => '99', 'alias' => 'ok'], $headers)->assertOk();
    expect($add->getContent())->toBe('');
    expect($book->fresh()->entries()->where('rustdesk_id', '99')->exists())->toBeTrue();

    // Tags: rejected with the Pro-shaped error.
    $this->postJson("/api/ab/tag/add/$g", ['name' => 'nope', 'color' => 0], $headers)
        ->assertOk()->assertJsonStructure(['error']);
    $this->putJson("/api/ab/tag/rename/$g", ['old' => 'work', 'new' => 'x'], $headers)
        ->assertOk()->assertJsonStructure(['error']);

    expect($book->fresh()->tags()->pluck('name')->all())->toBe(['work']);
});

it('lets a FULL-control member manage entries AND tags', function () {
    [$book, $headers] = sharedBookWithTier(AddressBookRule::PERM_FULL);
    $g = $book->guid;

    $this->postJson("/api/ab/peer/add/$g", ['id' => '99'], $headers)->assertOk();
    $this->postJson("/api/ab/tag/add/$g", ['name' => 'urgent', 'color' => 0], $headers)->assertOk();

    expect($book->fresh()->entries()->where('rustdesk_id', '99')->exists())->toBeTrue()
        ->and($book->fresh()->tags()->pluck('name')->sort()->values()->all())->toBe(['urgent', 'work']);
});

it('matches group share rules for every group a multi-group user belongs to', function () {
    $owner = apiUser();
    $member = apiUser();

    $engineering = UserGroup::create(['name' => 'Engineering']);
    $accounting = UserGroup::create(['name' => 'Accounting']);
    $sales = UserGroup::create(['name' => 'Sales']);
    $member->groups()->sync([$engineering->id, $accounting->id]);

    $engBook = AddressBook::create(['name' => 'Eng Book', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $engBook->rules()->create(['subject_type' => 'group', 'subject_id' => $engineering->id, 'permission' => AddressBookRule::PERM_READ]);

    $acctBook = AddressBook::create(['name' => 'Acct Book', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $acctBook->rules()->create(['subject_type' => 'group', 'subject_id' => $accounting->id, 'permission' => AddressBookRule::PERM_READ_WRITE]);

    $salesBook = AddressBook::create(['name' => 'Sales Book', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $salesBook->rules()->create(['subject_type' => 'group', 'subject_id' => $sales->id, 'permission' => AddressBookRule::PERM_FULL]);

    // Client API: both group-rule books are visible with the right permission,
    // the third group's book is not.
    $profiles = collect($this->postJson('/api/ab/shared/profiles', [], bearerFor($member))->json('data'));
    expect($profiles->pluck('rule', 'name')->all())->toBe([
        'Acct Book' => AddressBookRule::PERM_READ_WRITE,
        'Eng Book' => AddressBookRule::PERM_READ,
    ]);

    // Console side agrees (scopeVisibleTo).
    expect(AddressBook::visibleTo($member)->where('is_personal', false)->pluck('name')->sort()->values()->all())
        ->toBe(['Acct Book', 'Eng Book']);
});

// ----------------------------------------------------------- group tab ----

it('serves the group tab with pagination shape and info as an object (spec §20)', function () {
    $user = apiUser(['username' => 'grouper']);
    $owner = apiUser(['username' => 'owner1']);
    $group = DeviceGroup::create(['name' => 'HQ']);
    $user->deviceGroups()->attach($group); // non-admins only see granted folders
    Device::create([
        'rustdesk_id' => '77', 'uuid' => 'u', 'hostname' => 'PC-1', 'alias' => 'Reception PC',
        'os' => 'windows / Windows 11 - 22631', 'username' => 'winuser',
        'user_id' => $owner->id, 'device_group_id' => $group->id,
        'last_online_at' => now(),
    ]);

    $headers = bearerFor($user);

    $this->getJson('/api/device-group/accessible?current=1&pageSize=100', $headers)
        ->assertOk()->assertJsonPath('data.0.name', 'HQ');

    $this->getJson('/api/users?current=1&pageSize=100&accessible=&status=1', $headers)
        ->assertOk()->assertJsonStructure(['total', 'data' => [['name', 'info']]]);

    $peers = $this->getJson('/api/peers?current=1&pageSize=100&accessible=&status=1', $headers)
        ->assertOk()->json('data');

    expect($peers[0]['info'])->toBeArray()
        ->and($peers[0]['info']['device_name'])->toBe('PC-1')
        ->and($peers[0]['alias'])->toBe('Reception PC')
        ->and($peers[0]['status'])->toBe(1)
        ->and($peers[0]['user_name'])->toBe('owner1')
        ->and($peers[0]['device_group_name'])->toBe('HQ');
});

it('filters inactive users out of the group tab when status=1', function () {
    $user = apiUser(['username' => 'active-one']);
    $disabled = apiUser(['username' => 'disabled-one', 'is_active' => false]);

    // Same user group, so only the status=1 filter hides the disabled one.
    $team = UserGroup::create(['name' => 'Team']);
    $team->users()->attach([$user->id, $disabled->id]);

    $names = collect($this->getJson('/api/users?status=1', bearerFor($user))->json('data'))->pluck('name');

    expect($names)->toContain('active-one')->not->toContain('disabled-one');
});

// ------------------------------------------------------------- version ----

it('reports the api version as plain text', function () {
    $this->get('/api/version')->assertOk();
});

it('accepts heartbeats whose uuid is the base64 form of the stored plain uuid, and self-heals', function () {
    // lejianwen imports can hold the PLAIN uuid while clients send base64(uuid).
    Device::create(['rustdesk_id' => '888', 'uuid' => 'cad215e8-9a8a-483a-b0e9-07eb83d28162', 'hostname' => 'h']);

    $b64 = base64_encode('cad215e8-9a8a-483a-b0e9-07eb83d28162');
    $this->postJson('/api/heartbeat', ['id' => '888', 'uuid' => $b64])->assertOk();

    $device = Device::first();
    expect($device->isOnline())->toBeTrue()
        // uuid is re-pinned to the exact form the client sends.
        ->and($device->uuid)->toBe($b64);

    // A genuinely different machine is still rejected.
    $device->update(['last_online_at' => null]);
    $this->postJson('/api/heartbeat', ['id' => '888', 'uuid' => base64_encode('totally-different-uuid')])->assertOk();
    expect(Device::first()->last_online_at)->toBeNull();
});

/*
 * Alias backfill on client adds (issue #28). The client's group tab has no
 * alias to send, so an add from the client arrives blank even when the
 * console names the device.
 */

it('fills a blank alias from the fleet when the client adds a peer', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u-1', 'alias' => 'Front Desk PC', 'user_id' => $user->id]);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');

    $this->postJson("/api/ab/peer/add/$guid", ['id' => '123456789', 'hash' => 'h'], $headers)->assertOk();

    expect(AddressBook::personalFor($user)->entries()->sole()->alias)->toBe('Front Desk PC');
});

it('keeps an alias the client actually typed', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u-2', 'alias' => 'Console name', 'user_id' => $user->id]);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');

    $this->postJson("/api/ab/peer/add/$guid", ['id' => '123456789', 'alias' => 'My name', 'hash' => 'h'], $headers)->assertOk();

    expect(AddressBook::personalFor($user)->entries()->sole()->alias)->toBe('My name');
});

it('does not leak an alias from a device the user cannot see', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    // Someone else's device, in no group the user can reach.
    Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u-3', 'alias' => 'Server room rack 4']);
    $guid = $this->postJson('/api/ab/personal', [], $headers)->json('guid');

    $this->postJson("/api/ab/peer/add/$guid", ['id' => '123456789', 'hash' => 'h'], $headers)->assertOk();

    expect(AddressBook::personalFor($user)->entries()->sole()->alias)->toBe('');
});

it('sends a shared book default password for entries without their own (#162)', function () {
    $user = apiUser();
    $headers = bearerFor($user);
    $book = AddressBook::create([
        'name' => 'Shops', 'owner_user_id' => $user->id, 'is_personal' => false,
        'default_password_enc' => Crypt::encryptString('shop-default'),
    ]);
    $book->entries()->create(['rustdesk_id' => '111']);
    $book->entries()->create(['rustdesk_id' => '222', 'password_enc' => Crypt::encryptString('own')]);

    $data = collect($this->postJson("/api/ab/peers?ab={$book->guid}", [], $headers)->assertOk()->json('data'))->keyBy('id');

    expect($data['111']['password'])->toBe('shop-default')
        ->and($data['222']['password'])->toBe('own')
        ->and($book->toArray())->not->toHaveKey('default_password_enc');
});
