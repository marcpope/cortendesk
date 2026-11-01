<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="{{ __('logs.search_device_id_info') }}"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <select class="form-select rd-toolbar-filter" wire:model.live="type" aria-label="{{ __('logs.alarm_type') }}">
                <option value="">{{ __('logs.all_types') }}</option>
                @foreach ($types as $typ => $meta)
                    <option value="{{ $typ }}">{{ \App\Support\Label::get('alarm_type', $typ, $meta['label']) }}</option>
                @endforeach
            </select>
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateFrom" aria-label="{{ __('logs.from_date') }}">
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateTo" aria-label="{{ __('logs.to_date') }}">
            <select class="form-select rd-toolbar-narrow" wire:model.live="perPage" aria-label="{{ __('logs.rows_per_page') }}">
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-outline-light" wire:click="resetFilters">{{ __('common.reset') }}</button>
                <button type="button" class="btn btn-primary" wire:click="export">
                    <i class="ri-download-2-line"></i>{{ __('common.export_csv') }}
                </button>
            </div>
        </div>

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>{{ __('logs.when') }}</th>
                    <th>{{ __('common.device') }}</th>
                    <th>{{ __('common.type') }}</th>
                    <th>{{ __('logs.details') }}</th>
                    <th>{{ __('logs.conn') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($alarms as $alarm)
                    <tr wire:key="a{{ $alarm->id }}">
                        <td>
                            <span title="{{ $alarm->created_at }}">{{ $alarm->created_at?->diffForHumans() }}</span>
                        </td>
                        <td>
                            @if ($alarm->rustdesk_id === \App\Models\AlarmLog::CONSOLE_SOURCE)
                                <span class="badge bg-secondary-subtle text-secondary"><i class="ri-terminal-box-line me-1"></i>{{ __('common.console') }}</span>
                            @else
                                <span class="fw-semibold">
                                {{ $alarm->rustdesk_id === \App\Models\AlarmLog::CONSOLE_SOURCE ? __('common.console') : $alarm->rustdesk_id }}
                            </span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ $alarm->typeSeverity() }}-subtle text-{{ $alarm->typeSeverity() }}">
                                <i class="ri-alarm-warning-line me-1"></i>{{ $alarm->typeLabel() }}
                            </span>
                        </td>
                        <td>
                            @if ($alarm->infoPairs() !== [])
                                <small class="d-inline-block text-truncate align-middle" style="max-width: 320px;"
                                       title="{{ $alarm->info }}">
                                    @foreach ($alarm->infoPairs() as $key => $value)
                                        <span class="me-2"><span class="text-muted">{{ $key }}:</span> {{ $value }}</span>
                                    @endforeach
                                </small>
                            @else
                                <span class="d-inline-block text-truncate align-middle" style="max-width: 320px;"
                                      title="{{ $alarm->info }}">{{ $alarm->info ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="rd-mono">{{ $alarm->conn_id ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-alarm-warning-line"></i></div>
                                <p class="rd-empty-title">{{ __('logs.no_alarms_match_your_filters') }}</p>
                                <p class="rd-empty-text">{{ __('logs.nothing_has_been_flagged_in') }}</p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile card list (below md) --}}
        <div class="d-md-none rd-cardlist">
            @forelse ($alarms as $alarm)
                <div class="rd-mini" wire:key="ma{{ $alarm->id }}">
                    <div class="rd-mini-head">
                        <span class="rd-mini-title">{{ $alarm->rustdesk_id }}</span>
                        <span class="badge bg-{{ $alarm->typeSeverity() }}-subtle text-{{ $alarm->typeSeverity() }}">
                            {{ $alarm->typeLabel() }}
                        </span>
                    </div>
                    <div class="mt-2">
                        @if ($alarm->info)
                            <small class="d-block text-truncate" title="{{ $alarm->info }}">
                                <i class="ri-information-line me-1"></i>{{ $alarm->info }}
                            </small>
                        @endif
                        <span class="rd-mini-sub">
                            @if ($alarm->conn_id)conn {{ $alarm->conn_id }} · @endif{{ $alarm->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-alarm-warning-line"></i></div>
                    <p class="rd-empty-title">{{ __('logs.no_alarms_match_your_filters') }}</p>
                    <p class="rd-empty-text">{{ __('logs.nothing_has_been_flagged_in') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>{{ __('common.showing_from_to_of_total', ['from' => $alarms->firstItem() ?? 0, 'to' => $alarms->lastItem() ?? 0, 'total' => $alarms->total()]) }}</span>
            {{ $alarms->links() }}
        </div>
    </div>
</div>
