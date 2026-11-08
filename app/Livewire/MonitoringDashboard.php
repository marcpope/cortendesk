<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentSample;
use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\User;
use App\Services\AgentCommands;
use App\Support\Monitoring;
use App\Support\RustDeskReleases;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Monitoring: the agent list with health tiles, or onboarding while the user
 * can see no agents yet (docs/monitoring/SPEC.md "Console UI").
 *
 * Filtering and sorting read `latest` (JSON), so they run in PHP over the
 * visible agents. Fine for the hundreds a self-hosted console has; past that,
 * promote the sort columns out of JSON.
 */
class MonitoringDashboard extends Component
{
    use AuthorizesConsole, WithPagination;

    public const TABS = ['overview', 'machines', 'enrollment'];

    /** Rows in each "top" list on the overview. */
    public const TOP = 5;

    /** Trend bucket on the overview, seconds. */
    public const TREND_BUCKET = 1800;

    public const SORTABLE = ['name', 'cpu', 'memory', 'disk', 'uptime', 'last_seen', 'version'];

    public const STATUSES = ['all', 'online', 'offline', 'attention', 'disabled', 'rustdesk_old'];

    public const PER_PAGE = 25;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 0)]
    public int $group = 0;

    public string $sortField = 'name';

    public string $sortDirection = 'asc';

    protected string $paginationTheme = 'bootstrap';

    /** Agent ids picked for a bulk command (strings from the checkboxes). */
    public array $selected = [];

    /** Bulk command being set up: rustdesk.upgrade, os.update, reboot, or none. */
    public string $bulkAction = '';

    public string $bulkVersion = '';

    public bool $bulkReboot = false;

    public int $bulkDelay = 0;

    /** One-line outcome of the last bulk command. */
    public string $bulkResult = '';

    public function boot(): void
    {
        // Livewire updates bypass route middleware.
        abort_unless(Monitoring::enabled(), 404);
    }

    public function mount(): void
    {
        $this->authorizeConsole('monitoring', 'r');

        if (! in_array($this->tab, self::TABS, true) || ($this->tab === 'enrollment' && ! $this->consoleAllows('monitoring', 'rw'))) {
            $this->tab = 'overview';
        }
        if (! in_array($this->status, self::STATUSES, true)) {
            $this->status = 'all';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
        if ($this->tab === 'enrollment' && ! $this->consoleAllows('monitoring', 'rw')) {
            $this->tab = 'overview';
        }
    }

    public function filterByChip(string $status): void
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : 'all';
        $this->resetPage();
    }

    /** An overview tile or list opens the machine list, filtered. */
    public function showMachines(string $status = 'all', string $sort = ''): void
    {
        $this->tab = 'machines';
        $this->filterByChip($status);
        if (in_array($sort, self::SORTABLE, true)) {
            $this->sortField = $sort;
            $this->sortDirection = 'desc';
        }
    }

    public function updatedPaginators(): void
    {
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->bulkAction = '';
    }

    /** Header checkbox: every machine on the current page. */
    public function selectPage(): void
    {
        $this->selected = $this->sorted($this->filtered($this->visibleAgents()))
            ->forPage($this->getPage(), self::PER_PAGE)
            ->map(fn (Agent $a) => (string) $a->id)->values()->all();
        $this->bulkResult = '';
    }

    public function openBulk(string $type): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        if (in_array($type, AgentCommand::ACTIONS, true)) {
            $this->bulkAction = $type;
            $this->bulkResult = '';
            $this->resetErrorBag();
            if ($type === AgentCommand::RUSTDESK_UPGRADE) {
                $this->bulkVersion = RustDeskReleases::latest() ?? '';
            }
        }
    }

    /**
     * Queue the bulk command on every selected machine the user can see.
     * Machines that can't take it (disabled, older agent, no installer) are
     * counted as skipped, not errors.
     */
    public function bulkQueue(AgentCommands $commands): void
    {
        $this->authorizeConsole('agent_command', 'rw');
        if (! in_array($this->bulkAction, AgentCommand::ACTIONS, true)) {
            return;
        }
        if (! Monitoring::commandsEnabled()) {
            $this->addError('bulk', __('monitoring.cmd_error_disabled'));

            return;
        }
        $input = match ($this->bulkAction) {
            AgentCommand::RUSTDESK_UPGRADE => ['version' => $this->bulkVersion],
            AgentCommand::OS_UPDATE => ['reboot' => $this->bulkReboot],
            AgentCommand::REBOOT => ['delay' => in_array($this->bulkDelay, AgentDetail::REBOOT_DELAYS, true) ? $this->bulkDelay : 0],
        };

        $ids = array_map('intval', $this->selected);
        $agents = $this->visibleAgents()->filter(fn (Agent $a) => in_array($a->id, $ids, true));
        $batch = (string) Str::ulid();
        $queued = $skipped = 0;
        $summary = null;
        foreach ($agents as $agent) {
            try {
                $command = $commands->queue($agent, $this->bulkAction, $input, auth()->user(), $batch);
                $summary ??= $command->summary();
                $queued++;
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }

        if ($queued > 0) {
            ConsoleAudit::record('agent.command', 'Queued "'.$summary.'" on '.$queued.' machine(s)', 'agent', null);
        }
        $this->bulkResult = trim(__('monitoring.bulk_result', ['queued' => $queued]).' '.($skipped ? __('monitoring.bulk_skipped', ['skipped' => $skipped]) : ''));
        $this->selected = [];
        $this->bulkAction = '';
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE, true)) {
            return;
        }
        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    /**
     * Needs attention: online with CPU, memory or the fullest disk at or
     * above the threshold, or anything the agent page's banner would list.
     */
    public static function needsAttention(Agent $agent): bool
    {
        if (! $agent->enabled || ! $agent->isOnline()) {
            return false;
        }

        return ($agent->cpuPercent() ?? 0) >= Monitoring::HIGH_PERCENT
            || ($agent->memoryPercent() ?? 0) >= Monitoring::HIGH_PERCENT
            || ($agent->fullestDisk()['percent'] ?? 0) >= Monitoring::HIGH_PERCENT
            || $agent->issues() !== [];
    }

    /** RustDesk installed and older than the latest stable release. */
    public static function rustdeskOutdated(Agent $agent, ?string $latest): bool
    {
        $version = $agent->latest['rustdesk']['version'] ?? null;

        return $latest && $version && ($agent->latest['rustdesk']['installed'] ?? false) && version_compare($version, $latest, '<');
    }

    /** Device groups the user can see, for filters and token pickers. */
    public static function groupsFor(User $user): Collection
    {
        return DeviceGroup::query()->orderBy('name')
            ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('id', $user->accessibleDeviceGroupIds() ?: [0]))
            ->get(['id', 'name']);
    }

    /** @return Collection<int, Agent> */
    private function visibleAgents(): Collection
    {
        return Agent::query()
            ->visibleTo(auth()->user())
            ->with(['device:id,rustdesk_id,alias,hostname,device_group_id', 'group:id,name'])
            ->get();
    }

    private function filtered(Collection $agents): Collection
    {
        $needle = mb_strtolower(trim($this->search));
        $latest = $this->status === 'rustdesk_old' ? RustDeskReleases::latest() : null;

        return $agents
            ->when($needle !== '', fn ($c) => $c->filter(fn (Agent $a) => str_contains(mb_strtolower(implode(' ', [
                $a->hostname, $a->rustdesk_id, $a->device?->alias, $a->device?->rustdesk_id, $a->os_version, $a->last_ip,
            ])), $needle)))
            ->when($this->group > 0, fn ($c) => $c->filter(fn (Agent $a) => ($a->device?->device_group_id ?? $a->device_group_id) === $this->group))
            ->filter(fn (Agent $a) => match ($this->status) {
                'online' => $a->enabled && $a->isOnline(),
                'offline' => $a->enabled && ! $a->isOnline(),
                'attention' => self::needsAttention($a),
                'disabled' => ! $a->enabled,
                'rustdesk_old' => self::rustdeskOutdated($a, $latest),
                default => true,
            });
    }

    private function sorted(Collection $agents): Collection
    {
        $key = fn (Agent $a) => match ($this->sortField) {
            'cpu' => $a->cpuPercent() ?? -1,
            'memory' => $a->memoryPercent() ?? -1,
            'disk' => $a->fullestDisk()['percent'] ?? -1,
            'uptime' => $a->uptimeSeconds() ?? -1,
            'last_seen' => $a->last_seen_at?->getTimestamp() ?? 0,
            'version' => (string) $a->agent_version,
            default => mb_strtolower($a->displayName()),
        };

        return $agents->sortBy($key, SORT_NATURAL, $this->sortDirection === 'desc')->values();
    }

    /**
     * Fleet numbers for the overview tab, from each agent's last report.
     * "Reporting" counts only agents that sent the check at all, so a fleet
     * of Linux servers is not shown as 0% antivirus.
     */
    private function overview(Collection $all): array
    {
        $live = $all->filter(fn (Agent $a) => $a->enabled && $a->isOnline())->values();
        $avg = fn (Collection $c) => $c->isEmpty() ? null : round($c->avg(), 1);

        $health = $all->mapWithKeys(fn (Agent $a) => [$a->id => [
            'state' => ! $a->enabled ? 'disabled' : (! $a->isOnline() ? 'offline' : (self::needsAttention($a) ? 'attention' : 'healthy')),
        ]]);
        $states = ['healthy' => 0, 'attention' => 0, 'offline' => 0, 'disabled' => 0];
        foreach ($health as $h) {
            $states[$h['state']]++;
        }

        $top = fn (callable $value) => $live
            ->map(fn (Agent $a) => ['agent' => $a, 'value' => $value($a)])
            ->filter(fn ($r) => $r['value'] !== null)
            ->sortByDesc('value')->take(self::TOP)->values();

        // One row per check: how many reporting agents pass it.
        $check = function (callable $reported, callable $passes) use ($live) {
            $reporting = $live->filter($reported);

            return ['ok' => $reporting->filter($passes)->count(), 'of' => $reporting->count()];
        };
        $h = fn (Agent $a) => $a->latest['health'] ?? [];
        $security = [
            'antivirus' => $check(fn ($a) => isset($h($a)['antivirus']), fn ($a) => collect($h($a)['antivirus'])->contains(fn ($v) => ($v['enabled'] ?? true) && ($v['up_to_date'] ?? true))),
            'firewall' => $check(fn ($a) => isset($h($a)['firewall']), fn ($a) => $h($a)['firewall'] === true),
            'encryption' => $check(fn ($a) => isset($h($a)['encryption']), fn ($a) => collect($h($a)['encryption'])->every(fn ($v) => $v['encrypted'] ?? false)),
            'smart' => $check(fn ($a) => isset($h($a)['smart']), fn ($a) => collect($h($a)['smart'])->every(fn ($v) => $v['healthy'] ?? true)),
            'updates' => $check(fn ($a) => isset($h($a)['updates']['missing']), fn ($a) => ($h($a)['updates']['missing'] ?? 0) === 0 && ($h($a)['updates']['failed'] ?? 0) === 0),
        ];

        $count = fn (callable $key) => $all->map($key)->filter()->countBy()->sortDesc();

        return [
            'avgCpu' => $avg($live->map(fn (Agent $a) => $a->cpuPercent())->filter(fn ($v) => $v !== null)),
            'avgMemory' => $avg($live->map(fn (Agent $a) => $a->memoryPercent())->filter(fn ($v) => $v !== null)),
            'lowDisk' => $live->filter(fn (Agent $a) => collect($a->disks())->contains(fn ($d) => 100 - $d['percent'] < Agent::LOW_FREE_PERCENT))->count(),
            'rebootPending' => $live->filter(fn (Agent $a) => $a->latest['reboot_pending'] ?? false)->count(),
            'updatesMissing' => $live->sum(fn (Agent $a) => (int) ($h($a)['updates']['missing'] ?? 0)),
            'states' => $states,
            'topCpu' => $top(fn (Agent $a) => $a->cpuPercent()),
            'topMemory' => $top(fn (Agent $a) => $a->memoryPercent()),
            'topDisk' => $top(fn (Agent $a) => $a->fullestDisk()['percent'] ?? null),
            'security' => $security,
            'attention' => $live->map(fn (Agent $a) => ['agent' => $a, 'issues' => $a->issues(), 'high' => self::highUsage($a)])
                ->filter(fn ($r) => $r['issues'] || $r['high'])
                ->sortByDesc(fn ($r) => collect($r['issues'])->where('level', 'danger')->count() * 10 + count($r['issues']) + count($r['high']))
                ->take(8)->values(),
            'offline' => $all->filter(fn (Agent $a) => $a->enabled && ! $a->isOnline())->sortByDesc('last_seen_at')->take(self::TOP)->values(),
            'osMix' => $count(fn (Agent $a) => self::osFamily($a)),
            'agentVersions' => $count(fn (Agent $a) => $a->agent_version),
            'rustdeskVersions' => $count(fn (Agent $a) => ($a->latest['rustdesk']['installed'] ?? false) ? ($a->latest['rustdesk']['version'] ?: '?') : null),
            'rustdeskLatest' => $latestRustdesk = RustDeskReleases::latest(),
            'rustdeskOutdated' => $all->filter(fn (Agent $a) => self::rustdeskOutdated($a, $latestRustdesk))->count(),
            'trend' => $this->trend($live->pluck('id')->all()),
        ];
    }

    /** CPU, memory or fullest disk at or above the threshold, as labels. */
    private static function highUsage(Agent $agent): array
    {
        $out = [];
        if (($agent->cpuPercent() ?? 0) >= Monitoring::HIGH_PERCENT) {
            $out[] = __('monitoring.high_cpu', ['percent' => round($agent->cpuPercent())]);
        }
        if (($agent->memoryPercent() ?? 0) >= Monitoring::HIGH_PERCENT) {
            $out[] = __('monitoring.high_memory', ['percent' => round($agent->memoryPercent())]);
        }

        return $out;
    }

    /** "Windows Server", "Windows", "macOS", "Ubuntu" ... for the OS mix. */
    private static function osFamily(Agent $agent): string
    {
        $v = (string) $agent->os_version;

        return match (true) {
            str_contains($v, 'Windows Server') => 'Windows Server',
            $agent->os === 'windows' => 'Windows',
            $agent->os === 'darwin' => 'macOS',
            $v !== '' => strtok($v, ' '),
            default => 'Linux',
        };
    }

    /**
     * Average CPU and memory across the given agents over the last 24 hours,
     * in 30-minute buckets. Cached for a minute: the page polls every 15 s.
     *
     * @param  array<int,int>  $ids
     */
    private function trend(array $ids): array
    {
        if ($ids === []) {
            return ['cpu' => [], 'memory' => []];
        }
        sort($ids);

        return Cache::remember('monitoring.trend.'.md5(implode(',', $ids)), 60, function () use ($ids) {
            $epoch = match (DB::connection()->getDriverName()) {
                'sqlite' => "CAST(strftime('%s', taken_at) AS INTEGER)",
                'pgsql' => 'EXTRACT(EPOCH FROM taken_at)',
                default => 'UNIX_TIMESTAMP(taken_at)',
            };
            $rows = AgentSample::query()
                ->whereIn('agent_id', $ids)
                ->where('taken_at', '>=', now()->subDay())
                ->selectRaw("FLOOR({$epoch} / ".self::TREND_BUCKET.') as bucket, MIN(taken_at) as first_at, AVG(cpu_percent) as cpu, AVG(CASE WHEN mem_total > 0 THEN mem_used * 100.0 / mem_total END) as mem')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->toBase()
                ->get();

            $point = fn ($r, $v) => [Carbon::parse($r->first_at)->getTimestamp() * 1000, $v === null ? null : round((float) $v, 1)];

            return [
                'cpu' => $rows->map(fn ($r) => $point($r, $r->cpu))->all(),
                'memory' => $rows->map(fn ($r) => $point($r, $r->mem))->all(),
            ];
        });
    }

    public function render()
    {
        $all = $this->visibleAgents();
        $canManage = $this->consoleAllows('monitoring', 'rw');

        $rows = $this->sorted($this->filtered($all));
        $page = $this->getPage();
        $agents = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
        );

        $enabled = $all->where('enabled', true);
        $overview = ! $all->isEmpty() && $this->tab === 'overview' ? $this->overview($all) : null;
        if ($overview) {
            $this->dispatch('fleet-charts', trend: $overview['trend'], states: $overview['states']);
        }

        $canCommand = $this->consoleAllows('agent_command', 'rw') && Monitoring::commandsEnabled();

        return view('livewire.monitoring-dashboard', [
            'onboarding' => $all->isEmpty(),
            'agents' => $agents,
            'canManage' => $canManage,
            'canCommand' => $canCommand,
            'releaseVersions' => $this->bulkAction === AgentCommand::RUSTDESK_UPGRADE ? RustDeskReleases::versions() : [],
            'totalCount' => $all->count(),
            'onlineCount' => $enabled->filter(fn (Agent $a) => $a->isOnline())->count(),
            'offlineCount' => $enabled->reject(fn (Agent $a) => $a->isOnline())->count(),
            'attentionCount' => $all->filter(fn (Agent $a) => self::needsAttention($a))->count(),
            'disabledCount' => $all->where('enabled', false)->count(),
            'groups' => self::groupsFor(auth()->user()),
            'overview' => $overview,
        ]);
    }
}
