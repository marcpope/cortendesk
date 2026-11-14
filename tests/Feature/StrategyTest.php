<?php

/**
 * Strategies: data model + heartbeat delivery engine (PLAN C2/C3).
 * Oracle for every wire assertion: docs/strategy-protocol.md.
 */

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;

beforeEach(function () {
    // The "any enabled strategy" memo is a static; RefreshDatabase does not
    // reset statics, so a previous test's answer must not leak into this one.
    Strategy::flushCache();
});

function strat(array $attrs = []): Strategy
{
    static $n = 0;
    $n++;

    return Strategy::create(array_merge([
        'name' => "policy-$n",
        'enabled' => true,
        'options' => ['enable-file-transfer' => 'N'],
    ], $attrs));
}

function dev(array $attrs = []): Device
{
    static $n = 0;
    $n++;

    return Device::create(array_merge([
        'rustdesk_id' => (string) (940000000 + $n),
        'uuid' => "strategy-uuid-$n",
        'hostname' => "host-$n",
    ], $attrs));
}

function beat(Device $device, int $modifiedAt = 0)
{
    return test()->postJson('/api/heartbeat', [
        'id' => $device->rustdesk_id,
        'uuid' => $device->uuid,
        'ver' => 1004090,
        'modified_at' => $modifiedAt,
    ]);
}

// ------------------------------------------------------- resolution order ---

it('resolves the default strategy when nothing else is assigned', function () {
    $default = strat(['is_default' => true]);
    $device = dev();

    expect(Strategy::resolve($device))->toBe($default->id)
        ->and($device->fresh()->strategy_id_resolved)->toBe($default->id);
});

it('prefers a device-group assignment over the default', function () {
    strat(['is_default' => true]);
    $groupPolicy = strat();
    $group = DeviceGroup::create(['name' => 'Field']);
    $device = dev(['device_group_id' => $group->id]);

    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $groupPolicy->id);

    expect($device->fresh()->strategy_id_resolved)->toBe($groupPolicy->id);
});

it('prefers a user assignment over a device-group assignment', function () {
    strat(['is_default' => true]);
    $groupPolicy = strat();
    $userPolicy = strat();

    $group = DeviceGroup::create(['name' => 'Field']);
    $user = User::create(['username' => 'owner-a', 'password' => 'secret-password']);
    $device = dev(['device_group_id' => $group->id, 'user_id' => $user->id]);

    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $groupPolicy->id);
    Strategy::assignTo(Strategy::LEVEL_USER, $user->id, $userPolicy->id);

    expect($device->fresh()->strategy_id_resolved)->toBe($userPolicy->id);
});

it('prefers a device assignment over every other level', function () {
    strat(['is_default' => true]);
    $groupPolicy = strat();
    $userPolicy = strat();
    $devicePolicy = strat();

    $group = DeviceGroup::create(['name' => 'Field']);
    $user = User::create(['username' => 'owner-b', 'password' => 'secret-password']);
    $device = dev(['device_group_id' => $group->id, 'user_id' => $user->id]);

    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $groupPolicy->id);
    Strategy::assignTo(Strategy::LEVEL_USER, $user->id, $userPolicy->id);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $devicePolicy->id);

    expect($device->fresh()->strategy_id_resolved)->toBe($devicePolicy->id);

    // Removing the override falls back down the chain, one level at a time.
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, null);
    expect($device->fresh()->strategy_id_resolved)->toBe($userPolicy->id);

    Strategy::assignTo(Strategy::LEVEL_USER, $user->id, null);
    expect($device->fresh()->strategy_id_resolved)->toBe($groupPolicy->id);
});

it('resolves nothing when no strategy exists at all', function () {
    expect(dev()->fresh()->strategy_id_resolved)->toBeNull();
});

it('skips a disabled strategy as if it were not assigned', function () {
    $default = strat(['is_default' => true]);
    $devicePolicy = strat();
    $device = dev();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $devicePolicy->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($devicePolicy->id);

    $devicePolicy->update(['enabled' => false]);
    expect($device->fresh()->strategy_id_resolved)->toBe($default->id);
});

it('keeps exactly one default strategy', function () {
    $first = strat(['is_default' => true]);
    $second = strat(['is_default' => true]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(Strategy::where('is_default', true)->count())->toBe(1);
});

// ----------------------------------------------- cached column maintenance ---

it('recomputes the cached column when a device changes owner', function () {
    $userPolicy = strat();
    $user = User::create(['username' => 'owner-c', 'password' => 'secret-password']);
    Strategy::assignTo(Strategy::LEVEL_USER, $user->id, $userPolicy->id);

    $device = dev();
    expect($device->fresh()->strategy_id_resolved)->toBeNull();

    $device->update(['user_id' => $user->id]);
    expect($device->fresh()->strategy_id_resolved)->toBe($userPolicy->id);

    $device->update(['user_id' => null]);
    expect($device->fresh()->strategy_id_resolved)->toBeNull();
});

it('recomputes the cached column when an assignment moves to another strategy', function () {
    $a = strat();
    $b = strat();
    $device = dev();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $a->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($a->id);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $b->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($b->id)
        ->and(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $device->id))->toBe($b->id);
});

it('recomputes the cached column when a strategy is deleted', function () {
    $default = strat(['is_default' => true]);
    $policy = strat();
    $device = dev();

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($policy->id);

    $policy->delete();

    expect($device->fresh()->strategy_id_resolved)->toBe($default->id)
        ->and(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $device->id))->toBeNull();
});

it('recomputes when a device group is deleted', function () {
    $policy = strat();
    $group = DeviceGroup::create(['name' => 'Doomed']);
    $device = dev(['device_group_id' => $group->id]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $policy->id);
    expect($device->fresh()->strategy_id_resolved)->toBe($policy->id);

    Device::where('device_group_id', $group->id)->update(['device_group_id' => null]);
    $group->delete();

    expect($device->fresh()->strategy_id_resolved)->toBeNull();
});

it('counts assignments per level', function () {
    $policy = strat();
    $device = dev();
    $user = User::create(['username' => 'owner-d', 'password' => 'secret-password']);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);
    Strategy::assignTo(Strategy::LEVEL_USER, $user->id, $policy->id);

    expect($policy->assignmentCounts())->toBe([
        Strategy::LEVEL_DEVICE => 1,
        Strategy::LEVEL_USER => 1,
        Strategy::LEVEL_DEVICE_GROUP => 0,
    ]);
});

// ------------------------------------------------------- option sanitizing ---

it('drops keys outside the allowlist, including the dangerous ones', function () {
    $clean = Strategy::sanitizeOptions([
        'enable-audio' => 'N',
        'stop-service' => 'Y',      // §3.3 — would silence the device forever
        'api-server' => 'http://x', // §3.3 — would orphan the device
        'key' => 'abc',             // §3.3
        '2fa' => 'secret',          // §3.2, deliberately not exposed
        'bot' => '{}',
        'allow-hide-cm' => 'Y',
        'view_style' => 'adaptive', // §4.1 — a display setting, unreachable
        'made-up-key' => '1',
    ]);

    expect($clean)->toBe(['enable-audio' => 'N']);
});

it('coerces every value to a string and rejects out-of-range ones', function () {
    $clean = Strategy::sanitizeOptions([
        'enable-keyboard' => false,
        'allow-auto-disconnect' => true,
        'auto-disconnect-timeout' => 15,
        'temporary-password-length' => 8,
        'whitelist' => ' 10.0.0.0/8 ',
        'id-whitelist' => ' 123456789, 98765* ',
    ]);

    expect($clean)->toBe([
        'allow-auto-disconnect' => 'Y',
        'auto-disconnect-timeout' => '15',
        'enable-keyboard' => 'N',
        'id-whitelist' => '123456789, 98765*',
        'temporary-password-length' => '8',
        'whitelist' => '10.0.0.0/8',
    ]);

    foreach (array_keys($clean) as $key) {
        expect($clean[$key])->toBeString();
    }

    // Values that do not fit their key's type are dropped, not corrected.
    expect(Strategy::sanitizeOptions([
        'temporary-password-length' => '7',
        'approve-mode' => 'whatever',
        'auto-disconnect-timeout' => '0',
        'enable-audio' => 'maybe',
        'enable-camera' => ['nope'],
    ]))->toBe([]);
});

it('pushes nothing while disabled', function () {
    $policy = strat(['enabled' => false, 'options' => ['enable-audio' => 'N']]);

    expect($policy->configOptions())->toBe([]);
});

it('accepts ICE servers the client can use', function () {
    $lists = [
        'stun:stun.example.com' => 'stun:stun.example.com',
        'stun:stun.example.com:19302' => 'stun:stun.example.com:19302',
        'stuns://stun.example.com:5349' => 'stuns://stun.example.com:5349',
        'STUN:203.0.113.7:3478' => 'stun:203.0.113.7:3478',
        'stun:[2001:db8::1]:3478' => 'stun:[2001:db8::1]:3478',
        'turn://rd:pw@turn.example.com' => 'turn://rd:pw@turn.example.com',
        'turns://rd:p%40ss@turn.example.com:443' => 'turns://rd:p%40ss@turn.example.com:443',
        ' stun:a.example.com , turn://u:p@b.example.com:3478 ' => 'stun:a.example.com,turn://u:p@b.example.com:3478',
    ];

    foreach ($lists as $in => $out) {
        expect(Strategy::sanitizeOptions(['ice-servers' => $in]))->toBe(['ice-servers' => $out]);
    }

    // "" still means "back to the client's built-in servers".
    expect(Strategy::sanitizeOptions(['ice-servers' => '']))->toBe(['ice-servers' => '']);
});

it('drops an ICE server list holding any entry the client would skip', function () {
    $bad = [
        'turn:turn.example.com:3478',            // TURN without credentials
        'turn://rd@turn.example.com',            // no password
        'turn://:pw@turn.example.com',           // no user name
        'stun://u:p@stun.example.com',           // STUN takes no credentials
        'stun:2001:db8::1',                      // IPv6 without brackets
        'stun:stun.example.com:99999',           // port out of range
        'stun:stun.example.com:0',
        'stun:stun.example.com:abc',
        'http://stun.example.com',               // not an ICE scheme
        'stun.example.com:3478',                 // no scheme
        'stun:',
        'stun:bad_host.example.com',
        'turn://rd:p@ss@turn.example.com',       // raw @ in the password
        'turn://rd:p%zz@turn.example.com',       // broken percent-encoding
        'stun:stun.example.com/path',
        'stun:good.example.com, turn:bad.example.com',
        implode(',', array_fill(0, 17, 'stun:stun.example.com')),
    ];

    foreach ($bad as $value) {
        expect(Strategy::sanitizeOptions(['ice-servers' => $value]))->toBe([], $value);
    }
});

it('pushes ice-servers in the heartbeat', function () {
    strat(['is_default' => true, 'options' => ['ice-servers' => 'stun:stun.example.com:3478']]);
    $device = dev();

    beat($device)->assertJsonPath('strategy.config_options', ['ice-servers' => 'stun:stun.example.com:3478']);
});

// -------------------------------------------------------------- delivery ----

it('leaves the heartbeat response untouched for a device with no strategy', function () {
    // The contract pin: this endpoint is spoken by every device in the field.
    $device = dev();

    $response = beat($device);
    $response->assertOk();

    expect($response->json())->toBe([])
        ->and($device->fresh()->strategy_version)->toBeNull();

    // Still nothing once strategies exist but none reaches this device.
    strat();
    Strategy::flushCache();
    expect(beat($device)->json())->toBe([]);

    // ... and a device that has never sent inventory still gets exactly the
    // sysinfo request it got before strategies existed.
    $bare = Device::create(['rustdesk_id' => '910000001', 'uuid' => 'bare']);
    expect(beat($bare)->json())->toBe(['sysinfo' => 1]);
});

it('embeds the resolved strategy with a version token, as a map of strings', function () {
    $policy = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $body = beat($device)->assertOk()->json();

    expect($body)->toHaveKey('strategy')
        ->and($body['strategy'])->toBe(['config_options' => [
            'enable-audio' => 'N',
            'enable-file-transfer' => 'N',
        ]])
        ->and($body)->not->toHaveKey('extra')
        ->and($body['strategy'])->not->toHaveKey('extra')
        ->and($body['modified_at'])->toBeInt()
        ->and($body['modified_at'])->toBeGreaterThan(0);

    // Every value on the wire is a JSON string: one number or bool would make
    // the client discard the whole policy (protocol §1.3).
    $raw = json_decode(beat($device)->getContent(), true);
    foreach ($raw['strategy']['config_options'] as $value) {
        expect($value)->toBeString();
    }
});

it('stops sending the strategy once the device echoes the version, and records the ack', function () {
    $policy = strat();
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');

    // Second heartbeat, now echoing the token we just handed out.
    expect(beat($device, $version)->json())->toBe([]);

    $device->refresh();
    expect($device->strategy_version)->toBe($version)
        ->and($device->strategy_acked_options)->toBe(['enable-file-transfer' => 'N'])
        ->and($device->strategy_acked_at)->not->toBeNull();
});

it('re-sends the full policy to a device that has forgotten its token', function () {
    $policy = strat();
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');
    beat($device, $version);

    // A reinstall wipes the client's stored token and it reports 0 again.
    $body = beat($device, 0)->json();

    expect($body['modified_at'])->toBe($version)
        ->and($body['strategy']['config_options'])->toBe(['enable-file-transfer' => 'N']);
});

it('advances the version only when the effective policy really changes', function () {
    $policy = strat();
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $first = beat($device)->json('modified_at');
    beat($device, $first);

    // Touching the strategy without changing its map is not a change.
    $policy->update(['note' => 'renamed', 'name' => 'still-the-same-map']);
    expect(beat($device, $first)->json())->toBe([])
        ->and($device->fresh()->strategy_version)->toBe($first);

    // Swapping to a DIFFERENT strategy with an IDENTICAL map is not a change
    // either — the version tracks the effective options, not the row id.
    $twin = strat(['options' => ['enable-file-transfer' => 'N']]);
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $twin->id);
    expect(beat($device, $first)->json())->toBe([])
        ->and($device->fresh()->strategy_version)->toBe($first);

    // Editing the map IS a change: new token, and it only goes up.
    $twin->update(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $second = beat($device, $first)->json('modified_at');
    expect($second)->toBeGreaterThan($first);
});

it('resets the keys it pushed when the assignment goes away', function () {
    // The client has no revert: removing an assignment must PUSH "" for every
    // key we previously set, or the device keeps the policy forever (§6.5).
    $policy = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');
    beat($device, $version); // device acknowledges

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, null);

    $body = beat($device, $version)->json();
    expect($body['strategy']['config_options'])->toBe([
        'enable-audio' => '',
        'enable-file-transfer' => '',
    ])
        ->and($body['modified_at'])->toBeGreaterThan($version);

    // Once the reset is acknowledged the device drops out of the conversation
    // again — same response as a device that never had a policy.
    expect(beat($device, $body['modified_at'])->json())->toBe([])
        ->and(beat($device, 0)->json())->toBe([]);
});

it('resets only the keys that a narrower replacement policy dropped', function () {
    $wide = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $narrow = strat(['options' => ['enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $wide->id);

    $version = beat($device)->json('modified_at');
    beat($device, $version);

    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $narrow->id);

    expect(beat($device, $version)->json('strategy.config_options'))->toBe([
        'enable-audio' => 'N',
        'enable-file-transfer' => '',
    ]);
});

/*
 * The ack always arrives ONE heartbeat after the device applied a map (§1: up to
 * ~15s, or days if the machine sleeps). Anything the operator changes inside
 * that window used to be resolved by bumping the version first and comparing
 * the echo afterwards, which threw the ack away — and with it the only record of
 * what the device is holding, leaving the options applied forever.
 */
it('clears the pushed keys even when the policy is dropped before the device acks', function () {
    $policy = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    // Beat 1: the device receives and applies both keys. Nothing is acked yet.
    $version = beat($device)->json('modified_at');
    expect($device->fresh()->strategy_acked_options)->toBeNull();

    // The operator unassigns inside the ack window.
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, null);

    // Beat 2 carries the ack for the OLD token and must still carry the reset.
    $body = beat($device, $version)->json();

    expect($body['strategy']['config_options'])->toBe([
        'enable-audio' => '',
        'enable-file-transfer' => '',
    ])
        ->and($body['modified_at'])->toBeGreaterThan($version)
        // The ack was recorded against what was actually pushed, not discarded.
        ->and($device->fresh()->strategy_acked_options)->toBe([
            'enable-audio' => 'N',
            'enable-file-transfer' => 'N',
        ]);

    // And the conversation ends once the reset itself is acknowledged.
    expect(beat($device, $body['modified_at'])->json())->toBe([]);
});

it('resets a key that was pushed but never acked when the policy narrows', function () {
    $policy = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');

    // Narrowed before the device could acknowledge the wide map.
    $policy->update(['options' => ['enable-audio' => 'N']]);

    expect(beat($device, $version)->json('strategy.config_options'))->toBe([
        'enable-audio' => 'N',
        'enable-file-transfer' => '',
    ]);
});

it('does not lose a reset when the device sleeps through several edits', function () {
    // A machine that is off for a week echoes a token several versions old; the
    // union of "what it acked" and "what we last sent" is what has to be cleared.
    $policy = strat(['options' => ['enable-file-transfer' => 'N', 'enable-audio' => 'N']]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');
    beat($device, $version); // acked: both keys

    $policy->update(['options' => ['enable-keyboard' => 'N']]);
    beat($device, $version);  // pushed while the device was already asleep

    $policy->update(['options' => []]);

    // It finally wakes up, still holding the FIRST map.
    expect(beat($device, $version)->json('strategy.config_options'))->toBe([
        'enable-audio' => '',
        'enable-file-transfer' => '',
        'enable-keyboard' => '',
    ]);
});

it('re-pushes on every heartbeat when the strategy is enforced', function () {
    $policy = strat(['enforce' => true]);
    $device = dev();
    Strategy::assignTo(Strategy::LEVEL_DEVICE, $device->id, $policy->id);

    $version = beat($device)->json('modified_at');
    $body = beat($device, $version)->json();

    expect($body['modified_at'])->toBe($version)
        ->and($body['strategy']['config_options'])->toBe(['enable-file-transfer' => 'N']);
});

it('never pushes a policy to a device the approval gate is holding', function () {
    $policy = strat(['is_default' => true]);
    $device = dev(['status' => Device::STATUS_PENDING]);

    expect(beat($device)->json())->toBe([]);

    $device->update(['status' => Device::STATUS_ACTIVE]);
    expect(beat($device)->json())->toHaveKey('strategy');
});

it('never pushes a policy to a recycled or spoofed device', function () {
    $policy = strat(['is_default' => true]);

    $trashed = dev();
    $trashed->delete();
    expect(beat($trashed)->json())->toBe([]);

    $device = dev();
    expect(test()->postJson('/api/heartbeat', [
        'id' => $device->rustdesk_id,
        'uuid' => 'not-the-right-uuid',
        'modified_at' => 0,
    ])->json())->toBe([]);
});

it('pushes the group policy to a device via its group, live over the heartbeat', function () {
    $group = DeviceGroup::create(['name' => 'Kiosks']);
    $device = dev(['device_group_id' => $group->id]);
    $policy = strat(['options' => ['enable-keyboard' => 'N']]);

    expect(beat($device)->json())->toBe([]);

    Strategy::assignTo(Strategy::LEVEL_DEVICE_GROUP, $group->id, $policy->id);

    expect(beat($device)->json('strategy.config_options'))->toBe(['enable-keyboard' => 'N']);
});

// ------------------------------------------------------------ --assign ----

/**
 * `--assign --strategy_name` reaches two areas at once, so the token needs both:
 * device rw to touch the device row, strategy rw to push a policy onto it.
 */
function strategyAssignHeaders(array $perms = ['device' => 'rw', 'strategy' => 'rw']): array
{
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'strategy-'.uniqid(), $perms);

    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

it('assigns a named strategy to the device through --assign', function () {
    $policy = strat(['name' => 'Locked down']);

    test()->postJson('/api/devices/cli', [
        'id' => '945000001', 'uuid' => 'cli-uuid', 'strategy_name' => 'Locked down',
    ], strategyAssignHeaders())->assertOk();

    $device = Device::where('rustdesk_id', '945000001')->firstOrFail();

    expect(Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, $device->id))->toBe($policy->id)
        ->and($device->strategy_id_resolved)->toBe($policy->id);
});

it('fails an --assign that names an unknown strategy, changing nothing', function () {
    test()->postJson('/api/devices/cli', [
        'id' => '945000002', 'uuid' => 'cli-uuid-2', 'strategy_name' => 'Nope', 'note' => 'x',
    ], strategyAssignHeaders())->assertNotFound();

    expect(Device::where('rustdesk_id', '945000002')->exists())->toBeFalse();
});

it('refreshes the acknowledgement timestamp under enforce', function () {
    // Observed against a real 1.4.9 client: with `enforce` on, the device
    // re-echoes an identical map every heartbeat, which used to leave
    // strategy_acked_at frozen at the first ack — the console then showed a
    // stale "last confirmed" while the policy was being actively enforced.
    $strategy = \App\Models\Strategy::create([
        'name' => 'Enforced', 'enabled' => true, 'enforce' => true,
        'options' => \App\Models\Strategy::sanitizeOptions(['enable-file-transfer' => 'N']),
    ]);

    $device = dev(['status' => \App\Models\Device::STATUS_ACTIVE]);
    \App\Models\Strategy::assignTo('device', $device->id, $strategy->id);
    $device->refresh();

    // First delivery, then the ack on the following heartbeat.
    \App\Models\Strategy::deliveryFor($device, 0);
    $device->refresh();
    \App\Models\Strategy::deliveryFor($device, (int) $device->strategy_version);
    $device->refresh();

    $firstAck = $device->strategy_acked_at;
    expect($firstAck)->not->toBeNull();

    // An immediate re-echo of the SAME map must not churn the row.
    \App\Models\Strategy::deliveryFor($device, (int) $device->strategy_version);
    $device->refresh();
    expect($device->strategy_acked_at->timestamp)->toBe($firstAck->timestamp);

    // Once the timestamp goes stale, the next echo refreshes it.
    $device->forceFill([
        'strategy_acked_at' => now()->subSeconds(\App\Models\Strategy::ACK_REFRESH_SECONDS + 5),
    ])->saveQuietly();

    \App\Models\Strategy::deliveryFor($device, (int) $device->strategy_version);
    $device->refresh();

    expect($device->strategy_acked_at->gt($firstAck->subSecond()))->toBeTrue()
        ->and($device->strategy_acked_options)->toBe(['enable-file-transfer' => 'N']);
});
