<div>
    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="Search device ID, from, path, file name, IP…"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateFrom" aria-label="From date">
            <input type="date" class="form-control rd-toolbar-filter" wire:model.live="dateTo" aria-label="To date">
            <select class="form-select rd-toolbar-narrow" wire:model.live="perPage" aria-label="Rows per page">
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-outline-light" wire:click="resetFilters">Reset</button>
                <button type="button" class="btn btn-primary" wire:click="export">
                    <i class="ri-download-2-line"></i>Export CSV
                </button>
            </div>
        </div>

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Device</th>
                    <th>From</th>
                    <th>Direction</th>
                    <th>Path</th>
                    <th>Files</th>
                    <th>IP</th>
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
                                <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>Receive</span>
                            @else
                                <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>Send</span>
                            @endif
                        </td>
                        <td>
                            @if ($transfer->isClipboard())
                                <span class="badge bg-secondary-subtle text-secondary me-1" title="Copied and pasted through the clipboard">Clipboard</span>
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
                                <p class="rd-empty-title">No file transfers match your filters.</p>
                                <p class="rd-empty-text">Files moved during a remote session are recorded here.</p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
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
                                from {{ $transfer->from_peer ?: '—' }}@if ($transfer->from_name) ({{ $transfer->from_name }})@endif
                            </span>
                        </div>
                        @if ($transfer->direction === 1)
                            <span class="badge bg-warning-subtle text-warning"><i class="ri-arrow-down-line me-1"></i>Receive</span>
                        @else
                            <span class="badge bg-info-subtle text-info"><i class="ri-arrow-up-line me-1"></i>Send</span>
                        @endif
                    </div>
                    <div class="mt-2">
                        <small class="d-block text-truncate rd-mono" title="{{ $transfer->isClipboard() ? implode(', ', $transfer->fileNames()) : $transfer->path }}">
                            @if ($transfer->isClipboard())
                                <span class="badge bg-secondary-subtle text-secondary me-1">Clipboard</span>
                            @else
                                <i class="ri-file-line me-1"></i>
                            @endif
                            {{ $transfer->pathLabel() ?: '—' }}
                        </small>
                        <span class="rd-mini-sub">
                            {{ $transfer->file_count }} {{ Str::plural('file', $transfer->file_count) }} ·
                            {{ $transfer->ip ?: '—' }} ·
                            {{ $transfer->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-file-transfer-line"></i></div>
                    <p class="rd-empty-title">No file transfers match your filters.</p>
                    <p class="rd-empty-text">Files moved during a remote session are recorded here.</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>Showing {{ $transfers->firstItem() ?? 0 }}–{{ $transfers->lastItem() ?? 0 }} of {{ $transfers->total() }}</span>
            {{ $transfers->links() }}
        </div>
    </div>
</div>
