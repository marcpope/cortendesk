{{-- Monitoring > Overview: the fleet at a glance. $o comes from MonitoringDashboard::overview(). --}}
@php
    use App\Support\Monitoring;

    $st = $o['states'];
    $live = $st['healthy'] + $st['attention'];
    $pct = fn ($n, $of) => $of > 0 ? round($n / $of * 100) : 0;
    $tone = fn ($v) => $v === null ? 'rd-tone-muted' : ($v >= Monitoring::HIGH_PERCENT ? 'rd-tone-red' : ($v >= 75 ? 'rd-tone-amber' : 'rd-tone-green'));
    $stateTones = ['healthy' => 'rd-tone-green', 'attention' => 'rd-tone-red', 'offline' => 'rd-tone-muted', 'disabled' => 'rd-tone-amber'];
    $stateFilter = ['healthy' => 'online', 'attention' => 'attention', 'offline' => 'offline', 'disabled' => 'disabled'];
@endphp

{{-- ============================== TILES ============================== --}}
<div class="rd-stat-grid rd-mon-stat-grid">
    <a href="#" wire:click.prevent="showMachines('all')" class="card text-reset card-hover">
        <div class="card-body"><div class="rd-stat rd-tone-blue">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-computer-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.machines') }}</div><div class="rd-stat-value">{{ $totalCount }}</div></div>
            </div>
            <div class="rd-stat-foot"><i class="rd-dot text-success"></i>{{ __('monitoring.n_online', ['count' => $onlineCount]) }} · {{ __('monitoring.n_offline', ['count' => $offlineCount]) }}</div>
        </div></div>
    </a>
    <a href="#" wire:click.prevent="showMachines('attention')" class="card text-reset card-hover">
        <div class="card-body"><div class="rd-stat {{ $attentionCount > 0 ? 'rd-tone-red' : 'rd-tone-green' }}">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-alarm-warning-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.needs_attention') }}</div><div class="rd-stat-value">{{ $attentionCount }}</div></div>
            </div>
            <div class="rd-stat-foot">{{ $attentionCount > 0 ? __('monitoring.pct_of_online', ['pct' => $pct($attentionCount, $live)]) : __('monitoring.all_clear') }}</div>
        </div></div>
    </a>
    <a href="#" wire:click.prevent="showMachines('online', 'cpu')" class="card text-reset card-hover">
        <div class="card-body"><div class="rd-stat {{ $tone($o['avgCpu']) }}">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-cpu-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.avg_cpu') }}</div><div class="rd-stat-value">{{ $o['avgCpu'] === null ? '—' : round($o['avgCpu']).'%' }}</div></div>
            </div>
            <div class="rd-stat-foot text-truncate">@if ($o['topCpu']->isNotEmpty()){{ __('monitoring.busiest', ['name' => $o['topCpu'][0]['agent']->displayName(), 'percent' => round($o['topCpu'][0]['value'])]) }}@else — @endif</div>
        </div></div>
    </a>
    <a href="#" wire:click.prevent="showMachines('online', 'memory')" class="card text-reset card-hover">
        <div class="card-body"><div class="rd-stat {{ $tone($o['avgMemory']) }}">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-database-2-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.avg_memory') }}</div><div class="rd-stat-value">{{ $o['avgMemory'] === null ? '—' : round($o['avgMemory']).'%' }}</div></div>
            </div>
            <div class="rd-stat-foot text-truncate">@if ($o['topMemory']->isNotEmpty()){{ __('monitoring.busiest', ['name' => $o['topMemory'][0]['agent']->displayName(), 'percent' => round($o['topMemory'][0]['value'])]) }}@else — @endif</div>
        </div></div>
    </a>
    <a href="#" wire:click.prevent="showMachines('online', 'disk')" class="card text-reset card-hover">
        <div class="card-body"><div class="rd-stat {{ $o['lowDisk'] > 0 ? 'rd-tone-amber' : 'rd-tone-green' }}">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-hard-drive-2-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.low_disk') }}</div><div class="rd-stat-value">{{ $o['lowDisk'] }}</div></div>
            </div>
            <div class="rd-stat-foot">{{ __('monitoring.under_free', ['percent' => \App\Models\Agent::LOW_FREE_PERCENT]) }}</div>
        </div></div>
    </a>
    <div class="card">
        <div class="card-body"><div class="rd-stat {{ $o['updatesMissing'] > 0 || $o['rebootPending'] > 0 ? 'rd-tone-amber' : 'rd-tone-green' }}">
            <div class="rd-stat-head">
                <span class="rd-stat-icon"><i class="ri-download-cloud-2-line"></i></span>
                <div class="min-width-0"><div class="rd-stat-label">{{ __('monitoring.updates_missing') }}</div><div class="rd-stat-value">{{ $o['updatesMissing'] }}</div></div>
            </div>
            <div class="rd-stat-foot">{{ trans_choice(__('monitoring.reboots_pending'), $o['rebootPending'], ['count' => $o['rebootPending']]) }}</div>
        </div></div>
    </div>
</div>

{{-- ========================= TREND + HEALTH ========================= --}}
<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100 mb-0">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0 flex-grow-1">{{ __('monitoring.fleet_load') }}</h5>
                <span class="fs-12 text-muted">{{ __('monitoring.fleet_load_hint') }}</span>
            </div>
            <div class="card-body">
                <div wire:ignore class="rd-fleet-charts" data-trend="{{ json_encode($o['trend']) }}" data-states="{{ json_encode($st) }}"
                     data-labels="{{ json_encode(['cpu' => __('monitoring.avg_cpu'), 'memory' => __('monitoring.avg_memory'), 'healthy' => __('monitoring.state_healthy'), 'attention' => __('monitoring.needs_attention'), 'offline' => __('devices.offline'), 'disabled' => __('monitoring.disabled')]) }}">
                    <div data-chart="trend"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100 mb-0">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.fleet_health') }}</h5></div>
            <div class="card-body">
                <div class="rd-mon-donut">
                    <div class="rd-mon-donut-chart" wire:ignore data-chart="health"></div>
                    <ul class="rd-legend">
                        @foreach ($st as $state => $n)
                            <li class="rd-legend-item {{ $stateTones[$state] }}">
                                <a href="#" wire:click.prevent="showMachines('{{ $stateFilter[$state] }}')" class="rd-legend-link">
                                    <span class="rd-legend-swatch"></span>
                                    <span class="rd-legend-label">{{ $state === 'healthy' ? __('monitoring.state_healthy') : ($state === 'attention' ? __('monitoring.needs_attention') : ($state === 'offline' ? __('devices.offline') : __('monitoring.disabled'))) }}</span>
                                    <span class="rd-legend-count">{{ $n }}</span>
                                    <span class="rd-legend-pct">{{ $pct($n, $totalCount) }}%</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ============================ TOP LISTS ============================ --}}
<div class="row g-3 mb-3">
    @foreach (['topCpu' => [__('monitoring.busiest_cpu'), 'cpu'], 'topMemory' => [__('monitoring.most_memory'), 'memory'], 'topDisk' => [__('monitoring.fullest_disks'), 'disk']] as $key => [$title, $sort])
        <div class="col-lg-4">
            <div class="card h-100 mb-0">
                <div class="card-header d-flex align-items-center">
                    <h5 class="card-title mb-0 flex-grow-1">{{ $title }}</h5>
                    <a href="#" wire:click.prevent="showMachines('online', '{{ $sort }}')" class="fs-13">{{ __('monitoring.view_all') }}<i class="ri-arrow-right-s-line"></i></a>
                </div>
                <div class="card-body">
                    @if ($o[$key]->isEmpty())
                        <p class="text-muted mb-0">{{ __('monitoring.no_online_agents') }}</p>
                    @else
                        <ul class="rd-barlist">
                            @foreach ($o[$key] as $row)
                                @php $a = $row['agent']; @endphp
                                <li class="rd-barlist-item {{ $tone($row['value']) }}">
                                    <div class="rd-barlist-head">
                                        <a href="{{ $a->url() }}" class="rd-barlist-label text-reset">{{ $a->displayName() }}@if ($key === 'topDisk') <span class="text-muted">{{ $a->fullestDisk()['mount'] }}</span>@endif</a>
                                        <span class="rd-barlist-count">{{ round($row['value']) }}%</span>
                                    </div>
                                    <div class="rd-barlist-track"><div class="rd-barlist-fill" style="width: {{ min(100, $row['value']) }}%"></div></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- ======================= ATTENTION + SECURITY ======================= --}}
<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card h-100 mb-0">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0 flex-grow-1">{{ __('monitoring.needs_attention') }}</h5>
                @if ($o['attention']->isNotEmpty())
                    <a href="#" wire:click.prevent="showMachines('attention')" class="fs-13">{{ __('monitoring.view_all') }}<i class="ri-arrow-right-s-line"></i></a>
                @endif
            </div>
            @if ($o['attention']->isEmpty())
                <div class="card-body">
                    <div class="rd-empty py-3">
                        <div class="rd-empty-icon"><i class="ri-checkbox-circle-line text-success"></i></div>
                        <p class="rd-empty-title">{{ __('monitoring.nothing_needs_attention') }}</p>
                    </div>
                </div>
            @else
                <ul class="rd-mon-feed">
                    @foreach ($o['attention'] as $row)
                        @php $a = $row['agent']; @endphp
                        <li>
                            <x-platform-icon :platform="$a->platform()" size="fs-20"/>
                            <div class="min-width-0 flex-grow-1">
                                <a href="{{ $a->url() }}" class="fw-medium text-reset">{{ $a->displayName() }}</a>
                                <div class="rd-mon-feed-issues">
                                    @foreach ($row['issues'] as $issue)
                                        <span class="rd-mon-issue"><span class="rd-mon-dot {{ $issue['level'] === 'danger' ? 'rd-mon-dot-bad' : 'rd-mon-dot-warn' }}"></span>{{ $issue['text'] }}</span>
                                    @endforeach
                                    @foreach ($row['high'] as $label)
                                        <span class="rd-mon-issue"><span class="rd-mon-dot rd-mon-dot-bad"></span>{{ $label }}</span>
                                    @endforeach
                                </div>
                            </div>
                            <a href="{{ $a->url() }}" class="btn btn-sm btn-light flex-shrink-0" aria-label="{{ __('monitoring.open_agent') }}"><i class="ri-arrow-right-s-line"></i></a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100 mb-0">
            <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.security_posture') }}</h5></div>
            <div class="card-body">
                <ul class="rd-barlist">
                    @foreach (['antivirus' => __('monitoring.antivirus_current'), 'firewall' => __('monitoring.firewall_on'), 'encryption' => __('monitoring.encrypted'), 'smart' => __('monitoring.smart_ok'), 'updates' => __('monitoring.fully_updated')] as $key => $label)
                        @php
                            $c = $o['security'][$key];
                            $p = $c['of'] > 0 ? round($c['ok'] / $c['of'] * 100) : null;
                        @endphp
                        <li class="rd-barlist-item {{ $p === null ? 'rd-tone-muted' : ($p === 100 ? 'rd-tone-green' : ($p >= 80 ? 'rd-tone-amber' : 'rd-tone-red')) }}">
                            <div class="rd-barlist-head">
                                <span class="rd-barlist-label">{{ $label }}</span>
                                <span class="rd-barlist-count">{{ $c['of'] > 0 ? $c['ok'].' / '.$c['of'] : '—' }}</span>
                                <span class="rd-barlist-pct">{{ $p === null ? '' : $p.'%' }}</span>
                            </div>
                            <div class="rd-barlist-track"><div class="rd-barlist-fill" style="width: {{ $p ?? 0 }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
                <div class="fs-12 text-muted mt-3">{{ __('monitoring.security_hint') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ============================== MIX ============================== --}}
<div class="row g-3 mb-3">
    @foreach (['osMix' => __('monitoring.operating_systems'), 'rustdeskVersions' => __('monitoring.rustdesk_versions'), 'agentVersions' => __('monitoring.agent_versions')] as $key => $title)
        @php $total = $o[$key]->sum(); @endphp
        <div class="col-md-6 col-xl-3">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ $title }}</h5></div>
                <div class="card-body">
                    @if ($total === 0)
                        <p class="text-muted mb-0">—</p>
                    @else
                        <ul class="rd-barlist">
                            @foreach ($o[$key]->take(6) as $label => $n)
                                <li class="rd-barlist-item {{ $key === 'rustdeskVersions' && $o['rustdeskLatest'] && $label !== '?' && version_compare($label, $o['rustdeskLatest'], '<') ? 'rd-tone-amber' : 'rd-tone-blue' }}">
                                    <div class="rd-barlist-head">
                                        <span class="rd-barlist-label">{{ $label }}</span>
                                        <span class="rd-barlist-count">{{ $n }}</span>
                                        <span class="rd-barlist-pct">{{ $pct($n, $total) }}%</span>
                                    </div>
                                    <div class="rd-barlist-track"><div class="rd-barlist-fill" style="width: {{ $pct($n, $total) }}%"></div></div>
                                </li>
                            @endforeach
                        </ul>
                        @if ($key === 'rustdeskVersions' && $o['rustdeskOutdated'] > 0)
                            <a href="#" wire:click.prevent="showMachines('rustdesk_old')" class="fs-13 d-inline-block mt-2">
                                {{ __('monitoring.rustdesk_outdated_link', ['count' => $o['rustdeskOutdated'], 'version' => $o['rustdeskLatest']]) }}<i class="ri-arrow-right-s-line"></i>
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endforeach
    <div class="col-md-6 col-xl-3">
        <div class="card h-100 mb-0">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0 flex-grow-1">{{ __('monitoring.recently_offline') }}</h5>
                @if ($o['offline']->isNotEmpty())
                    <a href="#" wire:click.prevent="showMachines('offline')" class="fs-13">{{ __('monitoring.view_all') }}<i class="ri-arrow-right-s-line"></i></a>
                @endif
            </div>
            <div class="card-body">
                @forelse ($o['offline'] as $a)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <x-platform-icon :platform="$a->platform()" size="fs-18"/>
                        <a href="{{ $a->url() }}" class="text-reset text-truncate flex-grow-1">{{ $a->displayName() }}</a>
                        <span class="fs-12 text-muted text-nowrap" title="{{ $a->last_seen_at }}">{{ $a->last_seen_at?->diffForHumans(short: true) ?? __('common.never') }}</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">{{ __('monitoring.all_reporting') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
