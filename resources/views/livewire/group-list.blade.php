<div>
    <div class="card">

            {{-- Tabs --}}
            <ul class="nav nav-tabs nav-bordered rd-tabbar">
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'devices' ? 'active' : '' }}"
                       wire:click="setTab('devices')">
                        <i class="ri-computer-line me-1"></i>{{ __('groups.device_groups') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $deviceGroups->count() }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'users' ? 'active' : '' }}"
                       wire:click="setTab('users')">
                        <i class="ri-group-line me-1"></i>{{ __('groups.user_groups') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $userGroups->count() }}</span>
                    </a>
                </li>
            </ul>

            @php
                $current = $tab === 'users' ? $userGroups : $deviceGroups;
                $countLabel = $tab === 'users' ? __('common.users') : __('common.devices');
                $deleteConfirm = $tab === 'users'
                    ? __('groups.delete_this_user_group_users')
                    : __('groups.delete_this_device_group_devices');
            @endphp

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div>
                    <h4 class="header-title">{{ $tab === 'users' ? __('groups.user_groups') : __('groups.device_groups') }}</h4>
                    <p class="rd-card-sub mb-0">
                        {{ $tab === 'users'
                            ? __('groups.groups_of_people_membership_drives')
                            : __('groups.folders_of_machines_device_belongs') }}
                    </p>
                </div>
                <div class="rd-toolbar-actions">
                    <button type="button" class="btn btn-primary" wire:click="create('{{ $tab }}')">
                        <i class="ri-add-line"></i>{{ __('groups.add_group') }}
                    </button>
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.name') }}</th>
                        <th>{{ __('common.note') }}</th>
                        <th>{{ $countLabel }}</th>
                        @if ($tab === 'users')
                            <th>{{ __('common.device_access') }}</th>
                        @endif
                        <th>{{ __('common.created') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($current as $group)
                        <tr wire:key="g-{{ $tab }}-{{ $group->id }}">
                            <td class="fw-semibold">{{ $group->name }}</td>
                            <td>{{ $group->note ?: '—' }}</td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $tab === 'users' ? $group->users_count : $group->devices_count }}
                                </span>
                            </td>
                            @if ($tab === 'users')
                                <td>
                                    @forelse ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </td>
                            @endif
                            <td>
                                <span title="{{ $group->created_at }}">{{ $group->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end rd-rowact">
                                <a href="javascript:void(0);" class="rd-act me-2"
                                   wire:click="edit('{{ $tab }}', {{ $group->id }})">{{ __('common.edit') }}</a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                   wire:confirm="{{ $deleteConfirm }}">{{ __('common.delete') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'users' ? 6 : 5 }}" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="{{ $tab === 'users' ? 'ri-group-line' : 'ri-folders-line' }}"></i></div>
                                    <p class="rd-empty-title">
                                        {{ $tab === 'users' ? __('groups.no_user_groups_yet_click') : __('groups.no_device_groups_yet_click') }}
                                    </p>
                                    <p class="rd-empty-text">
                                        {{ $tab === 'users'
                                            ? __('groups.user_groups_decide_who_can')
                                            : __('groups.device_groups_keep_machines_organised') }}
                                    </p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create('{{ $tab }}')">{{ __('groups.add_group') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($current as $group)
                    <div class="rd-mini" wire:key="mg-{{ $tab }}-{{ $group->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title">{{ $group->name }}</span>
                                    <span class="rd-mini-sub">{{ $group->note ?: __('groups.no_note') }}</span>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    {{ $tab === 'users' ? $group->users_count : $group->devices_count }}
                                    {{ strtolower($countLabel) }}
                                </span>
                            </div>
                            @if ($tab === 'users' && $group->deviceGroups->isNotEmpty())
                                <div class="mt-2">
                                    @foreach ($group->deviceGroups as $dg)
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ $dg->name }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub">{{ __('groups.created_date', ['date' => $group->created_at?->format('Y-m-d')]) }}</span>
                                <div class="rd-mini-acts">
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}"
                                       wire:click="edit('{{ $tab }}', {{ $group->id }})"><i class="ri-pencil-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                       wire:click="deleteGroup('{{ $tab }}', {{ $group->id }})"
                                       wire:confirm="{{ $deleteConfirm }}"><i class="ri-delete-bin-line"></i></a>
                                </div>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="{{ $tab === 'users' ? 'ri-group-line' : 'ri-folders-line' }}"></i></div>
                        <p class="rd-empty-title">
                            {{ $tab === 'users' ? __('groups.no_user_groups_yet_tap') : __('groups.no_device_groups_yet_tap') }}
                        </p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create('{{ $tab }}')">{{ __('groups.add_group') }}</button>
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create / Edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @if ($showModal)
        {{-- Scrollable: the two checkbox blocks below grow with the number of groups,
             and without a bounded body "Create Group" is pushed off a 390px screen. --}}
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                {{ $modalType === 'users' ? ($editing ? __('groups.edit_user_group') : __('groups.add_user_group')) : ($editing ? __('groups.edit_device_group') : __('groups.add_device_group')) }}
                            </h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="gl-name">{{ __('common.name') }} <span class="text-danger">*</span></label>
                                <input type="text" id="gl-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" autocomplete="off">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="{{ $modalType === 'users' ? 'mb-3' : 'mb-0' }}">
                                <label class="form-label" for="gl-note">{{ __('common.note') }}</label>
                                <textarea id="gl-note" rows="3" class="form-control @error('note') is-invalid @enderror"
                                          wire:model="note"></textarea>
                                @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Device-group access (user groups only) --}}
                            @if ($modalType === 'users')
                                <div class="mb-3">
                                    <label class="form-label">{{ __('groups.device_group_access') }}</label>
                                    <p class="text-muted fs-13 mb-2">{{ __('groups.every_member_of_this_user') }}</p>
                                    @forelse ($grantableDeviceGroups as $dg)
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="gl-dg-{{ $dg->id }}"
                                                   value="{{ $dg->id }}" wire:model="device_group_ids">
                                            <label class="form-check-label" for="gl-dg-{{ $dg->id }}">{{ $dg->name }}</label>
                                        </div>
                                    @empty
                                        <p class="text-muted fs-13">{{ auth()->user()?->is_admin ? __('groups.no_device_groups_exist_yet') : __('groups.no_device_groups_you_can') }}</p>
                                    @endforelse
                                </div>
                            @endif

                            {{-- Accessed from: which user groups may see this group (B4) --}}
                            <div class="mb-0">
                                <label class="form-label">{{ __('groups.accessed_from') }}</label>
                                <p class="text-muted fs-13 mb-2">
                                    @if ($modalType === 'users')
                                        {{ __('groups.members_of_user_groups_checked_2') }}
                                    @else
                                        {{ __('groups.members_of_user_groups_checked') }}
                                    @endif
                                </p>
                                @php $accessorOptions = $userGroups->where('id', '!=', $editing ?? 0); @endphp
                                @forelse ($accessorOptions as $ug)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="gl-af-{{ $ug->id }}"
                                               value="{{ $ug->id }}" wire:model="accessor_group_ids">
                                        <label class="form-check-label" for="gl-af-{{ $ug->id }}">{{ $ug->name }}</label>
                                    </div>
                                @empty
                                    <p class="text-muted fs-13 mb-0">{{ __('groups.no_other_user_groups_exist') }}</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ $editing ? __('account.save_changes') : __('groups.create_group') }}</span>
                                <span wire:loading wire:target="save">{{ __('common.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
