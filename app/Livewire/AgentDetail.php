<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentSample;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Services\AgentCommands;
use App\Support\AgentInstall;
use App\Support\Monitoring;
use App\Support\RustDeskReleases;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One agent: current health, history charts, inventory, and the Manage
 * actions (disable, link to a device, delete).
 */
class AgentDetail extends Component
{
    use AuthorizesConsole;

    /** Range => seconds of history; 0 is everything kept. */
    public const RANGES = ['1h' => 3600, '24h' => 86400, '7d' => 604800, 'all' => 0];

    /** Most points per chart line; longer ranges are averaged into buckets. */
    public const MAX_POINTS = 240;

    /** Most services one agent watches. */
    public const MAX_WATCHED = 32;

    /** Software rows rendered at once; the search narrows the rest. */
    public const SOFTWARE_LIMIT = 200;

    public int $agentId;

    /** On the device page's Monitoring tab: the device header is already there. */
    public bool $embedded = false;

    /** Which inventory panel is open: hardware, software, admins, or none. */
    public string $inventoryPanel = '';

    public bool $editingWatch = false;

    /** One service name per line, while editing. */
    public string $watchInput = '';

    #[Url(except: '24h')]
    public string $range = '24h';

    public string $softwareSearch = '';

    public string $linkRustdeskId = '';

    /** The acknowledged-issues list is open. */
    public bool $showAcknowledged = false;

    /** Open remote-action form: rustdesk.upgrade, os.update, reboot, or none. */
    public string $action = '';

    public string $rustdeskVersion = '';

    public bool $updateReboot = false;

    /** Seconds; one of REBOOT_DELAYS. */
    public int $rebootDelay = 0;

    public string $rebootMessage = '';

    /** Command whose output is shown. */
    public string $openCommand = '';

    /** @var array{tone: string, text: string}|null */
    public ?array $notice = null;

    /** Reboot delays offered, in seconds. */
    public const REBOOT_DELAYS = [0, 300, 900, 3600];

    /** Commands listed on the page. */
    public const COMMANDS_SHOWN = 20;

    public function boot(): void
    {
        abort_unless(Monitoring::enabled(), 404);
    }

    public function mount(int $agentId, bool $embedded = false): void
    {
        $this->authorizeConsole('monitoring', 'r');
        $this->agentId = $agentId;
        $this->embedded = $embedded;
        $this->agent();

        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = '24h';
        }
    }

    private function agent(): Agent
    {
        return Agent::query()->visibleTo(auth()->user())->with(['device', 'group', 'enrollmentToken:id,name'])->findOrFail($this->agentId);
    }

    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, self::RANGES) ? $range : '24h';
    }

    public function showInventory(string $panel): void
    {
        $panel = in_array($panel, ['hardware', 'software', 'admins'], true) ? $panel : '';
        $this->inventoryPanel = $this->inventoryPanel === $panel ? '' : $panel;
    }

    public function editWatch(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $this->watchInput = implode("\n", (array) $this->agent()->watch_services);
        $this->editingWatch = true;
    }

    public function saveWatch(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();

        $names = collect(preg_split('/[\r\n,]+/', $this->watchInput) ?: [])
            ->map(fn ($n) => trim($n))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 128 && ! preg_match('/[\x00-\x1f"\'`;|&<>]/', $n))
            ->unique()
            ->take(self::MAX_WATCHED)
            ->values()
            ->all();

        $agent->forceFill(['watch_services' => $names])->save();
        $this->editingWatch = false;
        ConsoleAudit::record('agent.watch-services', 'Watched services on '.$agent->displayName().': '.(implode(', ', $names) ?: 'none'), 'agent', (string) $agent->id);
    }

    public function cancelWatch(): void
    {
        $this->editingWatch = false;
    }

    public function toggleEnabled(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();
        $agent->forceFill(['enabled' => ! $agent->enabled])->save();

        ConsoleAudit::record(
            $agent->enabled ? 'agent.enable' : 'agent.disable',
            ($agent->enabled ? 'Enabled agent ' : 'Disabled agent ').$agent->displayName(),
            'agent',
            (string) $agent->id,
        );
    }

    /** Hide a current issue from the banner and "needs attention" until restored. */
    public function acknowledgeIssue(string $key): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();
        $issue = collect($agent->allIssues())->firstWhere('key', $key);
        if (! $issue || $issue['acknowledged']) {
            return;
        }

        $acked = (array) ($agent->acknowledged_issues ?? []);
        $acked[$key] = ['by' => auth()->user()->username, 'at' => now()->toIso8601String()];
        $agent->forceFill(['acknowledged_issues' => $acked])->save();

        ConsoleAudit::record('agent.issue-ack', 'Acknowledged "'.$issue['text'].'" on '.$agent->displayName(), 'agent', (string) $agent->id);
    }

    public function restoreIssue(string $key): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();
        $acked = (array) ($agent->acknowledged_issues ?? []);
        if (! array_key_exists($key, $acked)) {
            return;
        }

        unset($acked[$key]);
        $agent->forceFill(['acknowledged_issues' => $acked ?: null])->save();
        if ($agent->acknowledgedIssues() === []) {
            $this->showAcknowledged = false;
        }

        $text = collect($agent->allIssues())->firstWhere('key', $key)['text'] ?? $key;
        ConsoleAudit::record('agent.issue-restore', 'Restored "'.$text.'" on '.$agent->displayName(), 'agent', (string) $agent->id);
    }

    public function openAction(string $type): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        if (! in_array($type, AgentCommand::ACTIONS, true)) {
            return;
        }
        $this->resetErrorBag();
        $this->notice = null;
        $this->action = $type;
        if ($type === AgentCommand::RUSTDESK_UPGRADE) {
            $this->rustdeskVersion = RustDeskReleases::latest() ?? '';
        }
    }

    public function closeAction(): void
    {
        $this->action = '';
        $this->resetErrorBag();
    }

    public function queueAction(AgentCommands $commands): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        $agent = $this->agent();
        $input = match ($this->action) {
            AgentCommand::RUSTDESK_UPGRADE => ['version' => $this->rustdeskVersion],
            AgentCommand::OS_UPDATE => ['reboot' => $this->updateReboot],
            AgentCommand::REBOOT => [
                'delay' => in_array($this->rebootDelay, self::REBOOT_DELAYS, true) ? $this->rebootDelay : 0,
                'message' => $this->rebootMessage,
            ],
            default => null,
        };
        if ($input === null) {
            return;
        }

        try {
            $command = $commands->queue($agent, $this->action, $input, auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        if ($command->wasRecentlyCreated) {
            ConsoleAudit::record('agent.command', 'Queued "'.$command->summary().'" on '.$agent->displayName(), 'agent', (string) $agent->id);
            $this->notice = ['tone' => 'success', 'text' => __('monitoring.command_queued')];
        } else {
            $this->notice = ['tone' => 'info', 'text' => __('monitoring.command_already_open', ['action' => $command->summary()])];
        }
        $this->action = '';
        $this->rebootMessage = '';
    }

    public function updateAgent(AgentCommands $commands): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        $agent = $this->agent();
        $command = $commands->requestSelfUpdate($agent, auth()->user());
        if (! $command) {
            $this->notice = ['tone' => 'info', 'text' => __('monitoring.agent_update_none')];

            return;
        }
        ConsoleAudit::record('agent.command', 'Queued "'.$command->summary().'" on '.$agent->displayName(), 'agent', (string) $agent->id);
        $this->notice = ['tone' => 'success', 'text' => __('monitoring.command_queued')];
    }

    public function cancelCommand(string $publicId, AgentCommands $commands): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        $agent = $this->agent();
        $command = $agent->commands()->where('public_id', $publicId)->first();
        if ($command && $commands->cancel($command)) {
            ConsoleAudit::record('agent.command-cancel', 'Cancelled "'.$command->summary().'" on '.$agent->displayName(), 'agent', (string) $agent->id);
        }
    }

    public function toggleCommand(string $publicId): void
    {
        $this->openCommand = $this->openCommand === $publicId ? '' : $publicId;
    }

    public function link(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $this->resetErrorBag('linkRustdeskId');
        $agent = $this->agent();

        $device = Device::query()->visibleTo(auth()->user())->where('rustdesk_id', trim($this->linkRustdeskId))->first();
        if (! $device) {
            $this->addError('linkRustdeskId', __('monitoring.link_no_device'));

            return;
        }
        if (Agent::query()->where('device_id', $device->id)->whereKeyNot($agent->id)->exists()) {
            $this->addError('linkRustdeskId', __('monitoring.link_taken'));

            return;
        }

        $agent->forceFill(['device_id' => $device->id])->save();
        $this->linkRustdeskId = '';
        ConsoleAudit::record('agent.link', 'Linked agent '.$agent->hostname.' to device '.$device->rustdesk_id, 'agent', (string) $agent->id);
    }

    public function unlink(): void
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();
        $from = $agent->device?->rustdesk_id;

        // Unlinking keeps the agent visible to whoever could see it through
        // the device's group, instead of dropping it to admins only.
        $agent->forceFill([
            'device_id' => null,
            'device_group_id' => $agent->device?->device_group_id ?? $agent->device_group_id,
        ])->save();
        ConsoleAudit::record('agent.unlink', 'Unlinked agent '.$agent->hostname.' from device '.$from, 'agent', (string) $agent->id);
    }

    public function delete()
    {
        $this->authorizeConsole('monitoring', 'rw');
        $agent = $this->agent();
        $name = $agent->displayName();
        $agent->delete();

        ConsoleAudit::record('agent.delete', 'Deleted agent '.$name, 'agent', (string) $this->agentId);

        return $this->redirectRoute('monitoring', navigate: false);
    }

    /**
     * Chart series for the selected range, averaged into at most MAX_POINTS
     * buckets so a week at 60 s stays a light payload.
     *
     * @return array{cpu: array, memory: array, disk: array, rx: array, tx: array}
     */
    private function series(Agent $agent): array
    {
        $seconds = self::RANGES[$this->range];
        $rows = AgentSample::query()
            ->where('agent_id', $agent->id)
            ->when($seconds > 0, fn ($q) => $q->where('taken_at', '>=', now()->subSeconds($seconds)))
            ->orderBy('taken_at')
            ->get(['taken_at', 'cpu_percent', 'mem_used', 'mem_total', 'disk_percent', 'rx_bps', 'tx_bps']);

        $out = ['cpu' => [], 'memory' => [], 'disk' => [], 'rx' => [], 'tx' => []];
        if ($rows->isEmpty()) {
            return $out;
        }

        $first = $rows->first()->taken_at->getTimestamp();
        $span = max(1, $rows->last()->taken_at->getTimestamp() - $first);
        $bucket = max(1, (int) ceil($span / self::MAX_POINTS));

        $avg = fn ($values) => ($values = array_filter($values, fn ($v) => $v !== null)) ? array_sum($values) / count($values) : null;

        foreach ($rows->groupBy(fn ($r) => intdiv($r->taken_at->getTimestamp() - $first, $bucket)) as $group) {
            $x = $group->first()->taken_at->getTimestamp() * 1000;
            $mem = $group->map(fn ($r) => $r->mem_total ? $r->mem_used / $r->mem_total * 100 : null)->all();

            $out['cpu'][] = [$x, self::round($avg($group->pluck('cpu_percent')->all()))];
            $out['memory'][] = [$x, self::round($avg($mem))];
            $out['disk'][] = [$x, self::round($avg($group->pluck('disk_percent')->all()))];
            $out['rx'][] = [$x, self::round($avg($group->pluck('rx_bps')->all()), 0)];
            $out['tx'][] = [$x, self::round($avg($group->pluck('tx_bps')->all()), 0)];
        }

        return $out;
    }

    /** The last hour of CPU and memory, for the tile sparklines. */
    private function sparklines(Agent $agent): array
    {
        $rows = AgentSample::query()
            ->where('agent_id', $agent->id)
            ->where('taken_at', '>=', now()->subHour())
            ->orderBy('taken_at')
            ->get(['cpu_percent', 'mem_used', 'mem_total']);

        return [
            'cpu' => $rows->map(fn ($r) => $r->cpu_percent === null ? null : round($r->cpu_percent, 1))->values()->all(),
            'memory' => $rows->map(fn ($r) => $r->mem_total ? round($r->mem_used / $r->mem_total * 100, 1) : null)->values()->all(),
        ];
    }

    private static function round(?float $value, int $precision = 1): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    public function render()
    {
        $agent = $this->agent();
        $series = $this->series($agent);
        $sparks = $this->sparklines($agent);
        $this->dispatch('agent-series', series: $series, sparks: $sparks);

        $software = collect($agent->inventory['software'] ?? []);
        $needle = mb_strtolower(trim($this->softwareSearch));
        if ($needle !== '') {
            $software = $software->filter(fn ($s) => str_contains(mb_strtolower(($s['name'] ?? '').' '.($s['publisher'] ?? '')), $needle));
        }

        $canSeeCommands = $this->consoleAllows('agent_command', 'r');
        if ($canSeeCommands) {
            app(AgentCommands::class)->sweep($agent);
        }
        $canCommand = $this->consoleAllows('agent_command', 'rw') && Monitoring::commandsEnabled() && $agent->enabled;
        $update = AgentInstall::updateFor($agent->platformKey());

        return view('livewire.agent-detail', [
            'agent' => $agent,
            'canCommand' => $canCommand,
            'actions' => $canCommand ? array_values(array_filter(AgentCommand::ACTIONS, fn ($t) => $agent->supports($t))) : [],
            'agentUpdate' => $this->consoleAllows('agent_command', 'rw') && $update && $agent->agent_version
                && $agent->supports(AgentCommand::AGENT_UPDATE) && version_compare($update['version'], $agent->agent_version, '>') ? $update['version'] : null,
            'commands' => $canSeeCommands ? $agent->commands()->latest('id')->limit(self::COMMANDS_SHOWN)->get() : collect(),
            'rustdeskVersions' => $this->action === AgentCommand::RUSTDESK_UPGRADE ? RustDeskReleases::versions() : [],
            'series' => $series,
            'sparks' => $sparks,
            'issues' => $agent->issues(),
            'acknowledged' => $agent->acknowledgedIssues(),
            'health' => $agent->latest['health'] ?? [],
            'nic' => $agent->primaryInterface(),
            'canManage' => $this->consoleAllows('monitoring', 'rw'),
            'softwareTotal' => count($agent->inventory['software'] ?? []),
            'software' => $software->sortBy(fn ($s) => mb_strtolower($s['name'] ?? ''))->take(self::SOFTWARE_LIMIT)->values(),
            'softwareMatches' => $software->count(),
        ]);
    }
}
