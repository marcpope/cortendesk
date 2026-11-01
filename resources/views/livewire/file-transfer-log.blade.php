<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="{{ __('logs.search_device_id_from_path') }}"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
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
                    <th>{{ __('common.from') }}</th>
                    <th>{{ __('logs.direction') }}</th>
                    <th>{{ __('logs.path') }}</th>
                    <th>{{ __('logs.files') }}</th>
                    <th>{{ __('common.ip') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transfers as $transfer)
                    <tr wire:key="t{{ $transfer->id }}">
                        <td>
                            <span title="{{ $transfer->created_at }}">{{ $transfer->created_at?->diffForHumans() }}</span>
                        </td>
                        <td><span class="fw-semibold">{{ $transfer->rustdesk_id }}</span></td>
                        <td>
                            <span class="rd-cell-title">{{ $transfer->from_peer ?: '—' }}</span>
                            <span class="rd-cell-sub">{{ $transfer->from_name }}</span>
                        </td>
                        <td>
                            @if ($transfer->direction === 1)
                                <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>{{ __('common.receive') }}</span>
                            @else
                                <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>{{ __('common.send') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($transfer->isClipboard())
                                <span class="badge bg-secondary-subtle text-secondary me-1" title="{{ __('logs.copied_and_pasted_through_clipboard') }}">{{ __('common.clipboard') }}</span>
                            @endif
                            <span class="d-inline-block text-truncate align-middle rd-mono" style="max-width: 240px;"
                                  title="{{ $transfer->isClipboard() ? implode(', ', $transfer->fileNames()) : $transfer->path }}">{{ $transfer->pathLabel() ?: '—' }}</span>
                        </td>
                        <td>{{ $transfer->file_count }}</td>
                        <td class="rd-mono">{{ $transfer->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-file-transfer-line"></i></div>
                                <p class="rd-empty-title">{{ __('logs.no_file_transfers_match_your') }}</p>
                                <p class="rd-empty-text">{{ __('logs.files_moved_during_remote_session') }}</p>
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
            @forelse ($transfers as $transfer)
                <div class="rd-mini" wire:key="mt{{ $transfer->id }}">
                    <div class="rd-mini-head">
                        <div class="min-width-0">
                            <span class="rd-mini-title">{{ $transfer->rustdesk_id }}</span>
                            <span class="rd-mini-sub">
                                {{ __('logs.from_peer', ['peer' => $transfer->from_peer ?: '—']) }}@if ($transfer->from_name) ({{ $transfer->from_name }})@endif
                            </span>
                        </div>
                        @if ($transfer->direction === 1)
                            <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>{{ __('common.receive') }}</span>
                        @else
                            <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>{{ __('common.send') }}</span>
                        @endif
                    </div>
                    <div class="mt-2">
                        <small class="d-block text-truncate rd-mono" title="{{ $transfer->isClipboard() ? implode(', ', $transfer->fileNames()) : $transfer->path }}">
                            @if ($transfer->isClipboard())
                                <span class="badge bg-secondary-subtle text-secondary me-1">{{ __('common.clipboard') }}</span>
                            @else
                                <i class="ri-file-line me-1"></i>
                            @endif
                            {{ $transfer->pathLabel() ?: '—' }}
                        </small>
                        <span class="rd-mini-sub">
                            {{ trans_choice('common.count_files', $transfer->file_count) }} ·
                            {{ $transfer->ip ?: '—' }} ·
                            {{ $transfer->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-file-transfer-line"></i></div>
                    <p class="rd-empty-title">{{ __('logs.no_file_transfers_match_your') }}</p>
                    <p class="rd-empty-text">{{ __('logs.files_moved_during_remote_session') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>{{ __('common.showing_from_to_of_total', ['from' => $transfers->firstItem() ?? 0, 'to' => $transfers->lastItem() ?? 0, 'total' => $transfers->total()]) }}</span>
            {{ $transfers->links() }}
        </div>
    </div>
</div>
