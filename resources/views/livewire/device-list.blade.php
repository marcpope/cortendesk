<div wire:poll.15s>

    {{-- Summary chips. Buttons, not badges (issue #26): each one filters the
         list underneath, and the active one is marked. Pending only appears
         when something is actually waiting. --}}
    <div class="rd-chiprow">
        <button type="button" class="rd-chip rd-chip-btn rd-tone-blue @if(! $trashed && ! $pendingTab && $status === 'all') rd-chip-active @endif"
                wire:click="filterByChip('all')" title="{{ __('devices.show_every_device') }}">
            <i class="ri-computer-line rd-chip-icon"></i>
            <span class="rd-chip-value">{{ $totalCount }}</span>
            <span class="rd-chip-label">{{ __('common.devices') }}</span>
        </button>
        <button type="button" class="rd-chip rd-chip-btn rd-tone-green @if(! $trashed && ! $pendingTab && $status === 'online') rd-chip-active @endif"
                wire:click="filterByChip('online')" title="{{ __('devices.only_devices_online_now') }}">
            <span class="rd-chip-dot"></span>
            <span class="rd-chip-value">{{ $onlineCount }}</span>
            <span class="rd-chip-label">{{ __('common.online') }}</span>
        </button>
        <button type="button" class="rd-chip rd-chip-btn rd-tone-muted @if(! $trashed && ! $pendingTab && $status === 'offline') rd-chip-active @endif"
                wire:click="filterByChip('offline')" title="{{ __('devices.only_devices_currently_offline') }}">
            <span class="rd-chip-dot"></span>
            <span class="rd-chip-value">{{ $totalCount - $onlineCount }}</span>
            <span class="rd-chip-label">{{ __('devices.offline') }}</span>
        </button>
        @if ($duplicates && ($duplicates->count() > 0 || $status === 'duplicates'))
            <button type="button" class="rd-chip rd-chip-btn rd-tone-amber @if(! $trashed && ! $pendingTab && $status === 'duplicates') rd-chip-active @endif"
                    wire:click="filterByChip('duplicates')" title="{{ __('devices.devices_that_look_like_another') }}">
                <i class="ri-file-copy-2-line rd-chip-icon"></i>
                <span class="rd-chip-value">{{ $duplicates->count() }}</span>
                <span class="rd-chip-label">{{ __('devices.possible_duplicates') }}</span>
            </button>
        @endif
        @if ($pendingCount > 0)
            <button type="button" class="rd-chip rd-chip-btn rd-tone-amber @if($pendingTab) rd-chip-active @endif"
                    wire:click="openPending" title="{{ __('devices.devices_waiting_for_approval') }}">
                <i class="ri-time-line rd-chip-icon"></i>
                <span class="rd-chip-value">{{ $pendingCount }}</span>
                <span class="rd-chip-label">{{ __('common.pending') }}</span>
            </button>
        @endif
    </div>

    <div class="card">

        {{-- Toolbar --}}
        <div class="rd-toolbar">
            <div class="rd-toolbar-search">
                <div class="input-group">
                    <span class="input-group-text"><i class="ri-search-line"></i></span>
                    <input type="search" class="form-control" placeholder="{{ __('devices.search_id_alias_hostname_user') }}"
                           wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <select class="form-select rd-toolbar-filter" wire:model.live="status" @disabled($trashed)>
                <option value="all">{{ __('common.all_statuses') }}</option>
                <option value="online">{{ __('common.online') }}</option>
                <option value="offline">{{ __('devices.offline') }}</option>
                <option value="duplicates">{{ __('devices.duplicates') }}</option>
            </select>
            <select class="form-select rd-toolbar-filter" wire:model.live="group">
                <option value="0">{{ __('devices.all_groups') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
            @if (auth()->user()?->is_admin)
                <select class="form-select rd-toolbar-filter" wire:model.live="owner">
                    <option value="0">{{ __('devices.all_owners') }}</option>
                    <option value="-1">{{ __('devices.unassigned') }}</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->username }}</option>
                    @endforeach
                </select>
            @endif
            {{--
                Sorting on a phone: the card list has no headings to click, so
                the picker and the reverse arrow stand in for them. Options come
                from the component's own allowlist so the two cannot drift.
            --}}
            <div class="rd-sort-mobile d-md-none">
                <select class="form-select rd-toolbar-filter" aria-label="{{ __('devices.sort_devices_by') }}"
                        wire:change="selectSort($event.target.value)">
                    @foreach (\App\Livewire\DeviceList::SORTABLE as $key => $unused)
                        @continue($key === 'owner' && ! auth()->user()?->is_admin)
                        <option value="{{ $key }}" @selected($sortField === $key)>
                            {{ __('devices.sort_column', ['column' => __('devices.col_'.($key === 'id' || isset(\App\Livewire\DeviceList::COLUMNS[$key]) ? $key : 'status'))]) }}
                        </option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-outline-light" wire:click="sortBy('{{ $sortField }}')"
                        aria-label="{{ __('devices.reverse_sort_order') }}" title="{{ __('devices.reverse_sort_order') }}">
                    <i class="{{ $sortDirection === 'asc' ? 'ri-sort-asc' : 'ri-sort-desc' }}"></i>
                </button>
            </div>
            <div class="rd-toolbar-actions">
                <button type="button" class="btn btn-outline-light" wire:click="resetFilters">{{ __('common.reset') }}</button>
                @if ($trashed)
                    <button type="button" class="btn btn-light" wire:click="$set('trashed', false)">
                        <i class="ri-arrow-left-line"></i>{{ __('devices.back_to_devices') }}
                    </button>
                @elseif (auth()->user()?->consoleAllows('device', 'rw'))
                    <button type="button" class="btn btn-primary" wire:click="create">
                        <i class="ri-add-line"></i>{{ __('devices.add_device') }}
                    </button>
                @endif
            </div>
        </div>

        @unless ($trashed)
            <div class="rd-toolbar">
                <a href="javascript:void(0);" class="fs-13 text-muted d-none d-md-inline"
                   wire:click="$toggle('columnsOpen')">
                    <i class="ri-layout-column-line me-1"></i>{{ __('devices.columns') }}
                </a>
                <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="exportCsv"
                   title="{{ __('devices.download_current_view_as_csv') }}">
                    <i class="ri-download-2-line me-1"></i>{{ __('common.export_csv') }}
                </a>
                @if ($pendingCount > 0)
                    <a href="javascript:void(0);" class="fs-13 text-warning fw-semibold" wire:click="$set('pendingTab', true)">
                        <i class="ri-time-line me-1"></i>{{ __('devices.pending_approval') }}
                        <span class="badge bg-warning-subtle text-warning ms-1">{{ $pendingCount }}</span>
                    </a>
                @endif
                <a href="javascript:void(0);" class="fs-13 text-muted ms-auto" wire:click="$set('trashed', true)">
                    <i class="ri-delete-bin-line me-1"></i>{{ __('devices.recycle_bin_count', ['count' => $trashedCount]) }}
                </a>
            </div>

            {{-- Column picker (issue #16). A plain inline panel, not a Bootstrap
                 dropdown: Livewire re-renders on every checkbox and a JS-owned
                 dropdown would snap shut after each click. Desktop-only, like
                 the table it configures — the mobile cards have a fixed layout. --}}
            @if ($columnsOpen)
                <div class="rd-toolbar d-none d-md-flex">
                    <div class="rd-inset w-100">
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            @foreach (\App\Livewire\DeviceList::COLUMNS as $key => $label)
                                @continue ($key === 'owner' && ! auth()->user()?->is_admin)
                                <div class="form-check form-check-inline m-0">
                                    <input type="checkbox" class="form-check-input" id="col-{{ $key }}"
                                           value="{{ $key }}" wire:model.live="columns">
                                    <label class="form-check-label fs-13" for="col-{{ $key }}">{{ __('devices.col_'.$key) }}</label>
                                </div>
                            @endforeach
                            <div class="ms-auto d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetColumns">{{ __('devices.defaults') }}</button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="$set('columnsOpen', false)">{{ __('devices.done') }}</button>
                            </div>
                        </div>
                        <div class="form-text">{{ __('devices.your_selection_is_saved_to') }}</div>
                    </div>
                </div>
            @endif
        @else
            <div class="rd-toolbar">
                <div class="alert alert-warning py-2 mb-0 w-100">
                    <i class="ri-delete-bin-line me-1"></i>{{ __('devices.recycle_bin_devices_here_are') }}
                </div>
            </div>
        @endunless

        {{-- Bulk actions bar (issues #15, #47) — appears when rows are selected,
             on phones too now that the cards carry checkboxes. --}}
        @if (! $trashed && ($selected !== [] || $bulkResult !== ''))
            <div class="rd-toolbar d-flex flex-wrap gap-2 align-items-center">
                @if ($selected !== [])
                    <span class="fs-13 fw-semibold">{{ __('common.count_selected', ['count' => count($selected)]) }}</span>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <button type="button" class="btn btn-sm btn-light" wire:click="openAbPicker">
                            <i class="ri-contacts-book-2-line me-1"></i>{{ __('devices.add_to_address_book') }}
                        </button>
                    @endif
                    @if (auth()->user()?->consoleAllows('device', 'rw'))
                        <button type="button" class="btn btn-sm btn-light" wire:click="openGroupPicker">
                            <i class="ri-folder-transfer-line me-1"></i>{{ __('devices.move_to_group') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-light" wire:click="setIncomingOnly(true)"
                                title="{{ __('devices.id_server_refuses_sessions_these') }}">
                            <i class="ri-login-box-line me-1"></i>{{ __('devices.incoming_only') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-light" wire:click="setIncomingOnly(false)"
                                title="{{ __('devices.let_these_devices_start_sessions') }}">
                            <i class="ri-arrow-left-right-line me-1"></i>{{ __('devices.allow_outgoing') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDeleteSelected">
                            <i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}
                        </button>
                    @endif
                    <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="clearSelection">{{ __('devices.clear') }}</a>
                @endif
                @if ($bulkResult !== '')
                    <span class="fs-13 text-success"><i class="ri-check-line me-1"></i>{{ $bulkResult }}</span>
                @endif
            </div>
        @endif

        {{-- Desktop table (md and up) --}}
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th class="rd-selcol">
                        @unless ($trashed)
                            @php $pageIds = $devices->pluck('id')->map(fn ($i) => (string) $i); @endphp
                            @if ($pageIds->isNotEmpty() && $pageIds->diff($selected)->isEmpty())
                                <input type="checkbox" class="form-check-input" checked
                                       wire:click="clearSelection" title="{{ __('devices.clear_selection') }}">
                            @else
                                <input type="checkbox" class="form-check-input"
                                       wire:click="selectPage" title="{{ __('devices.select_every_row_on_this') }}">
                            @endif
                        @endunless
                    </th>
                    <x-sortable-th field="id" :sort="$sortField" :dir="$sortDirection">{{ __('devices.id') }}</x-sortable-th>
                    @foreach (['device' => 'Device', 'alias' => 'Alias', 'group' => 'Group', 'owner' => 'Owner', 'version' => 'Version'] as $key => $label)
                        @if ($cols[$key])
                            <x-sortable-th :field="$key" :sort="$sortField" :dir="$sortDirection">{{ __('devices.col_'.$key) }}</x-sortable-th>
                        @endif
                    @endforeach
                    @if ($cols['os'])<x-sortable-th field="os" :sort="$sortField" :dir="$sortDirection">OS</x-sortable-th>@endif
                    @if ($cols['username'])<th>User</th>@endif
                    @if ($cols['ip'])<th>IP</th>@endif
                    @if ($cols['lan_ip'])<th title="Last seen during a connection">LAN IP</th>@endif
                    @if ($cols['cpu'])<th>CPU</th>@endif
                    @if ($cols['memory'])<th>Memory</th>@endif
                    @if ($cols['uuid'])<th>UUID</th>@endif
                    @foreach (['first_seen' => 'First Seen', 'last_seen' => 'Last Seen'] as $key => $label)
                        @if ($cols[$key])
                            <x-sortable-th :field="$key" :sort="$sortField" :dir="$sortDirection">{{ __('devices.col_'.$key) }}</x-sortable-th>
                        @endif
                    @endforeach
                    <x-sortable-th field="status" :sort="$sortField" :dir="$sortDirection">{{ __('common.status') }}</x-sortable-th>
                    <th class="text-end">{{ __('common.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($devices as $device)
                    <tr wire:key="d{{ $device->id }}">
                        <td class="rd-selcol">
                            @unless ($trashed)
                                <input type="checkbox" class="form-check-input" value="{{ $device->id }}"
                                       wire:model.live="selected">
                            @endunless
                        </td>
                        <td>
                            <x-platform-icon :platform="$device->platform()" class="me-1"/>
                            @if ($trashed)
                                <span class="fw-semibold">{{ $device->rustdesk_id }}</span>
                            @else
                                <a href="{{ \App\Support\ClientLink::to($device->rustdesk_id) }}" class="fw-semibold"
                                   title="{{ __('common.connect_with_rustdesk') }}">{{ $device->rustdesk_id }}</a>
                            @endif
                        </td>
                        @if ($cols['device'])
                            <td>
                                @if ($trashed)
                                    <span class="rd-cell-title">{{ $device->hostname ?: '—' }}</span>
                                @else
                                    <a href="{{ route('devices.show', $device->id) }}" class="rd-cell-title rd-cell-link">{{ $device->hostname ?: '—' }}</a>
                                @endif
                                {{-- The subtitle only carries what has no column of its own on
                                     screen; enable OS or User and it leaves here (issue #33). --}}
                                @php
                                    $subParts = array_filter([
                                        $cols['os'] ? null : ($device->os ? \Illuminate\Support\Str::limit($device->osDescription(), 28) : null),
                                        $cols['username'] ? null : ($device->username ?: null),
                                    ]);
                                @endphp
                                @if ($subParts !== [])
                                    <span class="rd-cell-sub">{{ implode(' · ', $subParts) }}</span>
                                @endif
                            </td>
                        @endif
                        @if ($cols['alias'])
                            <td>
                                @if ($device->alias && ! $trashed)
                                    <a href="{{ route('devices.show', $device->id) }}" class="rd-cell-link">{{ $device->alias }}</a>
                                @else
                                    {{ $device->alias ?: '—' }}
                                @endif
                            </td>
                        @endif
                        @if ($cols['group'])<td>{{ $device->group?->name ?: '—' }}</td>@endif
                        @if ($cols['owner'])
                            <td>
                                @if ($device->user)
                                    <span class="badge bg-info-subtle text-info">{{ $device->user->username }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        @endif
                        @if ($cols['version'])
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $device->version ?: '?' }}</span></td>
                        @endif
                        @if ($cols['os'])<td class="rd-nowrap" title="{{ $device->os }}">{{ $device->os ? \Illuminate\Support\Str::limit($device->osDescription(), 34) : '—' }}</td>@endif
                        @if ($cols['username'])<td>{{ $device->username ?: '—' }}</td>@endif
                        @if ($cols['ip'])<td class="rd-mono fs-13 rd-nowrap">{{ $device->last_online_ip ?: '—' }}</td>@endif
                        @if ($cols['lan_ip'])
                            <td class="rd-mono fs-13 rd-nowrap"
                                title="{{ $device->lan_ip ? __('devices.seen_during_connection', ['when' => $device->lan_ip_seen_at?->diffForHumans()]) : __('devices.not_seen_yet_learned_when') }}">{{ $device->lan_ip ?: '—' }}</td>
                        @endif
                        @if ($cols['cpu'])
                            <td><span class="fs-13" title="{{ $device->cpu }}">{{ $device->cpu ? \Illuminate\Support\Str::limit($device->cpu, 24) : '—' }}</span></td>
                        @endif
                        @if ($cols['memory'])<td class="fs-13">{{ $device->memory ?: '—' }}</td>@endif
                        @if ($cols['uuid'])
                            <td><span class="rd-mono fs-13" title="{{ $device->uuid }}">{{ $device->uuid ? \Illuminate\Support\Str::limit($device->uuid, 12) : '—' }}</span></td>
                        @endif
                        @if ($cols['first_seen'])
                            <td><span title="{{ $device->created_at }}">{{ $device->created_at?->format('Y-m-d') ?? '—' }}</span></td>
                        @endif
                        @if ($cols['last_seen'])
                            <td>
                                <span title="{{ $device->last_online_at }}">
                                    {{ $device->last_online_at?->diffForHumans() ?? __('common.never') }}
                                </span>
                            </td>
                        @endif
                        <td>
                            @if ($trashed)
                                <span class="badge bg-warning-subtle text-warning">{{ __('devices.deleted') }}</span>
                            @elseif ($device->isOnline())
                                <span class="badge bg-success-subtle text-success"><i class="rd-dot"></i>{{ __('common.online') }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary"><i class="rd-dot"></i>{{ __('devices.offline') }}</span>
                            @endif
                            @if ($device->isIncomingOnly())
                                <span class="badge bg-info-subtle text-info ms-1" title="{{ __('devices.can_be_controlled_cannot_start') }}">{{ __('devices.incoming_only') }}</span>
                            @endif
                            @include('livewire.partials.device-duplicate-badge', ['class' => 'ms-1'])
                        </td>
                        <td class="text-end rd-rowact">
                            @if ($trashed)
                                @if (auth()->user()?->consoleAllows('device', 'rw'))
                                    <a href="javascript:void(0);" class="text-success me-2" wire:click="restoreDevice({{ $device->id }})">{{ __('common.restore') }}</a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="forceDeleteDevice({{ $device->id }})"
                                       wire:confirm="{{ __('devices.permanently_delete_device_id_this', ['id' => $device->rustdesk_id]) }}">{{ __('devices.destroy') }}</a>
                                @endif
                            @else
                                {{-- Row actions are neutral; only the destructive one
                                     takes a colour. Two full-colour ordinary actions
                                     side by side read louder than Edit and make this
                                     the only cell in the console with four colours. --}}
                                @if (config('cortendesk.native_webclient'))
                                    <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                                       target="cortendesk-webclient" rel="noopener" class="rd-act me-2"
                                       title="{{ __('devices.connect_in_browser_native_client') }}"><i class="ri-remote-control-line me-1"></i>{{ __('devices.connect') }}</a>
                                @endif
                                @if (config('cortendesk.webclient_url'))
                                    <a href="{{ config('cortendesk.webclient_url') }}?id={{ $device->rustdesk_id }}"
                                       target="cortendesk-webclient" rel="noopener" class="rd-act me-2"
                                       title="{{ __('devices.connect_in_browser') }}">{{ __('common.web_client') }}</a>
                                @endif
                                <a href="{{ route('devices.show', $device->id) }}" class="rd-act me-2" title="{{ __('common.view_details') }}"><i class="ri-eye-line"></i></a>
                                @if (auth()->user()?->consoleAllows('device', 'rw'))
                                    <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $device->id }})">{{ __('common.edit') }}</a>
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="confirmDelete({{ $device->id }})">{{ __('common.delete') }}</a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspan }}" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="{{ $trashed ? 'ri-delete-bin-line' : 'ri-computer-line' }}"></i></div>
                                <p class="rd-empty-title">{{ $trashed ? __('devices.recycle_bin_is_empty') : __('devices.no_devices_match_your_filters') }}</p>
                                <p class="rd-empty-text">
                                    {{ $trashed
                                        ? __('devices.deleted_devices_land_here_until')
                                        : __('devices.devices_register_themselves_first_time') }}
                                </p>
                                @unless ($trashed)
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                                    @if (auth()->user()?->consoleAllows('setting', 'r'))
                                        <a href="{{ route('setup') }}" class="btn btn-sm btn-primary">{{ __('devices.set_up_client') }}</a>
                                    @endif
                                @endunless
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
                <div class="rd-mini" wire:key="m{{ $device->id }}">
                    <div class="rd-mini-head">
                        <div class="d-flex align-items-center gap-2 min-width-0">
                            <x-platform-icon :platform="$device->platform()" size="fs-22"/>
                            <div class="min-width-0">
                                @if ($trashed)
                                    <span class="rd-mini-title text-truncate">{{ $device->rustdesk_id }}</span>
                                @else
                                    <a href="{{ \App\Support\ClientLink::to($device->rustdesk_id) }}" class="rd-mini-title text-truncate"
                                       title="{{ __('common.connect_with_rustdesk') }}">{{ $device->rustdesk_id }}</a>
                                @endif
                                @if ($trashed)
                                    <span class="rd-mini-sub text-truncate">{{ $device->alias ?: $device->hostname }}</span>
                                @else
                                    <a href="{{ route('devices.show', $device->id) }}" class="rd-mini-sub text-truncate">{{ $device->alias ?: $device->hostname ?: __('logs.details') }}</a>
                                @endif
                            </div>
                        </div>
                        @unless ($trashed)
                            <input type="checkbox" class="form-check-input flex-shrink-0" value="{{ $device->id }}"
                                   wire:model.live="selected" aria-label="{{ __('devices.select_device_id', ['id' => $device->rustdesk_id]) }}">
                        @endunless
                        @if ($trashed)
                            <span class="badge bg-warning-subtle text-warning flex-shrink-0">{{ __('devices.deleted') }}</span>
                        @elseif ($device->isOnline())
                            <span class="badge bg-success-subtle text-success flex-shrink-0">{{ __('common.online') }}</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary flex-shrink-0">{{ __('devices.offline') }}</span>
                        @endif
                    </div>
                    @if ($device->isIncomingOnly() || $duplicates?->has($device->id))
                        <div class="mb-1 d-flex flex-wrap gap-1">
                            @if ($device->isIncomingOnly())
                                <span class="badge bg-info-subtle text-info" title="{{ __('devices.can_be_controlled_cannot_start') }}">{{ __('devices.incoming_only') }}</span>
                            @endif
                            @include('livewire.partials.device-duplicate-badge')
                        </div>
                    @endif
                    <div class="rd-mini-foot">
                        <span class="rd-mini-sub min-width-0">
                            {{ $device->username }} · v{{ $device->version ?: '?' }} ·
                            <span class="text-nowrap">{{ $device->last_online_at?->diffForHumans(short: true) ?? __('common.never') }}</span>
                        </span>
                        <div class="rd-mini-acts">
                            @if ($trashed)
                                @if (auth()->user()?->consoleAllows('device', 'rw'))
                                    <a href="javascript:void(0);" class="rd-iconbtn text-success" title="{{ __('common.restore') }}" wire:click="restoreDevice({{ $device->id }})"><i class="ri-arrow-go-back-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('devices.destroy') }}"
                                       wire:click="forceDeleteDevice({{ $device->id }})"
                                       wire:confirm="{{ __('devices.permanently_delete_device_id', ['id' => $device->rustdesk_id]) }}"><i class="ri-close-circle-line"></i></a>
                                @endif
                            @else
                                @if (config('cortendesk.native_webclient'))
                                    <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                                       target="cortendesk-webclient" rel="noopener" class="rd-iconbtn"
                                       title="{{ __('devices.connect_in_browser_native_client') }}"><i class="ri-remote-control-line"></i></a>
                                @endif
                                @if (config('cortendesk.webclient_url'))
                                    <a href="{{ config('cortendesk.webclient_url') }}?id={{ $device->rustdesk_id }}"
                                       target="cortendesk-webclient" rel="noopener" class="rd-iconbtn"
                                       title="{{ __('devices.connect_in_browser') }}"><i class="ri-global-line"></i></a>
                                @endif
                                <a href="{{ route('devices.show', $device->id) }}" class="rd-iconbtn" title="{{ __('common.view_details') }}"><i class="ri-eye-line"></i></a>
                                @if (auth()->user()?->consoleAllows('device', 'rw'))
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}" wire:click="edit({{ $device->id }})"><i class="ri-pencil-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                       wire:click="confirmDelete({{ $device->id }})"><i class="ri-delete-bin-line"></i></a>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="rd-empty">
                    <div class="rd-empty-icon"><i class="{{ $trashed ? 'ri-delete-bin-line' : 'ri-computer-line' }}"></i></div>
                    <p class="rd-empty-title">{{ $trashed ? __('devices.recycle_bin_is_empty') : __('devices.no_devices_match_your_filters') }}</p>
                    <p class="rd-empty-text">
                        {{ $trashed
                            ? __('devices.deleted_devices_land_here_until')
                            : __('devices.devices_register_themselves_first_time') }}
                    </p>
                    @unless ($trashed)
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                        @if (auth()->user()?->consoleAllows('setting', 'r'))
                            <a href="{{ route('setup') }}" class="btn btn-sm btn-primary">{{ __('devices.set_up_client') }}</a>
                        @endif
                    @endunless
                </div>
            @endforelse
        </div>

        <div class="rd-tablefoot">
            <span>{{ __('common.showing_from_to_of_total', ['from' => $devices->firstItem() ?? 0, 'to' => $devices->lastItem() ?? 0, 'total' => $devices->total()]) }}</span>
            {{ $devices->links() }}
        </div>
    </div>

    {{-- "Add to Address Book" picker (issue #15) --}}
    @if ($abPickerOpen)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.6);" wire:keydown.escape="closeAbPicker">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="addSelectedToBook">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ trans_choice('devices.add_count_device_to_address', count($selected)) }}</h5>
                            <button type="button" class="btn-close" wire:click="closeAbPicker"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label">{{ __('devices.address_book') }}</label>
                            <select class="form-select @error('abBookId') is-invalid @enderror" wire:model="abBookId">
                                <option value="0">{{ __('devices.choose') }}</option>
                                @foreach ($books as $book)
                                    <option value="{{ $book->id }}">
                                        {{ $book->name }}{{ $book->is_personal ? ' (personal)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('abBookId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">
                                {{ __('devices.only_books_you_can_add') }}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeAbPicker">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('common.add') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @include('livewire.partials.device-edit-modal')

    @include('livewire.partials.device-delete-modal')

    {{-- "Move to Group" picker (issue #47) --}}
    @if ($groupPickerOpen)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="move-group-title" style="background: rgba(0,0,0,.6);" wire:keydown.escape="closeGroupPicker">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="moveSelectedToGroup">
                        <div class="modal-header">
                            <h5 id="move-group-title" class="modal-title">{{ trans_choice('devices.move_count_device_to_group', count($selected)) }}</h5>
                            <button type="button" class="btn-close" aria-label="{{ __('devices.close_move_to_group_dialog') }}" wire:click="closeGroupPicker"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label" for="move-group-id">{{ __('common.device_group') }}</label>
                            <select id="move-group-id" class="form-select @error('moveGroupId') is-invalid @enderror" wire:model="moveGroupId">
                                <option value="-1">{{ __('devices.choose') }}</option>
                                <option value="0">{{ __('devices.no_group') }}</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                                @endforeach
                            </select>
                            @error('moveGroupId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">{{ __('devices.only_device_groups_you_can') }}</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeGroupPicker">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('devices.move') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
