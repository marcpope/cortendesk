<div wire:poll.15s>
    {{-- Header: who this is and how to reach it. --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-platform-icon :platform="$device->platform()" size="fs-36"/>
            <div class="min-width-0 me-auto">
                <h4 class="mb-0 text-truncate">{{ $device->alias ?: $device->hostname ?: $device->rustdesk_id }}</h4>
                <span class="text-muted">
                    {{ $device->rustdesk_id }}
                    @if ($device->alias && $device->hostname) · {{ $device->hostname }} @endif
                </span>
            </div>
            {{-- Status sits in the button row so flex stretches it to the
                 buttons' height. --}}
            <div class="d-flex flex-wrap gap-2">
                @if ($device->trashed())
                    <span class="badge bg-warning-subtle text-warning rd-status-block">{{ __('devices.in_recycle_bin') }}</span>
                @elseif ($device->isOnline())
                    <span class="badge bg-success-subtle text-success rd-status-block"><i class="rd-dot"></i>{{ __('common.online') }}</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary rd-status-block"><i class="rd-dot"></i>{{ __('devices.offline') }}</span>
                @endif
                @if ($device->isIncomingOnly())
                    <span class="badge bg-info-subtle text-info rd-status-block" title="{{ __('devices.can_be_controlled_cannot_start') }}">{{ __('devices.incoming_only') }}</span>
                @endif
                @unless ($device->canBeControlled())
                    <span class="badge rd-badge-purple rd-status-block" title="{{ __('devices.ios_cannot_be_controlled') }}">{{ __('devices.outgoing_only') }}</span>
                @endunless
                @unless ($device->trashed())
                    @if ($device->canBeControlled())
                        <a href="{{ \App\Support\ClientLink::to($device->rustdesk_id) }}" class="btn btn-sm btn-outline-light"
                           title="{{ __('common.connect_with_rustdesk') }}"><i class="ri-links-line me-1"></i>{{ __('devices.rustdesk') }}</a>
                        @if (config('cortendesk.native_webclient'))
                            <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                               target="cortendesk-webclient" rel="noopener" class="btn btn-sm btn-primary">
                                <i class="ri-remote-control-line me-1"></i>{{ __('devices.connect') }}</a>
                        @endif
                    @endif
                    @if ($canEdit)
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="editDevice">
                            <i class="ri-pencil-line me-1"></i>{{ __('common.edit') }}</button>
                        @if ($canReleaseId)
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="releaseId"
                                    wire:confirm="{{ __('devices.release_id_confirm', ['id' => $device->rustdesk_id]) }}"
                                    title="{{ __('devices.release_id_hint') }}">
                                <i class="ri-price-tag-3-line me-1"></i>{{ __('devices.release_id') }}</button>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                            <i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}</button>
                    @endif
                @endunless
            </div>
        </div>
    </div>

    {{-- Possible duplicates (issue #91): the same machine under another
         RustDesk ID. Each twin gets its facts and the fixes. --}}
    @if ($twins !== [])
        @php $first = $twins[0]['device']; @endphp
        <div class="alert alert-warning" role="status">
            <div class="d-flex gap-2">
                <i class="ri-file-copy-2-line fs-18 lh-1 mt-1"></i>
                <div class="min-width-0 flex-grow-1">
                    <p class="fw-semibold mb-1">
                        {{ count($twins) === 1 ? __('devices.possible_duplicate_of_id', ['id' => $first->rustdesk_id]) : __('devices.possible_duplicate_of_count_devices', ['count' => count($twins)]) }}
                    </p>
                    <p class="fs-13 mb-2">
                        {{ __('devices.rustdesk_gives_machine_new_id') }}
                        {{ $device->isOnline() ? __('devices.this_device_was_online_just') : __('devices.this_device_was_last_seen', ['when' => $device->last_online_at?->diffForHumans() ?? __('common.never')]) }}
                    </p>
                    @foreach ($twins as $twin)
                        @php $other = $twin['device']; @endphp
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 border-top" wire:key="twin{{ $other->id }}">
                            <div class="min-width-0">
                                <a href="{{ route('devices.show', $other->id) }}" class="fw-semibold">{{ $other->rustdesk_id }}</a>
                                @if ($other->alias || $other->hostname)
                                    <span>· {{ $other->alias ?: $other->hostname }}</span>
                                @endif
                                <span class="d-block fs-13">
                                    {{ ucfirst(\App\Support\Label::get('dup_reason', $twin['reason'], $twin['label'])) }} ·
                                    {{ $other->isOnline() ? __('devices.online_now') : __('devices.last_seen_when', ['when' => $other->last_online_at?->diffForHumans() ?? __('common.never')]) }} ·
                                    {{ __('devices.first_seen_date', ['date' => $other->created_at?->format('Y-m-d') ?? __('common.unknown')]) }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('devices.show', $other->id) }}" class="btn btn-sm btn-outline-light">{{ __('devices.open') }}</a>
                                @if ($canEdit)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDeleteTwin({{ $other->id }})">
                                        <i class="ri-delete-bin-line me-1"></i>{{ __('devices.delete_id', ['id' => $other->rustdesk_id]) }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismissDuplicate({{ $other->id }})"
                                            wire:confirm="{{ __('devices.stop_flagging_and_b_as', ['a' => $device->rustdesk_id, 'b' => $other->rustdesk_id]) }}">
                                        {{ __('devices.not_duplicate') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($canEdit)
                        <div class="border-top pt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                                <i class="ri-delete-bin-line me-1"></i>{{ __('devices.delete_this_device_id', ['id' => $device->rustdesk_id]) }}</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if ($releaseResult !== '')
        <div class="fs-13 {{ $releaseOk ? 'text-success' : 'text-danger' }} mb-3" role="status">
            <i class="{{ $releaseOk ? 'ri-check-line' : 'ri-error-warning-line' }} me-1"></i>{{ $releaseResult }}</div>
    @endif

    @if ($duplicateResult !== '')
        <div class="fs-13 text-success mb-3"><i class="ri-check-line me-1"></i>{{ $duplicateResult }}</div>
    @endif

    {{-- Tabs. Every pane is rendered and only the chosen one shows, so the
         share card's own component stays mounted across a tab change. --}}
    @php
        $tabs = [
            'overview' => ['ri-home-4-line', __('common.overview')],
            'connections' => ['ri-links-line', __('common.connections')],
            'activity' => ['ri-pulse-line', __('devices.tab_activity')],
            'details' => ['ri-information-line', __('devices.tab_details')],
        ];
    @endphp
    <div class="rd-dd-tabs">
        <ul class="nav nav-tabs nav-bordered rd-tabbar">
            @foreach ($tabs as $key => [$icon, $label])
                <li class="nav-item">
                    <a href="#" wire:click.prevent="setTab('{{ $key }}')"
                       class="nav-link {{ $tab === $key ? 'active' : '' }}" @if ($tab === $key) aria-current="page" @endif>
                        <i class="{{ $icon }} me-1"></i><span>{{ $label }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <span class="rd-dd-seen">
            <i class="ri-time-line me-1"></i>
            @if ($device->isOnline())
                {{ ucfirst(__('devices.online_now')) }}
            @elseif (! $device->last_online_at && ! $device->canBeControlled())
                {{ __('devices.outgoing_only') }}
            @else
                {{ ucfirst(__('devices.last_seen_when', ['when' => $device->last_online_at?->diffForHumans() ?? __('common.never')])) }}
            @endif
        </span>
    </div>

    {{-- ============================== OVERVIEW ============================== --}}
    <div @class(['rd-dd-pane', 'd-none' => $tab !== 'overview'])>
        <div class="row rd-row-equal">
            <div class="col-xl-5">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('devices.hardware_snapshot') }}</h5></div>
                    <div class="card-body">
                        <div class="rd-hw">
                            {{-- The platform glyph on a lit stage, in place of a product photo. --}}
                            <div class="rd-hw-stage" aria-hidden="true">
                                <x-platform-icon :platform="$device->platform()" size="rd-hw-glyph"/>
                            </div>
                            <div class="rd-hw-grid">
                                <div class="rd-hw-tile">
                                    <i class="ri-cpu-line"></i>
                                    <span class="rd-hw-value" title="{{ $device->cpu }}">{{ $hardware['cpu'] ?: '—' }}</span>
                                    <span class="rd-hw-label">{{ $hardware['cpu_detail'] ?: __('devices.cpu') }}</span>
                                </div>
                                <div class="rd-hw-tile">
                                    <i class="ri-ram-2-line"></i>
                                    <span class="rd-hw-value">{{ $hardware['memory'] ?: '—' }}</span>
                                    <span class="rd-hw-label">{{ __('devices.memory') }}</span>
                                </div>
                                <div class="rd-hw-tile">
                                    <i class="ri-macbook-line"></i>
                                    <span class="rd-hw-value" title="{{ $device->os }}">{{ $hardware['os'] ?: '—' }}</span>
                                    <span class="rd-hw-label">{{ $hardware['os_detail'] ?: __('devices.hw_os') }}</span>
                                </div>
                                <div class="rd-hw-tile">
                                    <i class="ri-links-line"></i>
                                    <span class="rd-hw-value">{{ $device->version ?: '—' }}</span>
                                    <span class="rd-hw-label">{{ __('devices.hw_client') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-7">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('devices.recent_connections') }}</h5></div>
                    <div class="card-body">
                        @if (! $canAudit)
                            <p class="text-muted mb-0">{{ __('common.activity_history_needs_audit_permission') }}</p>
                        @elseif ($flow['in'] === [] && $flow['out'] === [])
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-links-line"></i></div>
                                <p class="rd-empty-title">{{ __('common.no_connections_recorded') }}</p>
                            </div>
                        @else
                            {{-- Who reached this device (left), and what it reached (right). --}}
                            <div @class(['rd-flow', 'rd-flow-has-in' => $flow['in'] !== [], 'rd-flow-has-out' => $flow['out'] !== []])>
                                @if ($flow['in'] !== [])
                                    @php $nIn = count($flow['in']) + ($flow['in_more'] ? 1 : 0); @endphp
                                    <div class="rd-flow-col rd-flow-in">
                                        @foreach ($flow['in'] as $n)
                                            @include('livewire.partials.device-flow-node', ['n' => $n, 'fallback' => 'ri-user-3-line'])
                                        @endforeach
                                        @if ($flow['in_more'])
                                            <div class="rd-flow-node rd-flow-more">{{ __('devices.count_more', ['count' => $flow['in_more']]) }}</div>
                                        @endif
                                    </div>
                                    <div class="rd-flow-link rd-flow-link-in" aria-hidden="true">
                                        <svg viewBox="0 0 100 100" preserveAspectRatio="none">
                                            @for ($i = 0; $i < $nIn; $i++)
                                                @php $y = round(($i + 0.5) / $nIn * 100, 2); @endphp
                                                <path d="M0,{{ $y }} C55,{{ $y }} 45,50 100,50"/>
                                            @endfor
                                        </svg>
                                        <i class="ri-arrow-down-line"></i>
                                    </div>
                                @endif
                                <div class="rd-flow-center">
                                    <div class="rd-flow-self">
                                        <x-platform-icon :platform="$device->platform()" size="fs-24"/>
                                        <span class="text-truncate">{{ $device->alias ?: $device->hostname ?: $device->rustdesk_id }}</span>
                                    </div>
                                </div>
                                @if ($flow['out'] !== [])
                                    @php $nOut = count($flow['out']) + ($flow['out_more'] ? 1 : 0); @endphp
                                    <div class="rd-flow-link rd-flow-link-out" aria-hidden="true">
                                        <svg viewBox="0 0 100 100" preserveAspectRatio="none">
                                            @for ($i = 0; $i < $nOut; $i++)
                                                @php $y = round(($i + 0.5) / $nOut * 100, 2); @endphp
                                                <path d="M0,50 C55,50 45,{{ $y }} 100,{{ $y }}"/>
                                            @endfor
                                        </svg>
                                        <i class="ri-arrow-down-line"></i>
                                    </div>
                                    <div class="rd-flow-col rd-flow-out">
                                        @foreach ($flow['out'] as $n)
                                            @include('livewire.partials.device-flow-node', ['n' => $n, 'fallback' => 'ri-computer-line'])
                                        @endforeach
                                        @if ($flow['out_more'])
                                            <div class="rd-flow-node rd-flow-more">{{ __('devices.count_more', ['count' => $flow['out_more']]) }}</div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @php $flowTotal = $flow['in_count'] + $flow['out_count']; @endphp
                            <div class="rd-flow-foot">
                                <span class="rd-card-sub">{{ trans_choice('devices.last_count_sessions', $flowTotal) }}</span>
                                <div class="rd-flow-split">
                                    @if ($flow['in_count'])<span class="rd-flow-split-in" style="width: {{ round($flow['in_count'] / $flowTotal * 100, 1) }}%"></span>@endif
                                    @if ($flow['out_count'])<span class="rd-flow-split-out" style="width: {{ round($flow['out_count'] / $flowTotal * 100, 1) }}%"></span>@endif
                                </div>
                                <div class="rd-flow-legend">
                                    <span class="rd-flow-key rd-flow-key-in">{{ __('devices.count_incoming', ['count' => $flow['in_count']]) }}</span>
                                    <span class="rd-flow-key rd-flow-key-out">{{ __('devices.count_outgoing', ['count' => $flow['out_count']]) }}</span>
                                    <a href="#" wire:click.prevent="setTab('connections')" class="fs-13 ms-auto">{{ __('overview.view_log') }}<i class="ri-arrow-right-s-line"></i></a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($canAudit)
            {{-- One line per activity log; the Activity tab has the lists. --}}
            @php
                $activitySummary = [
                    ['ri-download-2-line', __('devices.file_transfers'), $transfers->first()?->created_at],
                    ['ri-alarm-warning-line', __('common.alarms'), $alarms->first()?->created_at],
                    ['ri-notification-3-line', __('devices.notifications'), $notifications->first()?->created_at],
                ];
            @endphp
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('devices.tab_activity') }}</h5></div>
                <div class="rd-dd-strip">
                    @foreach ($activitySummary as [$icon, $label, $latest])
                        <a href="#" wire:click.prevent="setTab('activity')" class="rd-dd-strip-item">
                            <i class="{{ $icon }}"></i>
                            <span class="min-width-0">
                                <span class="rd-cell-title text-truncate">{{ $label }}</span>
                                <span class="rd-cell-sub">{{ $latest ? __('overview.latest_when', ['when' => $latest->diffForHumans()]) : __('devices.none_recorded') }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Shortcuts to the panels on the Details tab. --}}
        <div class="row">
            <div class="col-md-4">
                <a href="#" wire:click.prevent="setTab('details')" class="card rd-dd-tile">
                    <i class="ri-contacts-book-2-line"></i>
                    <span class="rd-dd-tile-title">{{ __('common.address_books_2') }}</span>
                    <span class="badge bg-secondary-subtle text-secondary ms-auto">{{ $books->count() }}</span>
                    <i class="ri-arrow-right-s-line rd-dd-tile-go"></i>
                </a>
            </div>
            <div class="col-md-4">
                <a href="#" wire:click.prevent="{{ $canEdit ? 'openNote' : "setTab('details')" }}" class="card rd-dd-tile">
                    <i class="ri-pencil-line"></i>
                    <span class="rd-dd-tile-title">{{ __('common.note') }}</span>
                    <span class="rd-dd-tile-sub text-truncate">{{ $device->note }}</span>
                    @if ($canEdit)
                        <span class="rd-dd-tile-action">{{ $device->note ? __('common.edit') : __('devices.add_note') }}</span>
                    @endif
                    <i class="ri-arrow-right-s-line rd-dd-tile-go"></i>
                </a>
            </div>
            @if (config('cortendesk.share_links'))
                <div class="col-md-4">
                    <a href="#" wire:click.prevent="{{ $canEdit && ! $device->trashed() ? 'startShare' : "setTab('details')" }}" class="card rd-dd-tile">
                        <i class="ri-share-forward-line"></i>
                        <span class="rd-dd-tile-title">{{ __('shares.card_title') }}</span>
                        @if ($canEdit && ! $device->trashed())
                            <span class="rd-dd-tile-action ms-auto">{{ __('shares.new_share') }}</span>
                        @endif
                        <i class="ri-arrow-right-s-line rd-dd-tile-go"></i>
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- ============================ CONNECTIONS ============================ --}}
    <div @class(['rd-dd-pane', 'd-none' => $tab !== 'connections'])>
        @if ($canAudit)
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">{{ __('devices.recent_connections') }}</h5>
                    <a href="{{ route('logs.connections') }}?search={{ $device->rustdesk_id }}" class="fs-13">{{ __('common.all') }}</a>
                </div>
                <div class="card-body">
                    @forelse ($connections as $c)
                        <div class="rd-def">
                            <dt class="fw-normal">
                                @if ($c->rustdesk_id !== $device->rustdesk_id)
                                    {{-- This device started it (#149): name the device it reached. --}}
                                    <x-outgoing-peer :peer="$peerNames[$c->rustdesk_id] ?? [$c->rustdesk_id, null]"/>
                                @else
                                    @php $from = $c->from_peer ? ($peerNames[$c->from_peer] ?? null) : null; @endphp
                                    @if ($from && $from[1])
                                        {{-- A device the console knows: its alias, linked. --}}
                                        <a href="{{ route('devices.show', $from[1]) }}" title="{{ $c->from_name ?: $c->from_peer }}">{{ $from[0] }}</a>
                                    @else
                                        {{ $c->from_name ?: $c->from_peer ?: 'unknown' }}
                                    @endif
                                    <span class="text-muted">· {{ $c->ip }}</span>
                                @endif
                                @if ($c->note)
                                    <span class="rd-cell-sub rd-conn-note-full"><i class="ri-sticky-note-line"></i> {{ $c->note }}@if ($c->noteUser) · {{ $c->noteUser->username }}@endif</span>
                                @endif
                            </dt>
                            <dd class="text-end text-muted mb-0">
                                <x-conn-path :conn="$c" hide-unknown class="me-1"/>
                                {{ $c->created_at->diffForHumans() }}
                                @if ($c->closed_at) · {{ $c->created_at->diffAsCarbonInterval($c->closed_at)->cascade()->forHumans(short: true) }} @endif
                            </dd>
                        </div>
                    @empty
                        <span class="text-muted">{{ __('common.no_connections_recorded') }}</span>
                    @endforelse
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-body text-muted">
                    {{ __('common.activity_history_needs_audit_permission') }}
                </div>
            </div>
        @endif
    </div>

    {{-- ============================== ACTIVITY ============================= --}}
    <div @class(['rd-dd-pane', 'd-none' => $tab !== 'activity'])>
        @if ($canAudit)
            <div class="row">
                <div class="col-xl-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">{{ __('devices.recent_file_transfers') }}</h5>
                            <a href="{{ route('logs.file-transfers') }}?search={{ $device->rustdesk_id }}" class="fs-13">{{ __('common.all') }}</a>
                        </div>
                        <div class="card-body">
                            @forelse ($transfers as $t)
                                @php
                                    // The device logs direction from the controlled side. On an
                                    // outgoing row this device is the other end, so flip it (#149).
                                    $outgoing = $t->rustdesk_id !== $device->rustdesk_id;
                                    $received = ($t->direction === 1) !== $outgoing;
                                @endphp
                                <div class="rd-def">
                                    <dt class="fw-normal text-truncate" style="max-width: 70%" title="{{ $t->isClipboard() ? implode(', ', $t->fileNames()) : $t->path }}">
                                        @if ($received)
                                            <i class="ri-arrow-down-line text-warning me-1" title="{{ __('common.receive') }}"></i>
                                        @else
                                            <i class="ri-arrow-up-line text-info me-1" title="{{ __('common.send') }}"></i>
                                        @endif
                                        @if ($t->isClipboard())
                                            <span class="badge bg-secondary-subtle text-secondary me-1">{{ __('common.clipboard') }}</span>
                                        @endif
                                        {{ $t->pathLabel() ?: '—' }}
                                    </dt>
                                    <dd class="text-end text-muted mb-0">
                                        @if ($outgoing)
                                            <x-outgoing-peer :peer="$peerNames[$t->rustdesk_id] ?? [$t->rustdesk_id, null]"/> ·
                                        @endif
                                        {{ $t->created_at->diffForHumans() }}
                                    </dd>
                                </div>
                            @empty
                                <span class="text-muted">{{ __('devices.no_file_transfers_recorded') }}</span>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">{{ __('common.alarms') }}</h5>
                            <a href="{{ route('logs.alarms') }}?search={{ $device->rustdesk_id }}" class="fs-13">{{ __('common.all') }}</a>
                        </div>
                        <div class="card-body">
                            @forelse ($alarms as $a)
                                <div class="rd-def">
                                    <dt class="fw-normal">
                                        <span class="badge bg-{{ $a->typeSeverity() }}-subtle text-{{ $a->typeSeverity() }}">{{ $a->typeLabel() }}</span>
                                    </dt>
                                    <dd class="text-end text-muted mb-0">{{ $a->created_at->diffForHumans() }}</dd>
                                </div>
                            @empty
                                <span class="text-muted">{{ __('devices.no_alarms_recorded') }}</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('devices.notifications') }}</h5></div>
                <div class="card-body">
                    @forelse ($notifications as $n)
                        <div class="rd-def">
                            <dt class="fw-normal">
                                {{ \App\Services\AppriseNotifications::eventLabel($n->event) }}
                                @if ($n->error)<span class="d-block text-danger fs-13 text-break">{{ $n->error }}</span>@endif
                            </dt>
                            <dd class="text-end text-muted mb-0 text-nowrap">
                                <span title="{{ $n->created_at?->format('Y-m-d H:i:s T') }}">{{ $n->created_at?->diffForHumans() }}</span>
                                @php $tone = ['sent' => 'success', 'failed' => 'danger'][$n->status] ?? 'secondary'; @endphp
                                <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} ms-1">{{ $n->status }}</span>
                            </dd>
                        </div>
                    @empty
                        <span class="text-muted">{{ __('devices.no_notifications_sent_for_this') }}</span>
                    @endforelse
                </div>
            </div>
        @else
            <div class="card">
                <div class="card-body text-muted">
                    {{ __('common.activity_history_needs_audit_permission') }}
                </div>
            </div>
        @endif
    </div>

    {{-- ============================== DETAILS ============================== --}}
    <div @class(['rd-dd-pane', 'd-none' => $tab !== 'details'])>
        <div class="row">
            <div class="col-lg-6">
                {{-- Facts. Everything the client reports, plus console assignment. --}}
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('common.device') }}</h5></div>
                    <div class="card-body">
                        <dl class="rd-deflist">
                            <div class="rd-def"><dt>{{ __('common.os') }}</dt><dd class="text-end" title="{{ $device->os }}">{{ $device->osDescription() ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.cpu') }}</dt><dd class="text-end">{{ $device->cpu ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.memory') }}</dt><dd>{{ $device->memory ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('common.client_version') }}</dt><dd>{{ $device->version ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.client_user') }}</dt><dd>{{ $device->username ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.last_ip') }}</dt><dd>{{ $device->last_online_ip ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.lan_ip') }}</dt>
                                <dd class="text-end" title="{{ __('devices.learned_by_id_server_when') }}">
                                    @if ($device->lan_ip)
                                        <span>{{ $device->lan_ip }}</span>
                                        <span class="d-block text-muted fs-12">{{ __('devices.last_seen_during_connection_when', ['when' => $device->lan_ip_seen_at?->diffForHumans()]) }}</span>
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                            <div class="rd-def"><dt>{{ __('common.sessions') }}</dt><dd>{{ ! $device->canBeControlled() ? __('devices.outgoing_only') : ($device->isIncomingOnly() ? __('devices.incoming_only') : __('devices.incoming_and_outgoing')) }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.registered_from') }}</dt><dd title="{{ __('devices.address_this_device_first_appeared') }}">{{ $device->registered_ip ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.uuid') }}</dt><dd class="rd-mono rd-select-all min-width-0">{{ $device->uuid ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.first_seen_2') }}</dt><dd>{{ $device->created_at?->diffForHumans() ?? '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.last_seen') }}</dt>@if (! $device->last_online_at && ! $device->canBeControlled())<dd title="{{ __('devices.ios_no_presence') }}">—</dd>@else<dd>{{ $device->last_online_at?->diffForHumans() ?? __('common.never') }}</dd>@endif</div>
                            <div class="rd-def"><dt>{{ __('common.group') }}</dt><dd>{{ $device->group?->name ?: '—' }}</dd></div>
                            <div class="rd-def"><dt>{{ __('devices.owner') }}</dt>
                                <dd>
                                    @if ($device->user)
                                        <span class="badge bg-info-subtle text-info">{{ $device->user->username }}</span>
                                    @else — @endif
                                </dd>
                            </div>
                            <div class="rd-def"><dt>{{ __('devices.strategy') }}</dt><dd>{{ $device->resolvedStrategy?->name ?: '—' }}</dd></div>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                {{-- Note: the one editable field here. Everything else is either
                     client-reported or has its own screen. --}}
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ __('common.note') }}</h5>
                        @if ($canEdit && ! $editingNote)
                            <a href="javascript:void(0);" class="fs-13" wire:click="editNote">
                                <i class="ri-pencil-line me-1"></i>{{ __('common.edit') }}</a>
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($editingNote)
                            <textarea class="form-control @error('note') is-invalid @enderror" rows="3"
                                      wire:model="note" maxlength="500"></textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-primary" wire:click="saveNote">{{ __('common.save') }}</button>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="cancelNote">{{ __('common.cancel') }}</button>
                            </div>
                        @else
                            <p class="mb-0 {{ $device->note ? '' : 'text-muted' }}">{{ $device->note ?: __('devices.no_note') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Membership: which books hand this device out. Add and remove
                     follow the same per-book rule as the list's bulk add. --}}
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ __('common.address_books_2') }}</h5>
                        @if ($canWriteBooks && ! $device->trashed() && ! $abPickerOpen)
                            <a href="javascript:void(0);" class="fs-13" wire:click="openAbPicker">
                                <i class="ri-add-line me-1"></i>{{ __('common.add') }}</a>
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($abPickerOpen)
                            <form wire:submit="addToBook" class="mb-3">
                                <label class="form-label" for="dd-ab-book">{{ __('devices.add_to_address_book_2') }}</label>
                                <div class="d-flex gap-2">
                                    <select id="dd-ab-book" class="form-select @error('abBookId') is-invalid @enderror" wire:model="abBookId">
                                        <option value="0">{{ __('devices.choose') }}</option>
                                        @foreach ($addableBooks as $book)
                                            <option value="{{ $book->id }}">{{ $book->name }}{{ $book->is_personal ? ' (personal)' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('common.add') }}</button>
                                    <button type="button" class="btn btn-sm btn-light" wire:click="closeAbPicker">{{ __('common.cancel') }}</button>
                                </div>
                                @error('abBookId')<div class="text-danger fs-13 mt-1">{{ $message }}</div>@enderror
                                @if ($addableBooks->isEmpty())
                                    <div class="form-text">{{ __('devices.it_is_already_in_every') }}</div>
                                @endif
                            </form>
                        @endif
                        @forelse ($books as $book)
                            <span class="badge bg-secondary-subtle text-secondary me-1 mb-1">
                                <i class="ri-contacts-book-2-line me-1"></i>{{ $book->is_personal ? ($book->owner?->username ? __('address_books.owners_personal', ['name' => $book->owner->username]) : __('address_books.personal')) : $book->name }}
                                @if (in_array($book->id, $writableBookIds, true))
                                    <a href="javascript:void(0);" class="text-secondary ms-1" title="{{ __('devices.remove_from_this_address_book') }}"
                                       aria-label="{{ __('devices.remove_from_book', ['book' => $book->name]) }}"
                                       wire:click="removeFromBook({{ $book->id }})"
                                       wire:confirm="{{ __('devices.remove_this_device_from_book', ['book' => $book->name]) }}"><i class="ri-close-line"></i></a>
                                @endif
                            </span>
                        @empty
                            <span class="text-muted">{{ __('devices.in_no_address_book_you') }}</span>
                        @endforelse
                        @if ($abResult !== '')
                            <div class="fs-13 text-success mt-2"><i class="ri-check-line me-1"></i>{{ $abResult }}</div>
                        @endif
                    </div>
                </div>

                {{-- Its own component (polls on its own clock); here so it is not
                     below a long notification list (#156). --}}
                @if (config('cortendesk.share_links'))
                    @livewire(App\Livewire\DeviceShares::class, ['deviceId' => $device->id], key('device-shares-'.$device->id))
                @endif
            </div>
        </div>
    </div>

    @include('livewire.partials.device-edit-modal')

    @include('livewire.partials.device-delete-modal')
</div>