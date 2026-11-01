<div wire:poll.15s>
    <div class="card">

        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h4 class="header-title">{{ __('devices.pending_approval') }}
                    <span class="badge bg-warning-subtle text-warning ms-1">{{ $pendingCount }}</span>
                </h4>
                <p class="rd-card-sub mb-0">{{ __('devices.new_devices_held_by_deployment') }}</p>
            </div>
            <div class="rd-card-actions">
                <button type="button" class="btn btn-light" wire:click="$set('pendingTab', false)">
                    <i class="ri-arrow-left-line me-1"></i>{{ __('devices.back_to_devices') }}
                </button>
            </div>
        </div>

        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="{{ __('devices.search_id_hostname_user_ip') }}"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
        </div>

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>{{ __('devices.id') }}</th>
                    <th>{{ __('common.device') }}</th>
                    <th>{{ __('common.os') }}</th>
                    <th>{{ __('common.version') }}</th>
                    <th>{{ __('devices.first_seen') }}</th>
                    <th>{{ __('common.ip') }}</th>
                    <th class="text-end">{{ __('common.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($devices as $device)
                    <tr wire:key="p{{ $device->id }}">
                        <td>
                            <x-platform-icon :platform="$device->platform()" class="me-1"/>
                            <span class="fw-semibold">{{ $device->rustdesk_id }}</span>
                        </td>
                        <td>
                            <span class="rd-cell-title">{{ $device->hostname ?: '—' }}</span>
                            <span class="rd-cell-sub">{{ $device->username }}</span>
                        </td>
                        <td>{{ $device->os ?: '—' }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">{{ $device->version ?: '?' }}</span></td>
                        <td><span title="{{ $device->created_at }}">{{ $device->created_at?->diffForHumans() ?? '—' }}</span></td>
                        <td class="rd-mono">{{ $device->last_online_ip ?: '—' }}</td>
                        <td class="text-end rd-rowact">
                            @if (auth()->user()?->consoleAllows('device', 'rw'))
                                <a href="javascript:void(0);" class="text-success me-2" wire:click="approveDevice({{ $device->id }})">
                                    <i class="ri-check-line me-1"></i>{{ __('devices.approve') }}
                                </a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="rejectDevice({{ $device->id }})"
                                   wire:confirm="{{ __('devices.reject_device_id_it_will', ['id' => $device->rustdesk_id]) }}">
                                    <i class="ri-close-line me-1"></i>{{ __('devices.reject') }}
                                </a>
                            @else
                                <span class="text-muted">{{ __('devices.view_only') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-shield-check-line"></i></div>
                                <p class="rd-empty-title">{{ __('devices.no_devices_are_awaiting_approval') }}</p>
                                <p class="rd-empty-text">{{ __('devices.everything_deployment_gate_has_held') }}</p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="$set('pendingTab', false)">{{ __('devices.back_to_devices') }}</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile card list (below md) --}}
        <div class="d-md-none rd-cardlist">
            @forelse ($devices as $device)
                <div class="rd-mini" wire:key="pm{{ $device->id }}">
                    <div class="rd-mini-head">
                        <div class="d-flex align-items-center gap-2 min-width-0">
                            <x-platform-icon :platform="$device->platform()" size="fs-22"/>
                            <div class="min-width-0">
                                <span class="rd-mini-title text-truncate">{{ $device->rustdesk_id }}</span>
                                <span class="rd-mini-sub text-truncate">{{ $device->hostname ?: $device->os }}</span>
                            </div>
                        </div>
                        <span class="badge bg-warning-subtle text-warning flex-shrink-0">{{ __('common.pending') }}</span>
                    </div>
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub min-width-0">
                            {{ $device->username }} · v{{ $device->version ?: '?' }} ·
                            <span class="text-nowrap">{{ $device->created_at?->diffForHumans(short: true) ?? '—' }}</span>
                        </span>
                        <div class="rd-mini-acts">
                            @if (auth()->user()?->consoleAllows('device', 'rw'))
                                <a href="javascript:void(0);" class="rd-iconbtn text-success" title="{{ __('devices.approve') }}" wire:click="approveDevice({{ $device->id }})"><i class="ri-check-line"></i></a>
                                <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('devices.reject') }}"
                                   wire:click="rejectDevice({{ $device->id }})"
                                   wire:confirm="{{ __('devices.reject_device_id', ['id' => $device->rustdesk_id]) }}"><i class="ri-close-line"></i></a>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="ri-shield-check-line"></i></div>
                    <p class="rd-empty-title">{{ __('devices.no_devices_are_awaiting_approval') }}</p>
                    <p class="rd-empty-text">{{ __('devices.everything_deployment_gate_has_held') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="$set('pendingTab', false)">{{ __('devices.back_to_devices') }}</button>
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>{{ __('common.showing_from_to_of_total', ['from' => $devices->firstItem() ?? 0, 'to' => $devices->lastItem() ?? 0, 'total' => $devices->total()]) }}</span>
            {{ $devices->links() }}
        </div>
    </div>
</div>
