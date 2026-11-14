<?php

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentSample;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use App\Services\AgentCommands;
use App\Support\Monitoring;
use App\Support\RustDeskReleases;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Setting::put(Monitoring::SETTING_ENABLED, '1');
    // Independent of the release manifest that ships: no published build,
    // so no automatic self-update unless a test sets its own manifest.
    config(['cortendesk.agent_manifest' => base_path('tests/Fixtures/agent-manifest-unpublished.json')]);
});

function agentToken(array $attrs = []): string
{
    [$token, $plain] = AgentEnrollmentToken::issue('Servers', $attrs['device_group_id'] ?? null, null, $attrs['expires_at'] ?? null, $attrs['max_uses'] ?? null);

    return $plain;
}

function enrollAgent(array $body = []): array
{
    $res = test()->postJson('/agent/v1/enroll', $body + [
        'token' => agentToken(),
        'machine_uuid' => base64_encode('machine-1'),
        'hostname' => 'web-01',
        'os' => 'linux',
        'os_version' => 'Ubuntu 24.04',
        'arch' => 'amd64',
        'agent_version' => '0.1.0',
    ])->assertCreated();

    return $res->json();
}

function reportBody(array $over = []): array
{
    return array_replace_recursive([
        'agent_version' => '0.1.0',
        'hostname' => 'web-01',
        'uptime' => 3600,
        'cpu' => ['percent' => 12.5, 'cores' => 4, 'load' => [0.4, 0.3, 0.2]],
        'memory' => ['total' => 8_000_000_000, 'used' => 2_000_000_000, 'swap_total' => 0, 'swap_used' => 0],
        'disks' => [
            ['mount' => '/', 'fs' => 'ext4', 'total' => 100_000_000_000, 'used' => 50_000_000_000],
            ['mount' => '/data', 'fs' => 'xfs', 'total' => 100_000_000_000, 'used' => 95_000_000_000],
        ],
        'network' => ['rx_bytes' => 1000, 'tx_bytes' => 500],
        'rustdesk' => ['installed' => true, 'running' => true, 'version' => '1.4.3'],
        'users' => ['marc'],
        'reboot_pending' => false,
    ], $over);
}

function bearer(array $enrolled): array
{
    return ['Authorization' => 'Bearer '.$enrolled['agent_id'].'.'.$enrolled['secret']];
}

it('answers 404 on every agent path while Monitoring is off', function () {
    Setting::put(Monitoring::SETTING_ENABLED, '0');

    $this->postJson('/agent/v1/enroll', ['token' => agentToken(), 'machine_uuid' => 'x'])->assertNotFound();
    $this->postJson('/agent/v1/report', [])->assertNotFound();
    $this->postJson('/agent/v1/inventory', [])->assertNotFound();
    expect(Agent::count())->toBe(0);
});

it('enrolls with a token, stores only hashes, and counts the use', function () {
    $plain = agentToken();
    $res = $this->postJson('/agent/v1/enroll', [
        'token' => $plain, 'machine_uuid' => 'bWFjaGluZQ==', 'hostname' => 'web-01', 'os' => 'linux',
    ])->assertCreated()->assertJsonStructure(['agent_id', 'secret', 'interval']);

    $agent = Agent::sole();
    expect($agent->public_id)->toBe($res->json('agent_id'))
        ->and($agent->secret_hash)->toBe(hash('sha256', $res->json('secret')))
        ->and($agent->hostname)->toBe('web-01')
        ->and($res->json('interval'))->toBe(Monitoring::DEFAULT_INTERVAL)
        ->and(AgentEnrollmentToken::sole()->uses)->toBe(1);
});

it('rejects bad, revoked, expired and used-up tokens', function () {
    $this->postJson('/agent/v1/enroll', ['token' => 'cde_nope', 'machine_uuid' => 'a'])
        ->assertStatus(401)->assertJson(['error' => 'invalid_token']);

    $revoked = agentToken();
    AgentEnrollmentToken::query()->update(['revoked_at' => now()]);
    $this->postJson('/agent/v1/enroll', ['token' => $revoked, 'machine_uuid' => 'a'])->assertStatus(401);

    $expired = agentToken(['expires_at' => now()->subMinute()]);
    $this->postJson('/agent/v1/enroll', ['token' => $expired, 'machine_uuid' => 'a'])->assertStatus(401);

    $once = agentToken(['max_uses' => 1]);
    $this->postJson('/agent/v1/enroll', ['token' => $once, 'machine_uuid' => 'a'])->assertCreated();
    $this->postJson('/agent/v1/enroll', ['token' => $once, 'machine_uuid' => 'b'])->assertStatus(401);
});

it('re-enrolling the same machine keeps the row and rotates the secret', function () {
    $first = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(), bearer($first))->assertOk();

    $second = enrollAgent();

    expect(Agent::count())->toBe(1)
        ->and($second['agent_id'])->toBe($first['agent_id'])
        ->and(AgentSample::count())->toBe(1);
    $this->postJson('/agent/v1/report', reportBody(), bearer($first))->assertStatus(401)->assertJson(['error' => 'revoked']);
    $this->postJson('/agent/v1/report', reportBody(), bearer($second))->assertOk();
});

it('puts the agent in the token group', function () {
    $group = DeviceGroup::create(['name' => 'Servers']);
    enrollAgent(['token' => agentToken(['device_group_id' => $group->id])]);

    expect(Agent::sole()->device_group_id)->toBe($group->id);
});

it('stores a report and a sample, and asks for inventory until it has one', function () {
    $agent = enrollAgent();

    $this->postJson('/agent/v1/report', reportBody(), bearer($agent))
        ->assertOk()->assertJson(['interval' => 60, 'inventory' => true]);

    $row = Agent::sole();
    $sample = AgentSample::sole();
    expect($row->isOnline())->toBeTrue()
        ->and($row->cpuPercent())->toBe(12.5)
        ->and($row->memoryPercent())->toBe(25.0)
        ->and($row->fullestDisk()['mount'])->toBe('/data')
        ->and($sample->disk_percent)->toBe(95.0)
        ->and($sample->load1)->toBe(0.4)
        ->and($sample->rx_bps)->toBeNull();

    $hash = str_repeat('a', 64);
    $this->postJson('/agent/v1/inventory', [
        'hash' => $hash,
        'os' => ['name' => 'Ubuntu 24.04'],
        'hardware' => ['model' => 'PowerEdge'],
        'software' => [['name' => 'nginx', 'version' => '1.24']],
    ], bearer($agent))->assertOk();

    expect(Agent::sole()->inventory['software'][0]['name'])->toBe('nginx');

    $this->postJson('/agent/v1/report', reportBody(['inventory_hash' => $hash]), bearer($agent))
        ->assertOk()->assertJson(['inventory' => false]);
});

it('turns network counters into a rate and drops it after a reboot', function () {
    $agent = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(), bearer($agent));

    $this->travel(60)->seconds();
    $this->postJson('/agent/v1/report', reportBody(['network' => ['rx_bytes' => 61000, 'tx_bytes' => 6500]]), bearer($agent));
    expect(AgentSample::latest('id')->first()->rx_bps)->toBe(1000)
        ->and(AgentSample::latest('id')->first()->tx_bps)->toBe(100);

    $this->travel(60)->seconds();
    $this->postJson('/agent/v1/report', reportBody(['network' => ['rx_bytes' => 10, 'tx_bytes' => 10]]), bearer($agent));
    expect(AgentSample::latest('id')->first()->rx_bps)->toBeNull();
});

it('shows no traffic, not zero, when the adapter counts packets but not bytes', function () {
    $agent = enrollAgent();
    $counts = fn ($p) => ['network' => ['rx_bytes' => 0, 'tx_bytes' => 0, 'rx_packets' => $p, 'tx_packets' => $p]];
    $this->postJson('/agent/v1/report', reportBody($counts(100)), bearer($agent));
    $this->travel(60)->seconds();
    $this->postJson('/agent/v1/report', reportBody($counts(900)), bearer($agent));

    expect(AgentSample::latest('id')->first()->rx_bps)->toBeNull()
        ->and(Agent::sole()->latest['network']['rx_packets'])->toBe(900);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(Agent::sole()->url())->assertOk()
        ->assertSee('counts packets but not bytes');

    // An idle machine with nothing counted at all still reads as 0.
    expect(Agent::networkBytesUnreported(['rx_bytes' => 0, 'tx_bytes' => 0, 'rx_packets' => 0, 'tx_packets' => 0]))->toBeFalse()
        ->and(Agent::networkBytesUnreported(['rx_bytes' => 0, 'tx_bytes' => 0]))->toBeFalse();
});

it('keeps only known fields and clamps CPU', function () {
    $agent = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(['cpu' => ['percent' => 250], 'junk' => 'x']), bearer($agent))->assertOk();

    $latest = Agent::sole()->latest;
    expect($latest)->not->toHaveKey('junk')
        ->and($latest['cpu']['percent'])->toBe(100);
});

it('tells a disabled agent to back off and stores nothing', function () {
    $agent = enrollAgent();
    Agent::query()->update(['enabled' => false]);

    $this->postJson('/agent/v1/report', reportBody(), bearer($agent))
        ->assertStatus(403)->assertJson(['error' => 'disabled', 'interval' => Monitoring::DISABLED_INTERVAL]);
    expect(AgentSample::count())->toBe(0);
});

it('answers 401 revoked for a deleted agent or a bad secret', function () {
    $agent = enrollAgent();

    $this->postJson('/agent/v1/report', reportBody(), ['Authorization' => 'Bearer '.$agent['agent_id'].'.wrong'])
        ->assertStatus(401)->assertJson(['error' => 'revoked']);

    Agent::query()->delete();
    $this->postJson('/agent/v1/report', reportBody(), bearer($agent))->assertStatus(401);
});

it('links to the device by machine uuid, else by RustDesk ID', function () {
    $byUuid = Device::create(['rustdesk_id' => '111', 'uuid' => base64_encode('machine-1'), 'status' => 'active']);
    enrollAgent();
    expect(Agent::sole()->device_id)->toBe($byUuid->id);

    $byId = Device::create(['rustdesk_id' => '222', 'uuid' => 'other', 'status' => 'active']);
    enrollAgent(['machine_uuid' => 'machine-2', 'rustdesk_id' => '222']);
    expect(Agent::where('machine_uuid', 'machine-2')->sole()->device_id)->toBe($byId->id);
});

it('links on a later report once RustDesk is installed', function () {
    $agent = enrollAgent(['machine_uuid' => 'm-9']);
    expect(Agent::sole()->device_id)->toBeNull();

    $device = Device::create(['rustdesk_id' => '999', 'uuid' => 'not-it', 'status' => 'active']);
    $this->postJson('/agent/v1/report', reportBody(['rustdesk_id' => '999']), bearer($agent))->assertOk();

    expect(Agent::sole()->device_id)->toBe($device->id);
});

it('never links two agents to one device', function () {
    Device::create(['rustdesk_id' => '111', 'uuid' => 'dup', 'status' => 'active']);
    enrollAgent(['machine_uuid' => 'dup']);
    enrollAgent(['machine_uuid' => 'dup-clone', 'rustdesk_id' => '111']);

    expect(Agent::whereNotNull('device_id')->count())->toBe(1);
});

it('rejects a malformed report with 422', function () {
    $agent = enrollAgent();

    $this->postJson('/agent/v1/report', ['cpu' => 'lots'], bearer($agent))
        ->assertStatus(422)->assertJson(['error' => 'invalid']);
});

function caps(): array
{
    return ['capabilities' => ['agent.update', 'os.update', 'reboot', 'rustdesk.upgrade', 'Bad Cap!']];
}

it('stores capabilities, hands out queued commands once and records results', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk()->assertJsonMissingPath('commands');
    $agent = Agent::firstOrFail();
    expect($agent->capabilities)->toBe(['agent.update', 'os.update', 'reboot', 'rustdesk.upgrade']);

    $cmd = app(AgentCommands::class)->queue($agent, 'reboot', ['delay' => 300, 'message' => 'Patch night'], $admin);
    // Same type while open: the open one comes back.
    expect(app(AgentCommands::class)->queue($agent, 'reboot', ['delay' => 0], $admin)->id)->toBe($cmd->id);

    $res = $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    expect($res->json('commands'))->toBe([['id' => $cmd->public_id, 'type' => 'reboot', 'params' => ['delay' => 300, 'message' => 'Patch night']]])
        ->and($cmd->refresh()->status)->toBe('sent');
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');

    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'running'], bearer($enrolled))->assertOk();
    expect($cmd->refresh()->status)->toBe('running')->and($cmd->started_at)->not->toBeNull();
    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'succeeded', 'output' => 'Reboot scheduled.', 'exit_code' => 0], bearer($enrolled))->assertOk();
    expect($cmd->refresh()->status)->toBe('succeeded')->and($cmd->output)->toBe('Reboot scheduled.');
    // A late result for a finished command changes nothing.
    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'failed', 'error' => 'late'], bearer($enrolled))->assertOk();
    expect($cmd->refresh()->status)->toBe('succeeded')
        ->and(ConsoleAudit::where('action', 'agent.command-result')->count())->toBe(1);

    $this->postJson('/agent/v1/commands/01NOTMINE', ['status' => 'succeeded'], bearer($enrolled))->assertNotFound();
    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'exploded'], bearer($enrolled))->assertStatus(422);
    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'succeeded'])->assertUnauthorized();
});

it('does not let one agent post results for another agent\'s command', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $one = enrollAgent();
    $two = enrollAgent(['machine_uuid' => base64_encode('machine-2'), 'token' => agentToken()]);
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($one))->assertOk();
    $cmd = app(AgentCommands::class)->queue(Agent::where('public_id', $one['agent_id'])->first(), 'reboot', [], $admin);

    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'succeeded'], bearer($two))->assertNotFound();
    expect($cmd->refresh()->status)->toBe('queued');
});

it('sends nothing but agent updates while remote commands are off', function () {
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    $agent = Agent::firstOrFail();

    expect(fn () => app(AgentCommands::class)->queue($agent, 'reboot', [], $admin))->toThrow(InvalidArgumentException::class);

    // Queued while on, then turned off: held back, not delivered.
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    app(AgentCommands::class)->queue($agent, 'reboot', [], $admin);
    Setting::put(Monitoring::SETTING_COMMANDS, '0');
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');
});

it('refuses commands the agent build does not support', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(['capabilities' => ['agent.update']]), bearer($enrolled))->assertOk();

    expect(fn () => app(AgentCommands::class)->queue(Agent::firstOrFail(), 'reboot', [], $admin))
        ->toThrow(InvalidArgumentException::class, "can't run that command");
});

it('answers a trial report without storing it or handing out commands', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    $agent = Agent::firstOrFail();
    $cmd = app(AgentCommands::class)->queue($agent, 'reboot', [], $admin);
    $samples = AgentSample::count();

    $this->postJson('/agent/v1/report', reportBody(caps() + ['trial' => true, 'agent_version' => '9.9.9']), bearer($enrolled))
        ->assertOk()->assertJsonMissingPath('commands');

    expect(AgentSample::count())->toBe($samples)
        ->and($agent->refresh()->agent_version)->toBe('0.1.0')
        ->and($cmd->refresh()->status)->toBe('queued');
});

it('expires queued commands and fails delivered ones that never report back', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    $agent = Agent::firstOrFail();
    $reboot = app(AgentCommands::class)->queue($agent, 'reboot', [], $admin);
    $update = app(AgentCommands::class)->queue($agent, 'os.update', ['reboot' => true], $admin);
    $update->forceFill(['status' => 'running', 'sent_at' => now()->subHours(6), 'started_at' => now()->subHours(6)])->save();

    $this->travel(2)->hours();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');

    expect($reboot->refresh()->status)->toBe('expired')
        ->and($update->refresh()->status)->toBe('failed')
        ->and($update->error)->toBe('No result from the agent.');
});

it('resends a delivered command the agent never acknowledged', function () {
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    $cmd = app(AgentCommands::class)->queue(Agent::firstOrFail(), 'os.update', [], $admin);

    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonPath('commands.0.id', $cmd->public_id);
    $this->travel(16)->minutes();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonPath('commands.0.id', $cmd->public_id);
});

it('queues a signed self-update for an older agent, once, and not again after it fails', function () {
    $sha = str_repeat('a', 64);
    $manifest = tempnam(sys_get_temp_dir(), 'manifest');
    file_put_contents($manifest, json_encode(['version' => '0.2.0', 'base_url' => 'https://example.test/v0.2.0', 'files' => [
        'linux-amd64' => ['name' => 'cortendesk-agent_linux_amd64', 'sha256' => $sha, 'signature' => 'c2ln'],
        'linux-arm64' => ['name' => 'cortendesk-agent_linux_arm64', 'sha256' => '', 'signature' => ''],
    ]]));
    config(['cortendesk.agent_manifest' => $manifest]);
    $enrolled = enrollAgent();

    $res = $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    expect($res->json('commands'))->toHaveCount(1)
        ->and($res->json('commands.0'))->toMatchArray(['type' => 'agent.update', 'params' => [
            'version' => '0.2.0', 'url' => 'https://example.test/v0.2.0/cortendesk-agent_linux_amd64', 'sha256' => $sha, 'signature' => 'c2ln',
        ]]);
    $cmd = AgentCommand::firstOrFail();
    expect($cmd->created_by_name)->toBe('system');

    // Open: no second one. Failed: not retried for a day.
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');
    $this->postJson('/agent/v1/commands/'.$cmd->public_id, ['status' => 'failed', 'error' => 'rolled back'], bearer($enrolled))->assertOk();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');
    $this->travel(25)->hours();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonPath('commands.0.type', 'agent.update');

    // Already current, unsigned platform, or the setting off: nothing.
    AgentCommand::query()->delete();
    $this->postJson('/agent/v1/report', reportBody(caps() + ['agent_version' => '0.2.0']), bearer($enrolled))->assertJsonMissingPath('commands');
    Agent::query()->update(['arch' => 'arm64']);
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');
    Agent::query()->update(['arch' => 'amd64']);
    Setting::put(Monitoring::SETTING_AUTO_UPDATE, '0');
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertJsonMissingPath('commands');
    @unlink($manifest);
});

it('builds RustDesk upgrade params from the GitHub release for the agent platform', function () {
    $digest = fn ($c) => 'sha256:'.str_repeat($c, 64);
    $parsed = RustDeskReleases::parse([
        ['tag_name' => 'nightly', 'prerelease' => true, 'draft' => false, 'assets' => [['name' => 'rustdesk-1.5.2-x86_64.exe', 'digest' => $digest('f')]]],
        ['tag_name' => '1.5.0', 'prerelease' => false, 'draft' => false, 'assets' => [['name' => 'rustdesk-1.5.0-x86_64.exe', 'digest' => $digest('0')]]],
        ['tag_name' => '1.5.1', 'prerelease' => false, 'draft' => false, 'assets' => [
            ['name' => 'rustdesk-1.5.1-x86_64.exe', 'digest' => $digest('a')],
            ['name' => 'rustdesk-1.5.1-x86_64.msi', 'digest' => $digest('b')],
            ['name' => 'rustdesk-1.5.1-x86-sciter.exe', 'digest' => $digest('c')],
            ['name' => 'rustdesk-1.5.1-aarch64.dmg', 'digest' => $digest('d')],
            ['name' => 'rustdesk-1.5.1-x86_64.deb', 'digest' => $digest('e')],
            ['name' => 'rustdesk-1.5.1-0.x86_64.rpm', 'digest' => $digest('1')],
            ['name' => 'rustdesk-1.5.1-0.x86_64-suse.rpm', 'digest' => $digest('2')],
            ['name' => 'rustdesk-1.5.1-x86_64-sciter.deb', 'digest' => $digest('3')],
            ['name' => 'rustdesk-1.5.1-x86_64.AppImage', 'digest' => $digest('4')],
            ['name' => 'rustdesk-1.5.1-x86_64.flatpak', 'digest' => null],
        ]],
    ]);
    Cache::put(RustDeskReleases::CACHE_KEY, $parsed);

    expect(RustDeskReleases::versions())->toBe(['1.5.1', '1.5.0'])
        ->and(array_column(RustDeskReleases::assetsFor('1.5.1', 'windows', 'amd64'), 'name'))->toBe(['rustdesk-1.5.1-x86_64.exe', 'rustdesk-1.5.1-x86_64.msi'])
        ->and(array_column(RustDeskReleases::assetsFor('1.5.1', 'darwin', 'arm64'), 'name'))->toBe(['rustdesk-1.5.1-aarch64.dmg'])
        ->and(array_column(RustDeskReleases::assetsFor('1.5.1', 'linux', 'amd64'), 'name'))->toBe(['rustdesk-1.5.1-x86_64.deb', 'rustdesk-1.5.1-0.x86_64.rpm', 'rustdesk-1.5.1-0.x86_64-suse.rpm'])
        ->and(RustDeskReleases::assetsFor('1.5.1', 'linux', 'riscv64'))->toBe([])
        ->and(RustDeskReleases::assetsFor('9.9.9', 'windows', 'amd64'))->toBe([]);

    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $enrolled = enrollAgent();
    $this->postJson('/agent/v1/report', reportBody(caps()), bearer($enrolled))->assertOk();
    $cmd = app(AgentCommands::class)->queue(Agent::firstOrFail(), 'rustdesk.upgrade', ['version' => '1.5.1'], User::factory()->admin()->create());
    expect($cmd->params['assets'][0])->toBe(['name' => 'rustdesk-1.5.1-x86_64.deb', 'sha256' => str_repeat('e', 64)]);
    expect(fn () => app(AgentCommands::class)->queue(Agent::firstOrFail(), 'rustdesk.upgrade', ['version' => '1.4.0'], User::factory()->admin()->create()))
        ->toThrow(InvalidArgumentException::class);
});
