<?php

use App\Livewire\AgentDetail;
use App\Livewire\AgentEnrollmentManager;
use App\Livewire\AgentInstallGuide;
use App\Livewire\MonitoringDashboard;
use App\Livewire\SettingsPage;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentSample;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Monitoring;
use App\Support\RustDeskReleases;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function monitoringOn(): void
{
    Setting::put(Monitoring::SETTING_ENABLED, '1');
    config(['cortendesk.agent_manifest' => base_path('tests/Fixtures/agent-manifest-unpublished.json')]);
}

function makeAgent(array $attrs = []): Agent
{
    $agent = new Agent($attrs + [
        'public_id' => (string) Str::ulid(),
        'machine_uuid' => (string) Str::uuid(),
        'hostname' => 'web-01',
        'os' => 'linux',
        'os_version' => 'Ubuntu 24.04',
        'enabled' => true,
        'last_seen_at' => now(),
        'latest' => [
            'uptime' => 7200,
            'cpu' => ['percent' => 20, 'cores' => 4, 'load' => [0.5]],
            'memory' => ['total' => 1000, 'used' => 500],
            'disks' => [['mount' => '/', 'fs' => 'ext4', 'total' => 100, 'used' => 40]],
        ],
    ]);
    $agent->rotateSecret();
    $agent->save();

    return $agent;
}

it('hides the menu and 404s every screen while Monitoring is off', function () {
    $admin = User::factory()->admin()->create();
    makeAgent();

    $this->actingAs($admin)->get('/')->assertOk()->assertDontSee(route('monitoring'), false);
    $this->get('/monitoring')->assertNotFound();
    $this->get('/monitoring/agents/1')->assertNotFound();
    $this->get('/agent/v1/install.sh')->assertNotFound();
});

it('shows the menu and onboarding once Monitoring is on', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/')->assertSee(route('monitoring'), false);
    $this->get('/monitoring')->assertOk()
        ->assertSee('Monitor your machines with the CortenDesk Agent')
        ->assertSee('Pick an enrollment token')
        ->assertSee('Install the agent');
});

it('turns Monitoring on from Settings and audits it', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->set('monitoringEnabled', true)
        ->set('monitoringInterval', 120)
        ->set('monitoringRetentionDays', 30)
        ->call('save')
        ->assertHasNoErrors();

    expect(Monitoring::enabled())->toBeTrue()
        ->and(Monitoring::interval())->toBe(120)
        ->and(Monitoring::retentionDays())->toBe(30)
        ->and(ConsoleAudit::where('action', 'settings.monitoring')->exists())->toBeTrue();
});

it('rejects an out-of-range interval and jumps to the Monitoring tab', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->set('monitoringInterval', 5)
        ->call('save')
        ->assertHasErrors('monitoringInterval')
        ->assertSet('tab', 'monitoring');
});

it('lists agents with tiles and filters by attention', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    makeAgent(['hostname' => 'calm']);
    makeAgent(['hostname' => 'full-disk', 'latest' => [
        'cpu' => ['percent' => 5], 'memory' => ['total' => 10, 'used' => 1],
        'disks' => [['mount' => '/', 'total' => 100, 'used' => 95]],
    ]]);
    makeAgent(['hostname' => 'gone', 'last_seen_at' => now()->subHour()]);

    Livewire::actingAs($admin)->test(MonitoringDashboard::class)
        ->assertViewHas('onboarding', false)
        ->assertSee('Fleet health')
        ->call('setTab', 'machines')
        ->assertViewHas('totalCount', 3)
        ->assertViewHas('onlineCount', 2)
        ->assertViewHas('offlineCount', 1)
        ->assertViewHas('attentionCount', 1)
        ->assertSee('calm')
        ->call('filterByChip', 'attention')
        ->assertSee('full-disk')
        ->assertDontSee('calm');
});

it('scopes agents by device-group access', function () {
    monitoringOn();
    $mine = DeviceGroup::create(['name' => 'Mine']);
    $other = DeviceGroup::create(['name' => 'Other']);
    $user = User::factory()->create();
    $user->deviceGroups()->attach($mine);

    makeAgent(['hostname' => 'in-my-group', 'device_group_id' => $mine->id]);
    makeAgent(['hostname' => 'elsewhere', 'device_group_id' => $other->id]);
    $device = Device::create(['rustdesk_id' => '555', 'uuid' => 'u-555', 'status' => 'active', 'device_group_id' => $mine->id]);
    makeAgent(['hostname' => 'via-device', 'device_id' => $device->id, 'device_group_id' => $other->id]);
    $hidden = makeAgent(['hostname' => 'no-group']);

    Livewire::actingAs($user)->test(MonitoringDashboard::class, ['tab' => 'machines'])
        ->set('tab', 'machines')
        ->assertSee('in-my-group')
        ->assertSee('via-device')
        ->assertDontSee('elsewhere')
        ->assertDontSee('no-group');

    $this->actingAs($user)->get('/monitoring/agents/'.$hidden->id)->assertNotFound();
});

it('gives roles no Monitoring until granted, and the legacy user View', function () {
    monitoringOn();
    $role = Role::create(['name' => 'Helpdesk', 'permissions' => Role::normalizePermissions(['device' => 'r'])]);
    $roled = User::factory()->create(['role_id' => $role->id]);
    $legacy = User::factory()->create();

    $this->actingAs($roled)->get('/monitoring')->assertRedirect(route('overview'));
    $this->actingAs($legacy)->get('/monitoring')->assertOk();

    Livewire::actingAs($legacy)->test(MonitoringDashboard::class)
        ->assertSee('Ask an administrator');
    Livewire::actingAs($legacy)->test(AgentEnrollmentManager::class)->assertForbidden();
});

it('creates a token scoped to a group, opens Add agent for it, and revokes it', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    $group = DeviceGroup::create(['name' => 'Servers']);

    $component = Livewire::actingAs($admin)->test(AgentEnrollmentManager::class)
        ->set('name', 'Rack A')
        ->set('groupId', $group->id)
        ->set('maxUses', 5)
        ->call('create')
        ->assertHasNoErrors();

    $token = AgentEnrollmentToken::sole();
    $plain = $token->plain();
    expect($plain)->toStartWith('cde_')
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->getRawOriginal('token_encrypted'))->not->toContain($plain)
        ->and($token->toArray())->not->toHaveKey('token_encrypted')
        ->and($token->device_group_id)->toBe($group->id)
        ->and($token->max_uses)->toBe(5);
    $component->assertRedirect(route('monitoring.add', ['token' => $token->id]));
    Livewire::actingAs($admin)->test(AgentEnrollmentManager::class)
        ->assertSee(route('monitoring.add', ['token' => $token->id]), false);

    $component->call('revoke', $token->id);
    expect($token->fresh()->state())->toBe('revoked')
        ->and(ConsoleAudit::whereIn('action', ['agent.token-create', 'agent.token-revoke'])->count())->toBe(2);
});

it('makes a group-limited manager pick a group for a token', function () {
    monitoringOn();
    $role = Role::create(['name' => 'Mon', 'permissions' => Role::normalizePermissions(['monitoring' => 'rw'])]);
    $user = User::factory()->create(['role_id' => $role->id]);

    Livewire::actingAs($user)->test(AgentEnrollmentManager::class)
        ->set('name', 'x')
        ->call('create')
        ->assertHasErrors('groupId');
    expect(AgentEnrollmentToken::count())->toBe(0);
});

it('shows an agent, disables it, links it and deletes it', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    $agent = makeAgent();
    AgentSample::create(['agent_id' => $agent->id, 'taken_at' => now()->subMinutes(5), 'cpu_percent' => 10, 'mem_used' => 1, 'mem_total' => 2, 'disk_percent' => 40]);
    $device = Device::create(['rustdesk_id' => '777', 'uuid' => 'u-777', 'status' => 'active']);

    $this->actingAs($admin)->get('/monitoring/agents/'.$agent->id)->assertOk()->assertSee('web-01');

    $component = Livewire::actingAs($admin)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertDispatched('agent-series')
        ->call('toggleEnabled');
    expect($agent->fresh()->enabled)->toBeFalse();

    $component->set('linkRustdeskId', 'nope')->call('link')->assertHasErrors('linkRustdeskId');
    $component->set('linkRustdeskId', '777')->call('link')->assertHasNoErrors();
    expect($agent->fresh()->device_id)->toBe($device->id);

    $component->call('delete')->assertRedirect(route('monitoring'));
    expect(Agent::count())->toBe(0)->and(AgentSample::count())->toBe(0);
});

it('shows the Monitoring tab on a linked device only', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    $linked = Device::create(['rustdesk_id' => '100', 'uuid' => 'u-100', 'status' => 'active']);
    $plain = Device::create(['rustdesk_id' => '200', 'uuid' => 'u-200', 'status' => 'active']);
    makeAgent(['device_id' => $linked->id]);

    $this->actingAs($admin)->get('/devices/'.$linked->id)->assertOk()->assertSee('Agent details');
    $this->actingAs($admin)->get('/devices/'.$plain->id)->assertOk()->assertDontSee('Agent details');

    Setting::put(Monitoring::SETTING_ENABLED, '0');
    $this->actingAs($admin)->get('/devices/'.$linked->id)->assertOk()->assertDontSee('Agent details');
});

it('prunes samples past retention even while Monitoring is off', function () {
    Setting::put(Monitoring::SETTING_RETENTION, '7');
    $agent = makeAgent();
    AgentSample::create(['agent_id' => $agent->id, 'taken_at' => now()->subDays(8)]);
    AgentSample::create(['agent_id' => $agent->id, 'taken_at' => now()->subDays(6)]);

    $this->artisan('cortendesk:prune-agent-samples')->assertSuccessful();

    expect(AgentSample::count())->toBe(1);
});

it('serves install scripts with this console and the pinned version', function () {
    monitoringOn();

    $this->get('/agent/v1/install.sh')->assertOk()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8')
        ->assertSee("SERVER='".url('/')."'", false)
        ->assertSee('linux-amd64) URL=', false);
    $this->get('/agent/v1/install.ps1')->assertOk()
        ->assertSee("\$Server = '".url('/')."'", false)
        ->assertSee("'amd64' = @{ Url = ", false);
});

it('sums the fleet on the overview tab and opens the filtered list from it', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    $busy = makeAgent(['hostname' => 'busy', 'latest' => [
        'cpu' => ['percent' => 95], 'memory' => ['total' => 100, 'used' => 50],
        'disks' => [['mount' => 'C:', 'total' => 100, 'used' => 95]],
        'health' => ['firewall' => false, 'updates' => ['missing' => 4, 'failed' => 0]],
        'reboot_pending' => true,
    ]]);
    makeAgent(['hostname' => 'calm', 'latest' => [
        'cpu' => ['percent' => 5], 'memory' => ['total' => 100, 'used' => 10],
        'disks' => [['mount' => '/', 'total' => 100, 'used' => 10]],
        'health' => ['firewall' => true],
    ]]);
    makeAgent(['hostname' => 'gone', 'last_seen_at' => now()->subHour()]);
    AgentSample::create(['agent_id' => $busy->id, 'taken_at' => now()->subMinutes(10), 'cpu_percent' => 90, 'mem_used' => 50, 'mem_total' => 100]);

    $c = Livewire::actingAs($admin)->test(MonitoringDashboard::class)->assertDispatched('fleet-charts');
    $o = $c->viewData('overview');

    expect($o['states'])->toBe(['healthy' => 1, 'attention' => 1, 'offline' => 1, 'disabled' => 0])
        ->and($o['avgCpu'])->toBe(50.0)
        ->and($o['lowDisk'])->toBe(1)
        ->and($o['updatesMissing'])->toBe(4)
        ->and($o['rebootPending'])->toBe(1)
        ->and($o['topCpu'][0]['agent']->hostname)->toBe('busy')
        ->and($o['security']['firewall'])->toBe(['ok' => 1, 'of' => 2])
        ->and($o['attention'][0]['agent']->hostname)->toBe('busy')
        ->and($o['trend']['cpu'])->toHaveCount(1)
        ->and($o['offline'][0]->hostname)->toBe('gone');

    $c->call('showMachines', 'attention', 'cpu')
        ->assertSet('tab', 'machines')
        ->assertSet('status', 'attention')
        ->assertSet('sortField', 'cpu')
        ->assertSee('busy')
        ->assertDontSee('calm');
});

it('acknowledges an issue so it leaves needs attention, and restores it', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();
    $agent = makeAgent();
    $agent->forceFill(['latest' => $agent->latest + ['health' => ['firewall' => false, 'services' => [['name' => 'nginx', 'display' => '', 'state' => 'stopped']]]]])->save();

    expect(MonitoringDashboard::needsAttention($agent))->toBeTrue()
        ->and(collect($agent->issues())->pluck('key')->all())->toBe(['service_stopped:nginx', 'firewall_off']);

    $page = Livewire::actingAs($admin)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertSee('Firewall is off')
        ->call('acknowledgeIssue', 'firewall_off')
        ->call('acknowledgeIssue', 'made_up_key')
        ->assertSee('Acknowledged (1)');

    $agent->refresh();
    expect(array_keys($agent->acknowledged_issues))->toBe(['firewall_off'])
        ->and(collect($agent->issues())->pluck('key')->all())->toBe(['service_stopped:nginx'])
        ->and(ConsoleAudit::where('action', 'agent.issue-ack')->count())->toBe(1);

    $page->call('acknowledgeIssue', 'service_stopped:nginx');
    expect(MonitoringDashboard::needsAttention($agent->refresh()))->toBeFalse();

    $page->call('restoreIssue', 'firewall_off');
    expect(collect($agent->refresh()->issues())->pluck('key')->all())->toBe(['firewall_off'])
        ->and(ConsoleAudit::where('action', 'agent.issue-restore')->count())->toBe(1);
});

it('does not let a Monitoring viewer acknowledge issues', function () {
    monitoringOn();
    $group = DeviceGroup::create(['name' => 'Mine']);
    $user = User::factory()->create();
    $user->deviceGroups()->attach($group);
    $agent = makeAgent(['device_group_id' => $group->id]);
    $agent->forceFill(['latest' => $agent->latest + ['health' => ['firewall' => false]]])->save();

    Livewire::actingAs($user)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertDontSee('Acknowledge')
        ->call('acknowledgeIssue', 'firewall_off')
        ->assertForbidden();

    expect($agent->refresh()->acknowledged_issues)->toBeNull();
});

it('queues and cancels remote actions from the agent page', function () {
    monitoringOn();
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $admin = User::factory()->admin()->create();
    $agent = makeAgent();
    $agent->forceFill(['capabilities' => ['agent.update', 'os.update', 'reboot']])->save();

    $page = Livewire::actingAs($admin)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertSee('Remote actions')
        ->assertDontSee('Read-only telemetry')
        ->assertDontSee('Upgrade RustDesk')
        ->call('openAction', 'reboot')
        ->set('rebootDelay', 300)
        ->set('rebootMessage', 'Patch night')
        ->call('queueAction')
        ->assertHasNoErrors()
        ->assertSee('Reboot in 5 min')
        ->assertSee('Queued. The agent picks it up');

    $cmd = AgentCommand::firstOrFail();
    expect($cmd->params)->toBe(['delay' => 300, 'message' => 'Patch night'])
        ->and($cmd->created_by_name)->toBe($admin->username)
        ->and(ConsoleAudit::where('action', 'agent.command')->count())->toBe(1);

    // A delay outside the offered list falls back to now.
    $page->call('openAction', 'os.update')->set('updateReboot', true)->call('queueAction');
    expect(AgentCommand::where('type', 'os.update')->first()->params)->toBe(['reboot' => true]);

    $page->call('cancelCommand', $cmd->public_id);
    expect($cmd->refresh()->status)->toBe('cancelled')
        ->and(ConsoleAudit::where('action', 'agent.command-cancel')->count())->toBe(1);
});

it('keeps remote actions behind the setting and the Remote commands permission', function () {
    monitoringOn();
    $group = DeviceGroup::create(['name' => 'Mine']);
    $agent = makeAgent(['device_group_id' => $group->id]);
    $agent->forceFill(['capabilities' => ['reboot']])->save();
    $admin = User::factory()->admin()->create();

    // Setting off: read-only, and the action is refused.
    Livewire::actingAs($admin)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertSee('Read-only telemetry')
        ->assertDontSee('Remote actions')
        ->call('openAction', 'reboot')
        ->call('queueAction')
        ->assertHasErrors('action');
    expect(AgentCommand::count())->toBe(0);

    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $viewer = User::factory()->create(['role_id' => Role::create(['name' => 'Watcher', 'permissions' => Role::normalizePermissions(['monitoring' => 'rw', 'agent_command' => 'r'])])->id]);
    $viewer->deviceGroups()->attach($group);
    $operator = User::factory()->create(['role_id' => Role::create(['name' => 'Operator', 'permissions' => Role::normalizePermissions(['monitoring' => 'r', 'agent_command' => 'rw'])])->id]);
    $operator->deviceGroups()->attach($group);

    Livewire::actingAs($viewer)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertDontSee('Remote actions')
        ->call('openAction', 'reboot')
        ->assertForbidden();
    Livewire::actingAs($operator)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertSee('Remote actions')
        ->call('openAction', 'reboot')
        ->call('queueAction')
        ->assertHasNoErrors();
    expect(AgentCommand::count())->toBe(1);

    // The legacy (no role) user has no Remote commands.
    $legacy = User::factory()->create();
    $legacy->deviceGroups()->attach($group);
    Livewire::actingAs($legacy)->test(AgentDetail::class, ['agentId' => $agent->id])
        ->assertDontSee('Commands')
        ->call('cancelCommand', AgentCommand::first()->public_id)
        ->assertForbidden();
});

it('saves the remote command and auto-update settings with audit entries', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(SettingsPage::class)
        ->assertSet('monitoringCommands', false)
        ->assertSet('monitoringAutoUpdate', true)
        ->set('monitoringCommands', true)
        ->set('monitoringAutoUpdate', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Monitoring::commandsEnabled())->toBeTrue()
        ->and(Monitoring::autoUpdate())->toBeFalse()
        ->and(ConsoleAudit::where('action', 'settings.monitoring-commands')->exists())->toBeTrue()
        ->and(ConsoleAudit::where('action', 'settings.monitoring-auto-update')->exists())->toBeTrue();
});

it('queues a bulk command on the selected visible machines and skips the ones that cannot take it', function () {
    monitoringOn();
    Setting::put(Monitoring::SETTING_COMMANDS, '1');
    $group = DeviceGroup::create(['name' => 'Mine']);
    $operator = User::factory()->create(['role_id' => Role::create(['name' => 'Operator', 'permissions' => Role::normalizePermissions(['monitoring' => 'r', 'agent_command' => 'rw'])])->id]);
    $operator->deviceGroups()->attach($group);
    $a = makeAgent(['hostname' => 'a', 'device_group_id' => $group->id]);
    $b = makeAgent(['hostname' => 'b', 'device_group_id' => $group->id]);
    $old = makeAgent(['hostname' => 'old', 'device_group_id' => $group->id]);
    $hidden = makeAgent(['hostname' => 'hidden']);
    foreach ([$a, $b, $hidden] as $x) {
        $x->forceFill(['capabilities' => ['reboot', 'os.update']])->save();
    }

    Livewire::actingAs($operator)->test(MonitoringDashboard::class, ['tab' => 'machines'])
        ->set('tab', 'machines')
        ->set('selected', [(string) $a->id, (string) $b->id, (string) $old->id, (string) $hidden->id])
        ->call('openBulk', 'reboot')
        ->set('bulkDelay', 900)
        ->call('bulkQueue')
        ->assertSet('selected', [])
        ->assertSee('Queued on 2 machines. Skipped 1');

    expect(AgentCommand::pluck('agent_id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and(AgentCommand::pluck('batch')->unique())->toHaveCount(1)
        ->and(AgentCommand::first()->params)->toBe(['delay' => 900, 'message' => ''])
        ->and(ConsoleAudit::where('action', 'agent.command')->count())->toBe(1);

    // Viewers get no checkboxes and can't call it.
    Livewire::actingAs(User::factory()->create())->test(MonitoringDashboard::class)
        ->call('openBulk', 'reboot')
        ->assertForbidden();
});

it('filters machines on an older RustDesk than the latest release', function () {
    monitoringOn();
    Cache::put(RustDeskReleases::CACHE_KEY, ['1.5.1' => ['rustdesk-1.5.1-x86_64.exe' => str_repeat('a', 64)]]);
    $admin = User::factory()->admin()->create();
    $new = makeAgent(['hostname' => 'current-one']);
    $new->forceFill(['latest' => $new->latest + ['rustdesk' => ['installed' => true, 'running' => true, 'version' => '1.5.1']]])->save();
    $old = makeAgent(['hostname' => 'behind-one']);
    $old->forceFill(['latest' => $old->latest + ['rustdesk' => ['installed' => true, 'running' => true, 'version' => '1.4.6']]])->save();

    Livewire::actingAs($admin)->test(MonitoringDashboard::class)
        ->assertSee('1 older than 1.5.1')
        ->call('showMachines', 'rustdesk_old')
        ->assertSee('behind-one')
        ->assertDontSee('current-one');
});

it('saves the agent connection URL, allows plain http only to a private address, and uses it for installs', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();

    foreach ([
        'http://8.8.8.8:8090' => true, 'http://172.32.0.1' => true, 'https://ok.example.com/x?y=1' => true, 'ftp://x.example.com' => true,
        'https://desk.example.com:8443/' => false, 'http://127.0.0.1:8090' => false, 'http://10.0.0.5:8090' => false,
        'http://192.168.1.20:8000' => false, 'http://100.101.5.6' => false, 'http://[fd00::5]:8000' => false, '' => false,
    ] as $url => $bad) {
        $page = Livewire::actingAs($admin)->test(SettingsPage::class)->set('monitoringAgentUrl', $url)->call('save');
        $bad ? $page->assertHasErrors('monitoringAgentUrl') : $page->assertHasNoErrors();
    }

    Livewire::actingAs($admin)->test(SettingsPage::class)->set('monitoringAgentUrl', 'https://desk.example.com:8443/')->call('save');
    expect(Monitoring::agentUrl())->toBe('https://desk.example.com:8443')
        ->and(ConsoleAudit::where('action', 'settings.monitoring-agent-url')->exists())->toBeTrue();

    $this->get('/agent/v1/install.sh')->assertOk()->assertSee("SERVER='https://desk.example.com:8443'", false);
    $this->actingAs($admin)->get(route('monitoring.add'))->assertOk()->assertSee('https://desk.example.com:8443');
});

it('walks through adding an agent: token, commands for each OS, then the new machine', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();

    makeAgent(['hostname' => 'old-box']);
    $this->actingAs($admin)->get('/monitoring')->assertSee(route('monitoring.add'), false);

    // No tokens yet: the create form is open and the command has a placeholder.
    $guide = Livewire::actingAs($admin)->test(AgentInstallGuide::class)
        ->assertSet('creating', true)
        ->assertSee("-Token '<token>'")
        ->set('name', 'Office PCs')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('creating', false);

    $office = AgentEnrollmentToken::where('name', 'Office PCs')->sole();
    $plain = $office->plain();
    $guide->assertSet('tokenId', $office->id)
        ->assertSee("-Token '{$plain}'")
        ->call('setOs', 'linux')
        ->assertSee("install.sh | sudo sh -s -- --token '{$plain}'")
        ->call('setOs', 'macos')
        ->assertSee('macOS 12 or later');

    // Later visits pick the newest usable token; any listed one can be chosen.
    [$lab, $labPlain] = AgentEnrollmentToken::issue('Lab', null, $admin);
    AgentEnrollmentToken::issue('Old', null, $admin)[0]->forceFill(['revoked_at' => now()])->save();
    $again = Livewire::actingAs($admin)->test(AgentInstallGuide::class)
        ->assertSet('creating', false)
        ->assertSet('tokenId', $lab->id)
        ->assertSee("-Token '{$labPlain}'")
        ->assertDontSee('Old ·')
        ->set('tokenId', $office->id)
        ->assertSee("-Token '{$plain}'");

    // A revoked or unknown id gives no command.
    Livewire::actingAs($admin)->withQueryParams(['token' => 999])->test(AgentInstallGuide::class)
        ->assertSet('tokenId', $lab->id);
    $again->set('tokenId', 999)->assertSee("-Token '<token>'");

    $this->travel(1)->seconds();
    makeAgent(['hostname' => 'fresh-box']);
    $guide->call('$refresh')->assertSee('fresh-box');
});

it('only offers tokens for groups the user can see', function () {
    monitoringOn();
    $mine = DeviceGroup::create(['name' => 'Mine']);
    $other = DeviceGroup::create(['name' => 'Other']);
    $role = Role::create(['name' => 'Mon', 'permissions' => Role::normalizePermissions(['monitoring' => 'rw'])]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $user->deviceGroups()->attach($mine);

    [$ok, $okPlain] = AgentEnrollmentToken::issue('Mine', $mine->id, null);
    [$hidden, $hiddenPlain] = AgentEnrollmentToken::issue('Other', $other->id, null);

    Livewire::actingAs($user)->test(AgentInstallGuide::class)
        ->assertSet('tokenId', $ok->id)
        ->assertSee($okPlain)
        ->set('tokenId', $hidden->id)
        ->assertDontSee($hiddenPlain)
        ->assertSee("-Token '<token>'");
});

it('notes plain http on the Add agent page and warns when agents could not use it', function () {
    monitoringOn();
    $admin = User::factory()->admin()->create();

    Setting::put(Monitoring::SETTING_AGENT_URL, 'http://192.168.1.20:8000');
    $this->actingAs($admin)->get(route('monitoring.add'))->assertOk()
        ->assertSee('Plain http: the token and agent traffic')
        ->assertDontSee("This address isn't");

    Setting::put(Monitoring::SETTING_AGENT_URL, 'http://8.8.8.8');
    $this->actingAs($admin)->get(route('monitoring.add'))->assertOk()
        ->assertSee("This address isn't");
});

it('keeps the Add agent page to Monitoring managers', function () {
    monitoringOn();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('monitoring.add'))->assertRedirect();
    Livewire::actingAs($viewer)->test(AgentInstallGuide::class)->assertForbidden();
    makeAgent();
    $this->actingAs($viewer)->get('/monitoring')->assertDontSee(route('monitoring.add'), false);
});
