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
    @include('livewire.partials.group-edit-modal')
</div>
