{{-- Connection report (PR #173): time between who connected and what, over a date range. --}}
@php
    $fmt = fn ($t) => $t?->format('Y-m-d H:i');
    $sides = [
        'source' => __('report.from'),
        'target' => __('report.to'),
    ];
    $fields = [
        'device_group_id' => __('report.device_group'),
        'user_id' => __('report.user'),
        'user_group_id' => __('report.user_group'),
        'rustdesk_id' => __('report.device'),
    ];
    $groupOptions = $devices->pluck('group')->filter()->unique('id')->sortBy('name');
    $ownerOptions = $devices->pluck('user')->filter()->unique('id')->sortBy('username');
    $userGroupOptions = $devices->pluck('user')->filter()->flatMap(fn ($u) => $u->groups)->unique('id')->sortBy('name');
    $deviceOptions = $devices->sortBy('rustdesk_id');
    $deviceName = fn ($id) => ($d = $devices->get($id)) ? ($d->alias ?: $d->hostname) : null;
@endphp
<div>
    {{-- ============================ FILTERS ============================ --}}
    <div class="card">
        <div class="card-body">
            <div class="row g-3 align-items-end mb-1">
                <div class="col-6 col-md-3">
                    <label class="form-label" for="report-from">{{ __('report.date_from') }}</label>
                    <input id="report-from" type="date" class="form-control @error('dateFrom') is-invalid @enderror" wire:model.live="dateFrom">
                    @error('dateFrom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="report-to">{{ __('report.date_to') }}</label>
                    <input id="report-to" type="date" class="form-control @error('dateTo') is-invalid @enderror" wire:model.live="dateTo">
                    @error('dateTo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6 d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-light" wire:click="period('this')">{{ __('report.this_month') }}</button>
                    <button type="button" class="btn btn-outline-light" wire:click="period('last')">{{ __('report.last_month') }}</button>
                </div>
            </div>

            <div class="row g-3">
                @foreach ($sides as $side => $legend)
                    <fieldset class="col-md-6">
                        <legend class="fs-14 fw-semibold mt-3 mb-0">{{ $legend }}</legend>
                        <div class="row g-2">
                            @foreach ($fields as $field => $caption)
                                <div class="col-sm-6">
                                    <label class="form-label mt-2 mb-1 fs-13" for="{{ $side }}-{{ $field }}">{{ $caption }}</label>
                                    <select id="{{ $side }}-{{ $field }}" class="form-select" wire:model.live="{{ $side }}.{{ $field }}">
                                        <option value="">{{ __('report.all') }}</option>
                                        @if ($field === 'device_group_id')
                                            @foreach ($groupOptions as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                                        @elseif ($field === 'user_id')
                                            @foreach ($ownerOptions as $u)<option value="{{ $u->id }}">{{ $u->username }}</option>@endforeach
                                        @elseif ($field === 'user_group_id')
                                            @foreach ($userGroupOptions as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                                        @else
                                            @foreach ($deviceOptions as $d)<option value="{{ $d->rustdesk_id }}">{{ $d->alias ?: $d->hostname ?: $d->rustdesk_id }} ({{ $d->rustdesk_id }})</option>@endforeach
                                        @endif
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>

            <details class="rd-mon-details fs-13 text-muted mt-3">
                <summary>{{ __('report.method') }}</summary>
                <p class="mt-2 mb-1">{{ __('report.help', ['timezone' => config('app.timezone')]) }}</p>
                <p class="mb-0">{{ __('report.attribution') }}</p>
            </details>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="button" class="btn btn-primary" wire:click="export" wire:loading.attr="disabled">
                    <i class="ri-download-2-line me-1"></i>{{ __('report.export') }}
                </button>
                <button type="button" class="btn btn-outline-light" wire:click="exportXlsx" wire:loading.attr="disabled">
                    <i class="ri-file-excel-2-line me-1"></i>{{ __('report.export_xlsx') }}
                </button>
            </div>
        </div>
    </div>

    {{-- ============================ TOTALS ============================ --}}
    <div class="rd-stat-grid rd-stat-grid-4">
        @foreach ([
            ['ri-remote-control-line', 'rd-tone-blue', __('report.sessions'), $count],
            ['ri-time-line', 'rd-tone-green', __('report.duration'), \App\Support\SessionTime::format($total)],
            ['ri-user-line', 'rd-tone-purple', __('report.unique'), \App\Support\SessionTime::format($unique)],
            ['ri-loader-4-line', 'rd-tone-amber', __('report.active'), $active],
        ] as [$icon, $tone, $label, $value])
            <div class="card mb-0"><div class="card-body">
                <div class="rd-stat {{ $tone }}">
                    <div class="rd-stat-head">
                        <span class="rd-stat-icon"><i class="{{ $icon }}"></i></span>
                        <div class="min-width-0">
                            <div class="rd-stat-label" title="{{ $label }}">{{ $label }}</div>
                            <div class="rd-stat-value">{{ $value }}</div>
                        </div>
                    </div>
                </div>
            </div></div>
        @endforeach
    </div>

    {{-- ============================ BREAKDOWNS ============================ --}}
    <div class="row">
        @foreach ([
            [__('report.customers'), __('report.device_group'), $breakdown],
            [__('report.operators'), __('report.from'), $operators],
        ] as [$title, $column, $list])
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ $title }}</h5></div>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-centered mb-0">
                            <thead><tr><th>{{ $column }}</th><th class="text-end">{{ __('report.sessions') }}</th><th class="text-end">{{ __('report.duration') }}</th></tr></thead>
                            <tbody>
                            @forelse ($list as $item)
                                <tr><td>{{ $item['name'] }}</td><td class="text-end">{{ $item['count'] }}</td><td class="text-end rd-mono">{{ \App\Support\SessionTime::format($item['seconds']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('report.empty') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none rd-cardlist">
                        @forelse ($list as $item)
                            <div class="rd-mini">
                                <div class="rd-mini-head">
                                    <span class="rd-mini-title min-width-0 text-break">{{ $item['name'] }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary rd-mono">{{ \App\Support\SessionTime::format($item['seconds']) }}</span>
                                </div>
                                <span class="rd-mini-sub">{{ __('report.sessions') }}: {{ $item['count'] }}</span>
                            </div>
                        @empty
                            <p class="text-muted mb-0">{{ __('report.empty') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ============================ SESSIONS ============================ --}}
    <div class="card">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>{{ __('report.from') }}</th>
                    <th>{{ __('report.to') }}</th>
                    <th>{{ __('report.start') }}</th>
                    <th>{{ __('report.end') }}</th>
                    <th class="text-end">{{ __('report.seconds') }}</th>
                    <th>{{ __('report.note') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="report-{{ $row->id }}">
                        <td>
                            <span class="rd-cell-title">{{ $row->from_peer }}</span>
                            <span class="rd-cell-sub">{{ $row->from_name }}</span>
                        </td>
                        <td>
                            <span class="rd-cell-title">{{ $row->rustdesk_id }}</span>
                            <span class="rd-cell-sub">{{ $deviceName($row->rustdesk_id) }}</span>
                        </td>
                        <td class="text-nowrap">{{ $fmt($row->created_at) }}</td>
                        <td class="text-nowrap">
                            @if ($row->closed_at)
                                {{ $fmt($row->closed_at) }}
                            @else
                                <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ __('common.active') }}</span>
                            @endif
                        </td>
                        <td class="text-end rd-mono">{{ $row->closed_at ? \App\Support\SessionTime::format(\App\Livewire\ConnectionReport::secondsIn($row, $start, $end)) : '' }}</td>
                        <td class="rd-conn-note">
                            @if ($row->note)
                                <span class="rd-cell-sub text-truncate" title="{{ $row->note }}">{{ $row->note }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">{{ __('report.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-md-none rd-cardlist">
            @forelse ($rows as $row)
                <div class="rd-mini" wire:key="report-m{{ $row->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">{{ $deviceName($row->rustdesk_id) ?: $row->rustdesk_id }}</span>
                            <span class="rd-mini-sub">{{ __('logs.from_peer', ['peer' => $row->from_peer]) }}@if ($row->from_name) ({{ $row->from_name }})@endif</span>
                        </div>
                        @if ($row->closed_at)
                            <span class="badge bg-secondary-subtle text-secondary rd-mono">{{ \App\Support\SessionTime::format(\App\Livewire\ConnectionReport::secondsIn($row, $start, $end)) }}</span>
                        @else
                            <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ __('common.active') }}</span>
                        @endif
                    </div>
                    <span class="rd-mini-sub">{{ $fmt($row->created_at) }}</span>
                    @if ($row->note)
                        <div class="rd-mini-sub rd-conn-note-full"><i class="ri-sticky-note-line"></i> {{ $row->note }}</div>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">{{ __('report.empty') }}</p>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>{{ __('common.showing_from_to_of_total', ['from' => $rows->firstItem() ?? 0, 'to' => $rows->lastItem() ?? 0, 'total' => $rows->total()]) }}</span>
            {{ $rows->links() }}
        </div>
    </div>
</div>
