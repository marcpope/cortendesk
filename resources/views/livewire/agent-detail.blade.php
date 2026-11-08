@php
    use App\Support\Monitoring;
    use Illuminate\Support\Number;

    $bytes = fn ($v) => $v === null ? '—' : Number::fileSize((int) $v, 1);
    $latest = $agent->latest ?? [];
    $inv = $agent->inventory ?? [];
    $hw = $inv['hardware'] ?? [];
    $os = $inv['os'] ?? [];
    $rd = $latest['rustdesk'] ?? [];
    $updates = $health['updates'] ?? null;
    // SMART is per physical drive, volumes are logical. With one drive every
    // volume sits on it, so its badge goes on each; otherwise drives get
    // their own list.
    $smartDrives = array_values((array) ($health['smart'] ?? []));
    $soleDrive = count($smartDrives) === 1 ? $smartDrives[0] : null;
    $sampledAt = $agent->last_seen_at;
    $chartLabels = [
        'cpu' => __('monitoring.col_cpu'), 'memory' => __('monitoring.col_memory'), 'disk' => __('monitoring.fullest_disk'),
        'rx' => __('monitoring.received'), 'tx' => __('monitoring.sent'),
    ];
    $dot = fn (?bool $ok) => $ok === null ? 'rd-mon-dot-muted' : ($ok ? 'rd-mon-dot-ok' : 'rd-mon-dot-bad');
@endphp
<div wire:poll.60s class="rd-mon">

    {{-- ============================== HEADER ============================== --}}
    @if ($embedded)
        <div class="rd-mon-strip">
            <span class="text-muted fs-13">
                {{ __('monitoring.agent') }} {{ $agent->hostname }} · v{{ $agent->agent_version ?: '?' }}
                · <span title="{{ $sampledAt }}">{{ __('monitoring.sampled', ['when' => $sampledAt?->diffForHumans() ?? __('common.never')]) }}</span>
                @unless ($actions || $agentUpdate)· <i class="ri-information-line"></i> {{ __('monitoring.read_only_telemetry') }}@endunless
                @if ($acknowledged) · <button type="button" class="btn btn-link p-0 fs-13 align-baseline text-reset text-decoration-underline" wire:click="$toggle('showAcknowledged')" aria-expanded="{{ $showAcknowledged ? 'true' : 'false' }}">{{ __('monitoring.acknowledged_count', ['count' => count($acknowledged)]) }}</button>@endif
            </span>
            @include('monitoring.partials.remote-actions')
            @if ($canManage)
                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">{{ __('monitoring.agent') }}</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('monitoring.agent', $agent->id) }}"><i class="ri-external-link-line me-1"></i>{{ __('monitoring.open_agent') }}</a></li>
                        <li><button type="button" class="dropdown-item" wire:click="toggleEnabled">
                            @if ($agent->enabled)<i class="ri-pause-circle-line me-1"></i>{{ __('monitoring.disable') }}@else<i class="ri-play-circle-line me-1"></i>{{ __('monitoring.enable') }}@endif
                        </button></li>
                        <li><button type="button" class="dropdown-item" wire:click="unlink" wire:confirm="{{ __('monitoring.unlink_confirm') }}"><i class="ri-link-unlink me-1"></i>{{ __('monitoring.unlink') }}</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item text-danger" wire:click="delete" wire:confirm="{{ __('monitoring.delete_confirm', ['name' => $agent->displayName()]) }}"><i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}</button></li>
                    </ul>
                </div>
            @endif
        </div>
    @else
        <div class="card rd-mon-head">
            <div class="card-body">
                <div class="rd-mon-head-id">
                    <x-platform-icon :platform="$agent->platform()" size="fs-32"/>
                    <div class="min-width-0">
                        <h4 class="mb-0 text-truncate">{{ $agent->displayName() }}</h4>
                        <div class="fs-13 text-muted text-truncate">
                            @if ($agent->device)<span class="rd-mono">{{ $agent->device->rustdesk_id }}</span> · @endif{{ $agent->device?->group?->name ?? $agent->group?->name ?? __('monitoring.no_group') }}
                            @unless ($actions || $agentUpdate)· <i class="ri-information-line"></i> {{ __('monitoring.read_only_telemetry') }}@endunless
                            @if ($acknowledged) · <button type="button" class="btn btn-link p-0 fs-13 align-baseline text-reset text-decoration-underline" wire:click="$toggle('showAcknowledged')" aria-expanded="{{ $showAcknowledged ? 'true' : 'false' }}">{{ __('monitoring.acknowledged_count', ['count' => count($acknowledged)]) }}</button>@endif
                        </div>
                    </div>
                </div>
                <div class="rd-mon-head-os"><i class="ri-computer-line"></i>{{ $agent->os_version ?: '—' }}</div>
                <div class="rd-mon-head-meta">
                    @include('monitoring.partials.agent-state', ['agent' => $agent])
                    <span class="fs-13 text-muted" title="{{ $sampledAt }}">{{ __('monitoring.sampled', ['when' => $sampledAt?->diffForHumans() ?? __('common.never')]) }}</span>
                </div>
                <div class="rd-mon-head-acts">
                    @if ($agent->device?->canBeControlled())
                        <a href="{{ \App\Support\ClientLink::to($agent->device->rustdesk_id) }}" class="btn btn-primary btn-sm"><i class="ri-remote-control-line me-1"></i>{{ __('monitoring.connect') }}</a>
                    @endif
                    @include('monitoring.partials.remote-actions')
                    @if ($canManage)
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">{{ __('monitoring.agent') }}</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button type="button" class="dropdown-item" wire:click="toggleEnabled">
                                    @if ($agent->enabled)<i class="ri-pause-circle-line me-1"></i>{{ __('monitoring.disable') }}@else<i class="ri-play-circle-line me-1"></i>{{ __('monitoring.enable') }}@endif
                                </button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger" wire:click="delete" wire:confirm="{{ __('monitoring.delete_confirm', ['name' => $agent->displayName()]) }}"><i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}</button></li>
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @unless ($agent->enabled)
        <div class="alert alert-warning py-2 fs-13">{{ __('monitoring.disabled_note') }}</div>
    @endunless

    @include('monitoring.partials.action-form')

    {{-- ========================== NEEDS ATTENTION ========================== --}}
    @if ($issues)
        <div class="rd-mon-attn" role="status">
            <span class="rd-mon-attn-title"><i class="ri-error-warning-line"></i>{{ __('monitoring.needs_attention') }}</span>
            @foreach ($issues as $issue)
                <span class="rd-mon-attn-item" wire:key="issue-{{ md5($issue['key']) }}">
                    <span class="rd-mon-dot {{ $issue['level'] === 'danger' ? 'rd-mon-dot-bad' : 'rd-mon-dot-warn' }}"></span>{{ $issue['text'] }}
                    @if ($canManage)
                        <button type="button" class="rd-mon-attn-ack" wire:click="acknowledgeIssue({{ \Illuminate\Support\Js::from($issue['key']) }})" title="{{ __('monitoring.acknowledge_hint') }}"><i class="ri-check-line"></i>{{ __('monitoring.acknowledge') }}</button>
                    @endif
                </span>
            @endforeach
        </div>
    @endif
    @if ($showAcknowledged && $acknowledged)
        <div class="rd-mon-acked">
            @foreach ($acknowledged as $issue)
                @php $ack = $agent->acknowledged_issues[$issue['key']] ?? []; @endphp
                <div class="rd-mon-acked-item" wire:key="acked-{{ md5($issue['key']) }}">
                    <span class="rd-mon-dot rd-mon-dot-muted"></span>
                    <span class="min-width-0">{{ $issue['text'] }}
                        <span class="text-muted fs-12 d-block d-sm-inline ms-sm-2">{{ __('monitoring.acknowledged_by', ['user' => $ack['by'] ?? '?', 'when' => isset($ack['at']) ? \Illuminate\Support\Carbon::parse($ack['at'])->diffForHumans() : '?']) }}</span>
                    </span>
                    @if ($canManage)
                        <button type="button" class="btn btn-sm btn-link ms-auto p-0" wire:click="restoreIssue({{ \Illuminate\Support\Js::from($issue['key']) }})">{{ __('common.restore') }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- ============================== TILES ============================== --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="card rd-mon-tile mb-0">
                <span class="rd-mon-tile-icon rd-tone-blue"><i class="ri-cpu-line"></i></span>
                <div class="min-width-0">
                    <div class="rd-mon-tile-label">{{ __('monitoring.cpu_load') }}</div>
                    <div class="rd-mon-tile-value">{{ $agent->cpuPercent() === null ? '—' : number_format($agent->cpuPercent(), 0).'%' }}</div>
                </div>
                <div class="rd-mon-spark" wire:ignore data-spark="cpu" data-tone="--rd-blue"></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card rd-mon-tile mb-0">
                <span class="rd-mon-tile-icon rd-tone-green"><i class="ri-database-2-line"></i></span>
                <div class="min-width-0">
                    <div class="rd-mon-tile-label">{{ __('monitoring.col_memory') }}</div>
                    <div class="rd-mon-tile-value">{{ $agent->memoryPercent() === null ? '—' : number_format($agent->memoryPercent(), 0).'%' }}</div>
                </div>
                <div class="rd-mon-spark" wire:ignore data-spark="memory" data-tone="--rd-green"></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card rd-mon-tile mb-0">
                <span class="rd-mon-tile-icon rd-tone-green"><i class="ri-pulse-line"></i></span>
                <div class="min-width-0">
                    <div class="rd-mon-tile-label">{{ __('monitoring.col_uptime') }}</div>
                    <div class="rd-mon-tile-value">{{ Monitoring::duration($agent->uptimeSeconds()) }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card rd-mon-tile mb-0">
                <span class="rd-mon-tile-icon {{ ($latest['reboot_pending'] ?? false) ? 'rd-tone-amber' : 'rd-tone-muted' }}"><i class="ri-restart-line"></i></span>
                <div class="min-width-0">
                    <div class="rd-mon-tile-label">{{ __('monitoring.reboot') }}</div>
                    <div class="rd-mon-tile-value">{{ ($latest['reboot_pending'] ?? false) ? __('monitoring.pending') : __('monitoring.not_needed') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== STORAGE · SECURITY · UPDATES ===================== --}}
    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.storage_smart') }}</h5></div>
                <div class="card-body">
                    @forelse ($agent->disks() as $d)
                        @php
                            $free = 100 - $d['percent'];
                            $smart = $soleDrive;
                        @endphp
                        <div class="rd-mon-vol">
                            <i class="ri-hard-drive-2-line rd-mon-vol-icon"></i>
                            <div class="flex-grow-1 min-width-0">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-medium text-truncate">{{ $d['mount'] }}</span>
                                    <span class="ms-auto fs-13 {{ $free < \App\Models\Agent::LOW_FREE_PERCENT ? 'text-danger' : '' }}">{{ __('monitoring.percent_free', ['percent' => number_format($free, 0)]) }}</span>
                                    @if ($smart && array_key_exists('healthy', $smart))
                                        <span class="badge {{ $smart['healthy'] ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                                            <i class="{{ $smart['healthy'] ? 'ri-check-line' : 'ri-alert-line' }}"></i> {{ $smart['healthy'] ? __('monitoring.smart_healthy') : __('monitoring.smart_warning') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="fs-12 text-muted mb-1">{{ __('monitoring.total_used', ['total' => $bytes($d['total']), 'used' => $bytes($d['used'])]) }}</div>
                                <div class="rd-meter {{ $free < \App\Models\Agent::LOW_FREE_PERCENT ? 'rd-tone-red' : 'rd-tone-blue' }}">
                                    <div class="rd-meter-track"><div class="rd-meter-fill" style="width: {{ $d['percent'] }}%"></div></div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ __('monitoring.not_reported') }}</p>
                    @endforelse
                    @if (count($smartDrives) > 1)
                        @foreach ($smartDrives as $smart)
                            <div class="d-flex align-items-center gap-2 fs-13 mb-1">
                                <span class="text-truncate">{{ ($smart['model'] ?? '') ?: ($smart['device'] ?? '') }}</span>
                                <span class="badge ms-auto {{ ($smart['healthy'] ?? true) ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">{{ ($smart['healthy'] ?? true) ? __('monitoring.smart_healthy') : __('monitoring.smart_warning') }}</span>
                            </div>
                        @endforeach
                    @endif
                    <div class="fs-12 text-muted mt-2"><i class="ri-information-line me-1"></i>{{ __('monitoring.free_threshold', ['percent' => \App\Models\Agent::LOW_FREE_PERCENT]) }}</div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.security_posture') }}</h5></div>
                <div class="card-body">
                    @php
                        $av = $health['antivirus'] ?? null;
                        // A product reported without `enabled` (XProtect) is built in and
                        // always on; without `up_to_date` its freshness is unknown.
                        $avOk = $av === null ? null : collect($av)->contains(fn ($a) => $a['enabled'] ?? true);
                        $avCurrent = $av === null ? null : (collect($av)->contains(fn ($a) => array_key_exists('up_to_date', $a))
                            ? collect($av)->contains(fn ($a) => ($a['enabled'] ?? true) && ($a['up_to_date'] ?? false))
                            : null);
                        $enc = $health['encryption'] ?? null;
                        $encOk = $enc === null ? null : collect($enc)->every(fn ($e) => $e['encrypted'] ?? false);
                        $fw = $health['firewall'] ?? null;
                    @endphp
                    <ul class="rd-mon-rows">
                        <li>
                            <i class="ri-shield-check-line"></i><span class="flex-grow-1">{{ __('monitoring.antivirus') }}</span>
                            <span class="rd-mon-state"><span class="rd-mon-dot {{ $av === null ? 'rd-mon-dot-muted' : (! $avOk ? 'rd-mon-dot-bad' : ($avCurrent === false ? 'rd-mon-dot-warn' : 'rd-mon-dot-ok')) }}"></span>
                                @if ($av === null){{ __('monitoring.not_reported') }}@elseif (! $avOk){{ __('monitoring.off') }}@else{{ __('monitoring.on') }}@if ($avCurrent !== null) · {{ $avCurrent ? __('monitoring.current') : __('monitoring.out_of_date') }}@endif @endif
                            </span>
                        </li>
                        <li>
                            <i class="ri-fire-line"></i><span class="flex-grow-1">{{ __('monitoring.firewall') }}</span>
                            <span class="rd-mon-state"><span class="rd-mon-dot {{ $dot($fw) }}"></span>{{ $fw === null ? __('monitoring.not_reported') : ($fw ? __('monitoring.on') : __('monitoring.off')) }}</span>
                        </li>
                        <li>
                            <i class="ri-lock-2-line"></i><span class="flex-grow-1">{{ __('monitoring.device_encryption') }}</span>
                            <span class="rd-mon-state"><span class="rd-mon-dot {{ $dot($encOk) }}"></span>{{ $enc === null ? __('monitoring.not_reported') : ($encOk ? __('monitoring.on') : __('monitoring.off')) }}</span>
                        </li>
                    </ul>
                    @if ($av)
                        <div class="fs-12 text-muted mt-2">{{ collect($av)->pluck('name')->filter()->implode(', ') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ $agent->os === 'windows' ? __('monitoring.windows_update') : __('monitoring.updates') }}</h5></div>
                <div class="card-body">
                    @if ($updates === null)
                        <p class="text-muted mb-0">{{ __('monitoring.not_reported') }}</p>
                    @else
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <div class="rd-mon-count h-100">
                                    <i class="ri-download-2-line text-warning"></i>
                                    <div><div class="rd-mon-count-value">{{ $updates['missing'] ?? '—' }}</div><div class="fs-13 text-muted">{{ __('monitoring.missing') }}</div></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="rd-mon-count h-100">
                                    <i class="ri-close-circle-line {{ ($updates['failed'] ?? 0) > 0 ? 'text-danger' : 'text-muted' }}"></i>
                                    <div><div class="rd-mon-count-value">{{ $updates['failed'] ?? '—' }}</div><div class="fs-13 text-muted">{{ __('monitoring.failed') }}</div></div>
                                </div>
                            </div>
                        </div>
                        @if (! empty($updates['last_installed']))
                            <div class="rd-mon-count fs-13"><i class="ri-calendar-line text-muted"></i>{{ __('monitoring.last_installed') }} <strong class="ms-1">{{ \Illuminate\Support\Carbon::parse($updates['last_installed'])->locale(app()->getLocale())->isoFormat('ll') }}</strong></div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== SERVICES · RUSTDESK · NETWORK ===================== --}}
    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.services_events') }}</h5></div>
                <div class="card-body">
                    @if ($editingWatch)
                        <form wire:submit="saveWatch">
                            <label class="form-label fs-13" for="watchInput">{{ __('monitoring.watch_services_label') }}</label>
                            <textarea id="watchInput" class="form-control rd-mono fs-13 mb-2" rows="4" wire:model="watchInput" placeholder="{{ $agent->os === 'windows' ? "MSSQLSERVER\nSpooler" : ($agent->os === 'darwin' ? 'com.openssh.sshd' : "nginx\nssh") }}"></textarea>
                            <div class="form-text mb-2">{{ __('monitoring.watch_services_help') }}</div>
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('common.save') }}</button>
                            <button type="button" class="btn btn-sm btn-light" wire:click="cancelWatch">{{ __('common.cancel') }}</button>
                        </form>
                    @else
                        <ul class="rd-mon-rows">
                            @php $reported = collect($health['services'] ?? [])->keyBy('name'); @endphp
                            @foreach ((array) $agent->watch_services as $name)
                                @php
                                    $svc = $reported[$name] ?? null;
                                    $state = $svc['state'] ?? null;
                                @endphp
                                <li>
                                    <span class="rd-mon-dot {{ $state === 'running' ? 'rd-mon-dot-ok' : ($state === 'stopped' ? 'rd-mon-dot-bad' : 'rd-mon-dot-muted') }}"></span>
                                    <span class="flex-grow-1 text-truncate">{{ ($svc['display'] ?? '') ?: $name }}</span>
                                    <span class="fs-13 {{ $state === 'stopped' ? 'text-danger' : 'text-muted' }}">{{ $state ? __('monitoring.svc_'.$state) : __('monitoring.waiting') }}</span>
                                </li>
                            @endforeach
                            @if (isset($health['event_errors_24h']))
                                <li>
                                    <i class="ri-alert-line {{ $health['event_errors_24h'] > 0 ? 'text-warning' : 'text-muted' }}"></i>
                                    <span class="flex-grow-1">{{ trans_choice('monitoring.event_errors', $health['event_errors_24h'], ['count' => $health['event_errors_24h']]) }}</span>
                                    <span class="fs-13 text-muted">24h</span>
                                </li>
                            @endif
                        </ul>
                        @if (empty($agent->watch_services) && ! isset($health['event_errors_24h']))
                            <p class="text-muted fs-13 mb-2">{{ __('monitoring.no_watched_services') }}</p>
                        @endif
                        @if ($canManage)
                            <a href="#" wire:click.prevent="editWatch" class="fs-13">{{ __('monitoring.watch_services') }}<i class="ri-arrow-right-s-line"></i></a>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.rustdesk_health') }}</h5></div>
                <div class="card-body">
                    <ul class="rd-mon-rows">
                        @if ($rd['installed'] ?? false)
                            <li><i class="ri-remote-control-line text-primary"></i><span class="flex-grow-1">{{ __('monitoring.version_n', ['version' => $rd['version'] ?: '?']) }}</span></li>
                            <li>
                                <span class="rd-mon-dot {{ ($rd['running'] ?? false) ? 'rd-mon-dot-ok' : 'rd-mon-dot-bad' }}"></span>
                                <span class="flex-grow-1 {{ ($rd['running'] ?? false) ? 'text-success' : 'text-danger' }}">{{ ($rd['running'] ?? false) ? __('monitoring.service_running') : __('monitoring.service_not_running') }}</span>
                            </li>
                        @elseif ($latest)
                            <li><span class="rd-mon-dot rd-mon-dot-muted"></span><span class="flex-grow-1 text-muted">{{ __('monitoring.rustdesk_not_installed') }}</span></li>
                        @endif
                        <li>
                            <i class="ri-link"></i>
                            @if ($agent->device)
                                <span class="flex-grow-1">{{ __('monitoring.linked_to') }} <a href="{{ route('devices.show', $agent->device->id) }}" class="rd-mono">{{ $agent->device->rustdesk_id }}</a></span>
                            @else
                                <span class="flex-grow-1 text-muted">{{ __('monitoring.not_linked') }}</span>
                            @endif
                        </li>
                    </ul>
                    @if (! $agent->device && $canManage)
                        <form wire:submit="link" class="d-flex gap-2 mt-2">
                            <input type="text" class="form-control form-control-sm rd-mono @error('linkRustdeskId') is-invalid @enderror"
                                   wire:model="linkRustdeskId" placeholder="{{ __('monitoring.rustdesk_id') }}" aria-label="{{ __('monitoring.rustdesk_id') }}">
                            <button type="submit" class="btn btn-light btn-sm text-nowrap">{{ __('monitoring.link') }}</button>
                        </form>
                        @error('linkRustdeskId') <div class="text-danger fs-13 mt-1">{{ $message }}</div> @enderror
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.network') }}</h5></div>
                <div class="card-body">
                    <dl class="rd-mon-kv">
                        <div><dt><i class="ri-router-line"></i>{{ __('monitoring.local_ip') }}</dt><dd class="rd-mono">{{ $nic['ip'] ?? '—' }}</dd></div>
                        <div><dt><i class="ri-global-line"></i>{{ __('monitoring.public_ip') }}</dt><dd class="rd-mono" title="{{ __('monitoring.public_ip_hint') }}">{{ $agent->last_ip ?: '—' }}</dd></div>
                        <div><dt><i class="ri-node-tree"></i>{{ __('monitoring.mac') }}</dt><dd class="rd-mono">{{ ($nic['mac'] ?? '') ?: '—' }}</dd></div>
                        @if (! empty($health['wifi_ssid']))
                            <div><dt><i class="ri-wifi-line"></i>{{ __('monitoring.wifi') }}</dt><dd>{{ $health['wifi_ssid'] }}</dd></div>
                        @endif
                        @if (! empty($latest['users']))
                            <div><dt><i class="ri-user-line"></i>{{ __('monitoring.signed_in') }}</dt><dd>{{ implode(', ', $latest['users']) }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================== COMMANDS ============================== --}}
    @if ($commands->isNotEmpty())
        @include('monitoring.partials.agent-commands')
    @endif

    {{-- ============================== INVENTORY ============================== --}}
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h5 class="card-title mb-0 flex-grow-1">{{ __('monitoring.inventory') }}</h5>
            @if ($agent->inventory_at)<span class="fs-12 text-muted" title="{{ $agent->inventory_at }}">{{ $agent->inventory_at->diffForHumans() }}</span>@endif
        </div>
        @if (! $agent->inventory)
            <div class="card-body"><p class="text-muted mb-0">{{ __('monitoring.no_inventory') }}</p></div>
        @else
            <div class="rd-mon-inv">
                <button type="button" @class(['rd-mon-inv-item', 'active' => $inventoryPanel === 'hardware']) wire:click="showInventory('hardware')">
                    <i class="ri-computer-line"></i>
                    <span><span class="rd-mon-inv-title">{{ __('monitoring.hardware') }}</span><span class="rd-mon-inv-sub">{{ trim(($hw['manufacturer'] ?? '').' '.($hw['model'] ?? '')) ?: __('monitoring.hardware_sub') }}</span></span>
                </button>
                <button type="button" @class(['rd-mon-inv-item', 'active' => $inventoryPanel === 'software']) wire:click="showInventory('software')">
                    <i class="ri-stack-line"></i>
                    <span><span class="rd-mon-inv-title">{{ __('monitoring.installed_apps') }}</span><span class="rd-mon-inv-sub">{{ trans_choice('monitoring.app_count', $softwareTotal, ['count' => $softwareTotal]) }}</span></span>
                </button>
                <button type="button" @class(['rd-mon-inv-item', 'active' => $inventoryPanel === 'admins']) wire:click="showInventory('admins')">
                    <i class="ri-group-line"></i>
                    @php $admins = (array) ($inv['local_admins'] ?? []); @endphp
                    <span><span class="rd-mon-inv-title">{{ __('monitoring.local_admins') }}</span><span class="rd-mon-inv-sub">{{ trans_choice('monitoring.account_count', count($admins), ['count' => count($admins)]) }}</span></span>
                </button>
            </div>

            @if ($inventoryPanel === 'hardware')
                <div class="card-body border-top">
                    <dl class="rd-deflist">
                        <div class="rd-def"><dt>{{ __('monitoring.os') }}</dt><dd>{{ trim(($os['name'] ?? '').' '.($os['version'] ?? '')) ?: '—' }}@if (! empty($os['build'])) <span class="text-muted">({{ $os['build'] }})</span>@endif</dd></div>
                        <div class="rd-def"><dt>{{ __('monitoring.model') }}</dt><dd>{{ trim(($hw['manufacturer'] ?? '').' '.($hw['model'] ?? '')) ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('monitoring.serial') }}</dt><dd class="rd-mono">{{ $hw['serial'] ?? '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('monitoring.bios') }}</dt><dd>{{ $hw['bios'] ?? '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('monitoring.processor') }}</dt><dd>{{ $hw['cpu'] ?? '—' }}@if (! empty($hw['cores'])) <span class="text-muted">({{ $hw['cores'] }}c/{{ $hw['threads'] ?? $hw['cores'] }}t)</span>@endif</dd></div>
                        <div class="rd-def"><dt>{{ __('monitoring.col_memory') }}</dt><dd>{{ isset($hw['memory']) ? $bytes($hw['memory']) : '—' }}</dd></div>
                        @foreach ((array) ($hw['drives'] ?? []) as $drive)
                            <div class="rd-def"><dt>{{ __('monitoring.drive') }}</dt><dd>{{ $drive['model'] ?? '—' }} <span class="text-muted">{{ isset($drive['size']) ? $bytes($drive['size']) : '' }}</span></dd></div>
                        @endforeach
                        @if (! empty($inv['battery']['design_capacity']))
                            @php $b = $inv['battery']; @endphp
                            <div class="rd-def"><dt>{{ __('monitoring.battery') }}</dt><dd>
                                {{ __('monitoring.battery_health', ['percent' => round(($b['full_capacity'] ?? 0) / $b['design_capacity'] * 100)]) }}
                                @if (! empty($b['cycle_count']))<span class="text-muted">· {{ trans_choice('monitoring.cycles', $b['cycle_count'], ['count' => $b['cycle_count']]) }}</span>@endif
                            </dd></div>
                        @endif
                        @foreach ((array) ($inv['network'] ?? []) as $n)
                            <div class="rd-def"><dt>{{ $n['name'] ?? __('monitoring.network') }}</dt><dd class="rd-mono fs-13">{{ implode(', ', (array) ($n['ips'] ?? [])) ?: '—' }} <span class="text-muted">{{ $n['mac'] ?? '' }}</span></dd></div>
                        @endforeach
                    </dl>
                </div>
            @elseif ($inventoryPanel === 'software')
                <div class="card-body border-top pb-0">
                    <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control form-control-sm mb-2" style="max-width: 280px;" wire:model.live.debounce.300ms="softwareSearch"
                           placeholder="{{ \App\Support\SearchHint::placeholder(__('monitoring.search_software')) }}" aria-label="{{ __('monitoring.search_software') }}">
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>{{ __('common.name') }}</th><th>{{ __('monitoring.col_version') }}</th><th class="d-none d-md-table-cell">{{ __('monitoring.publisher') }}</th></tr></thead>
                        <tbody>
                        @forelse ($software as $s)
                            <tr>
                                <td>{{ $s['name'] ?? '—' }}</td>
                                <td class="rd-mono fs-13">{{ $s['version'] ?? '' }}</td>
                                <td class="d-none d-md-table-cell text-muted fs-13">{{ $s['publisher'] ?? '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted text-center py-3">{{ __('monitoring.no_software_match') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($softwareMatches > $software->count())
                    <div class="card-footer fs-13 text-muted">{{ __('monitoring.software_more', ['shown' => $software->count(), 'total' => $softwareMatches]) }}</div>
                @endif
            @elseif ($inventoryPanel === 'admins')
                <div class="card-body border-top">
                    @forelse ($admins as $name)
                        <span class="badge bg-secondary-subtle text-secondary me-1 mb-1">{{ $name }}</span>
                    @empty
                        <p class="text-muted mb-0">{{ __('monitoring.not_reported') }}</p>
                    @endforelse
                </div>
            @endif
        @endif
    </div>

    {{-- ============================== HISTORY ============================== --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-title mb-0 flex-grow-1">{{ __('monitoring.history') }}</h5>
            <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('monitoring.history') }}">
                @foreach (array_keys(\App\Livewire\AgentDetail::RANGES) as $r)
                    <button type="button" class="btn {{ $range === $r ? 'btn-primary' : 'btn-light' }}" wire:click="setRange('{{ $r }}')">{{ __('monitoring.range_'.$r) }}</button>
                @endforeach
            </div>
        </div>
        <div class="card-body">
            @if (empty($series['cpu']))
                <p class="text-muted mb-0">{{ __('monitoring.no_history') }}</p>
            @endif
            @if (\App\Models\Agent::networkBytesUnreported($latest['network'] ?? null))
                <p class="text-muted fs-13 mb-2"><i class="ri-information-line me-1"></i>{{ __('monitoring.network_bytes_unreported') }}</p>
            @endif
            <div wire:ignore class="row g-3 rd-mon-charts" data-series="{{ json_encode($series) }}" data-sparks="{{ json_encode($sparks) }}" data-labels="{{ json_encode($chartLabels) }}">
                <div class="col-lg-6"><div data-chart="usage"></div></div>
                <div class="col-lg-6"><div data-chart="network"></div></div>
            </div>
        </div>
    </div>
</div>
