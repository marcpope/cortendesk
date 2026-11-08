{{-- Onboarding polls faster so the first agent shows up soon after install. --}}
<div @if ($onboarding) wire:poll.5s @else wire:poll.15s @endif>

@if ($onboarding)
    {{-- ============================ ONBOARDING ============================ --}}
    <div class="card rd-agent-hero">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row gap-4">
                <div class="rd-agent-hero-icon"><i class="ri-pulse-line"></i></div>
                <div class="flex-grow-1">
                    <h4 class="mb-2">{{ __('monitoring.onboarding_title') }} <span class="badge bg-warning-subtle text-warning align-middle fs-12">{{ __('monitoring.beta') }}</span></h4>
                    <p class="text-muted mb-3">{{ __('monitoring.onboarding_intro') }}</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="mb-2">{{ __('monitoring.what_it_reports') }}</h6>
                            <ul class="rd-agent-list">
                                <li><i class="ri-cpu-line"></i>{{ __('monitoring.reports_cpu_memory') }}</li>
                                <li><i class="ri-hard-drive-3-line"></i>{{ __('monitoring.reports_disks') }}</li>
                                <li><i class="ri-time-line"></i>{{ __('monitoring.reports_uptime') }}</li>
                                <li><i class="ri-shield-check-line"></i>{{ __('monitoring.reports_security') }}</li>
                                <li><i class="ri-settings-3-line"></i>{{ __('monitoring.reports_services') }}</li>
                                <li><i class="ri-list-check-2"></i>{{ __('monitoring.reports_inventory') }}</li>
                                <li><i class="ri-remote-control-line"></i>{{ __('monitoring.reports_rustdesk') }}</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="mb-2">{{ __('monitoring.what_it_does_not_do') }}</h6>
                            <ul class="rd-agent-list rd-agent-list-muted">
                                <li><i class="ri-terminal-box-line"></i>{{ __('monitoring.no_commands') }}</li>
                                <li><i class="ri-door-lock-line"></i>{{ __('monitoring.no_ports') }}</li>
                                <li><i class="ri-toggle-line"></i>{{ __('monitoring.per_machine') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($canManage)
        @livewire(App\Livewire\AgentInstallGuide::class, ['embedded' => true], key('agent-install-guide'))
    @else
        <div class="alert alert-info">{{ __('monitoring.ask_an_admin') }}</div>
        <div class="card">
            <div class="card-body d-flex align-items-center gap-2 text-muted">
                <span class="spinner-grow spinner-grow-sm text-primary" role="status" aria-hidden="true"></span>
                <span>{{ __('monitoring.waiting_for_first_agent') }}</span>
            </div>
        </div>
    @endif
@else
    {{-- ============================ DASHBOARD ============================ --}}
    <div class="rd-mon-tabrow mb-3">
    <ul class="nav nav-tabs nav-bordered rd-tabbar mb-0">
        @foreach (array_filter(['overview' => ['ri-dashboard-3-line', __('common.overview')], 'machines' => ['ri-computer-line', __('monitoring.machines')], 'enrollment' => $canManage ? ['ri-key-2-line', __('monitoring.enrollment')] : null]) as $key => [$icon, $label])
            <li class="nav-item">
                <a href="#" wire:click.prevent="setTab('{{ $key }}')" class="nav-link {{ $tab === $key ? 'active' : '' }}">
                    <i class="{{ $icon }} me-1"></i><span>{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
    @if ($canManage)
        <a href="{{ route('monitoring.add') }}" class="btn btn-primary btn-sm flex-shrink-0"><i class="ri-add-line me-1"></i>{{ __('monitoring.add_agent') }}</a>
    @endif
    </div>

    @if ($tab === 'enrollment' && $canManage)
        @livewire(App\Livewire\AgentEnrollmentManager::class, [], key('agent-enroll'))
    @elseif ($tab === 'overview' && $overview)
        @include('monitoring.partials.fleet-overview', ['o' => $overview])
    @else
        <div class="rd-chiprow">
            <button type="button" class="rd-chip rd-chip-btn rd-tone-blue @if($status === 'all') rd-chip-active @endif" wire:click="filterByChip('all')">
                <i class="ri-computer-line rd-chip-icon"></i>
                <span class="rd-chip-value">{{ $totalCount }}</span>
                <span class="rd-chip-label">{{ __('monitoring.agents') }}</span>
            </button>
            <button type="button" class="rd-chip rd-chip-btn rd-tone-green @if($status === 'online') rd-chip-active @endif" wire:click="filterByChip('online')">
                <span class="rd-chip-dot"></span>
                <span class="rd-chip-value">{{ $onlineCount }}</span>
                <span class="rd-chip-label">{{ __('common.online') }}</span>
            </button>
            <button type="button" class="rd-chip rd-chip-btn rd-tone-muted @if($status === 'offline') rd-chip-active @endif" wire:click="filterByChip('offline')">
                <span class="rd-chip-dot"></span>
                <span class="rd-chip-value">{{ $offlineCount }}</span>
                <span class="rd-chip-label">{{ __('devices.offline') }}</span>
            </button>
            <button type="button" class="rd-chip rd-chip-btn {{ $attentionCount > 0 ? 'rd-tone-red' : 'rd-tone-muted' }} @if($status === 'attention') rd-chip-active @endif"
                    wire:click="filterByChip('attention')" title="{{ __('monitoring.attention_hint', ['percent' => \App\Support\Monitoring::HIGH_PERCENT]) }}">
                <i class="ri-alarm-warning-line rd-chip-icon"></i>
                <span class="rd-chip-value">{{ $attentionCount }}</span>
                <span class="rd-chip-label">{{ __('monitoring.needs_attention') }}</span>
            </button>
            @if ($disabledCount > 0)
                <button type="button" class="rd-chip rd-chip-btn rd-tone-amber @if($status === 'disabled') rd-chip-active @endif" wire:click="filterByChip('disabled')">
                    <i class="ri-pause-circle-line rd-chip-icon"></i>
                    <span class="rd-chip-value">{{ $disabledCount }}</span>
                    <span class="rd-chip-label">{{ __('monitoring.disabled') }}</span>
                </button>
            @endif
        </div>

        <div class="card">
            <div class="rd-toolbar">
                <div class="rd-toolbar-search">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control"
                               placeholder="{{ \App\Support\SearchHint::placeholder(__('monitoring.search_placeholder')) }}" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <select class="form-select rd-toolbar-filter" wire:model.live="status">
                    <option value="all">{{ __('common.all_statuses') }}</option>
                    <option value="online">{{ __('common.online') }}</option>
                    <option value="offline">{{ __('devices.offline') }}</option>
                    <option value="attention">{{ __('monitoring.needs_attention') }}</option>
                    <option value="disabled">{{ __('monitoring.disabled') }}</option>
                    <option value="rustdesk_old">{{ __('monitoring.rustdesk_outdated_filter') }}</option>
                </select>
                @if ($groups->isNotEmpty())
                    <select class="form-select rd-toolbar-filter" wire:model.live="group">
                        <option value="0">{{ __('devices.all_groups') }}</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="rd-sort-mobile d-md-none">
                    <select class="form-select" wire:change="sortBy($event.target.value)" aria-label="{{ __('monitoring.sort_by') }}">
                        @foreach (\App\Livewire\MonitoringDashboard::SORTABLE as $field)
                            <option value="{{ $field }}" @selected($sortField === $field)>{{ __('monitoring.col_'.$field) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($canCommand && ($selected !== [] || $bulkResult !== ''))
                @include('monitoring.partials.bulk-bar')
            @endif

            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        @if ($canCommand)
                            <th class="rd-selcol">
                                @php $pageIds = $agents->getCollection()->pluck('id')->map(fn ($i) => (string) $i); @endphp
                                @if ($pageIds->isNotEmpty() && $pageIds->diff($selected)->isEmpty())
                                    <input type="checkbox" class="form-check-input" checked wire:click="clearSelection" title="{{ __('devices.clear_selection') }}">
                                @else
                                    <input type="checkbox" class="form-check-input" wire:click="selectPage" title="{{ __('devices.select_every_row_on_this') }}">
                                @endif
                            </th>
                        @endif
                        <x-sortable-th field="name" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_name') }}</x-sortable-th>
                        <th>{{ __('monitoring.col_os') }}</th>
                        <x-sortable-th field="cpu" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_cpu') }}</x-sortable-th>
                        <x-sortable-th field="memory" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_memory') }}</x-sortable-th>
                        <x-sortable-th field="disk" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_disk') }}</x-sortable-th>
                        <x-sortable-th field="uptime" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_uptime') }}</x-sortable-th>
                        <x-sortable-th field="last_seen" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_last_seen') }}</x-sortable-th>
                        <x-sortable-th field="version" :sort="$sortField" :dir="$sortDirection">{{ __('monitoring.col_version') }}</x-sortable-th>
                        <th>{{ __('common.status') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($agents as $agent)
                        @php $disk = $agent->fullestDisk(); @endphp
                        <tr wire:key="a{{ $agent->id }}">
                            @if ($canCommand)
                                <td class="rd-selcol"><input type="checkbox" class="form-check-input" value="{{ $agent->id }}" wire:model.live="selected" aria-label="{{ $agent->displayName() }}"></td>
                            @endif
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-platform-icon :platform="$agent->platform()" size="fs-20"/>
                                    <div class="min-width-0">
                                        <a href="{{ $agent->url() }}" class="fw-medium text-truncate d-block">{{ $agent->displayName() }}</a>
                                        @if ($agent->device)
                                            <span class="fs-12 text-muted rd-mono">{{ $agent->device->rustdesk_id }}</span>
                                        @else
                                            <span class="fs-12 text-muted">{{ __('monitoring.not_linked') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="fs-13" title="{{ $agent->os_version }}">{{ \Illuminate\Support\Str::limit($agent->os_version ?: '—', 28) }}</td>
                            <td style="min-width: 120px;">@include('monitoring.partials.meter', ['percent' => $agent->cpuPercent()])</td>
                            <td style="min-width: 120px;">@include('monitoring.partials.meter', ['percent' => $agent->memoryPercent()])</td>
                            <td style="min-width: 140px;">@include('monitoring.partials.meter', ['percent' => $disk['percent'] ?? null, 'title' => $disk ? $disk['mount'] : null])</td>
                            <td class="rd-nowrap">{{ \App\Support\Monitoring::duration($agent->uptimeSeconds()) }}</td>
                            <td class="rd-nowrap"><span title="{{ $agent->last_seen_at }}">{{ $agent->last_seen_at?->diffForHumans() ?? __('common.never') }}</span></td>
                            <td>@if ($agent->agent_version)<span class="badge bg-secondary-subtle text-secondary">{{ $agent->agent_version }}</span>@else — @endif</td>
                            <td>@include('monitoring.partials.agent-state', ['agent' => $agent])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canCommand ? 10 : 9 }}" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-pulse-line"></i></div>
                                    <p class="rd-empty-title">{{ __('monitoring.no_agents_match') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-md-none rd-cardlist">
                @forelse ($agents as $agent)
                    @php $disk = $agent->fullestDisk(); @endphp
                    <div class="rd-mini" wire:key="am{{ $agent->id }}">
                        <div class="rd-mini-head">
                            <div class="d-flex align-items-center gap-2 min-width-0">
                                @if ($canCommand)
                                    <input type="checkbox" class="form-check-input flex-shrink-0 m-0" value="{{ $agent->id }}" wire:model.live="selected" aria-label="{{ $agent->displayName() }}">
                                @endif
                                <x-platform-icon :platform="$agent->platform()" size="fs-22"/>
                                <div class="min-width-0">
                                    <a href="{{ $agent->url() }}" class="rd-mini-title text-truncate">{{ $agent->displayName() }}</a>
                                    <span class="rd-mini-sub text-truncate">{{ $agent->os_version ?: '—' }}</span>
                                </div>
                            </div>
                            @include('monitoring.partials.agent-state', ['agent' => $agent])
                        </div>
                        <div class="rd-agent-mini-meters">
                            <div><span class="rd-agent-mini-label">{{ __('monitoring.col_cpu') }}</span>@include('monitoring.partials.meter', ['percent' => $agent->cpuPercent()])</div>
                            <div><span class="rd-agent-mini-label">{{ __('monitoring.col_memory') }}</span>@include('monitoring.partials.meter', ['percent' => $agent->memoryPercent()])</div>
                            <div><span class="rd-agent-mini-label">{{ __('monitoring.col_disk') }}</span>@include('monitoring.partials.meter', ['percent' => $disk['percent'] ?? null])</div>
                        </div>
                        <div class="rd-mini-foot">
                            <span class="rd-mini-sub min-width-0">
                                {{ __('monitoring.up_for', ['time' => \App\Support\Monitoring::duration($agent->uptimeSeconds())]) }}
                                · <span class="text-nowrap">{{ $agent->last_seen_at?->diffForHumans(short: true) ?? __('common.never') }}</span>
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-pulse-line"></i></div>
                        <p class="rd-empty-title">{{ __('monitoring.no_agents_match') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="rd-tablefoot">
                <span>{{ __('common.showing_from_to_of_total', ['from' => $agents->firstItem() ?? 0, 'to' => $agents->lastItem() ?? 0, 'total' => $agents->total()]) }}</span>
                {{ $agents->links() }}
            </div>
        </div>
    @endif
@endif

</div>
