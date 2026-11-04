<div>
    @php
        // Roles gate the screen; is_admin additionally gates the two controls
        // that could hand out MORE authority than the actor holds (D4).
        $canManageUsers = auth()->user()?->consoleAllows('user', 'rw');
        $isSuperAdmin = auth()->user()?->is_admin;
    @endphp

    <div class="card">

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div class="rd-toolbar-search">
                    <div class="input-group">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control" placeholder="{{ \App\Support\SearchHint::placeholder(__('users.search_username_name_email')) }}"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <select class="form-select rd-toolbar-filter" wire:model.live="role" aria-label="{{ __('common.role') }}">
                    <option value="all">{{ __('users.all_roles') }}</option>
                    <option value="admin">{{ __('users.administrators') }}</option>
                    <option value="user">{{ __('common.users') }}</option>
                </select>
                <select class="form-select rd-toolbar-filter" wire:model.live="status" aria-label="{{ __('common.status') }}">
                    <option value="all">{{ __('common.all_statuses') }}</option>
                    <option value="active">{{ __('common.active') }}</option>
                    <option value="pending">{{ __('users.awaiting_approval') }}</option>
                    <option value="disabled">{{ __('common.disabled') }}</option>
                </select>
                <div class="rd-toolbar-actions">
                    <button type="button" class="btn btn-outline-light" wire:click="resetFilters">{{ __('common.reset') }}</button>
                    @if ($canManageUsers)
                        <button type="button" class="btn btn-primary" wire:click="create">
                            <i class="ri-add-line"></i>{{ __('users.add_user') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.user') }}</th>
                        <th>{{ __('common.email') }}</th>
                        <th>{{ __('common.group') }}</th>
                        <th>{{ __('common.role') }}</th>
                        <th>{{ __('common.status') }}</th>
                        <th>{{ __('common.devices') }}</th>
                        <th>{{ __('common.created') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $user)
                        @php
                            $canTouch = $canManageUsers && ($isSuperAdmin
                                || (! $user->is_admin && $user->role_id === null && $user->id !== auth()->id()));
                        @endphp
                        <tr wire:key="u{{ $user->id }}">
                            <td>
                                <div class="rd-cell {{ $user->is_admin ? 'rd-tone-accent' : 'rd-tone-purple' }}">
                                    <span class="rd-avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                                    <div class="min-width-0">
                                        <a href="{{ route('users.show', $user->id) }}" class="rd-cell-title rd-cell-link">{{ $user->username }}</a>
                                        <span class="rd-cell-sub">{{ $user->name ?: '—' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $user->email ?: '—' }}</td>
                            <td>
                                @forelse ($user->groups as $g)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $g->name }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>
                                @if ($user->is_admin)
                                    <span class="badge bg-danger-subtle text-danger">{{ __('common.administrator') }}</span>
                                @elseif ($user->role)
                                    <span class="badge bg-info-subtle text-info">{{ $user->role->name }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('common.user') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($user->isSsoPending())
                                    <span class="badge bg-warning-subtle text-warning" title="{{ __('users.awaiting_approval_title') }}">{{ __('users.awaiting_approval') }}</span>
                                @elseif ($user->is_active)
                                    <span class="badge bg-success-subtle text-success">{{ __('common.active') }}</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning">{{ __('common.disabled') }}</span>
                                @endif
                                @if ($user->totp_enabled)
                                    <span class="badge bg-info-subtle text-info" title="{{ __('users.two_factor_authentication_enabled') }}"><i class="ri-shield-keyhole-line"></i> {{ __('common.2fa') }}</span>
                                @endif
                            </td>
                            <td>{{ $user->devices_count }}</td>
                            <td>
                                <span class="text-nowrap" title="{{ $user->created_at }}">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end rd-rowact">
                                <a href="{{ route('users.show', $user->id) }}" class="rd-act me-2" title="{{ __('common.view_details') }}"><i class="ri-eye-line"></i></a>
                                @if ($canTouch)
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $user->id }})">{{ __('common.edit') }}</a>
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="openAssign({{ $user->id }})">{{ __('common.devices') }}</a>
                                @if ($user->id === auth()->id())
                                    <span class="text-muted me-2" title="{{ __('users.you_cannot_disable_your_own') }}" style="cursor:not-allowed;">
                                        {{ $user->is_active ? __('users.disable') : __('users.enable') }}
                                    </span>
                                    <span class="text-muted" title="{{ __('users.you_cannot_delete_your_own') }}" style="cursor:not-allowed;">{{ __('common.delete') }}</span>
                                @else
                                    @if ($user->isSsoPending())
                                        <a href="javascript:void(0);" class="rd-act me-2 text-success"
                                           wire:click="approveSso({{ $user->id }})"
                                           wire:confirm="{{ __('users.approve_confirm', ['user' => $user->username]) }}">{{ __('users.approve') }}</a>
                                    @endif
                                    <a href="javascript:void(0);" class="rd-act me-2" wire:click="toggleActive({{ $user->id }})">
                                        {{ $user->is_active ? __('users.disable') : __('users.enable') }}
                                    </a>
                                    <a href="javascript:void(0);" class="rd-act me-2"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="{{ __('users.force_user_to_log_out', ['user' => $user->username]) }}">{{ __('users.log_out') }}</a>
                                    @if ($user->totp_enabled)
                                        <a href="javascript:void(0);" class="text-warning me-2"
                                           wire:click="resetTwoFactor({{ $user->id }})"
                                           wire:confirm="{{ __('users.reset_2fa_for_user_their', ['user' => $user->username]) }}">{{ __('users.reset_2fa') }}</a>
                                    @endif
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="{{ __('users.delete_user_user_their_devices', ['user' => $user->username]) }}">{{ __('common.delete') }}</a>
                                @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-user-line"></i></div>
                                    <p class="rd-empty-title">{{ __('users.no_users_match_your_filters') }}</p>
                                    <p class="rd-empty-text">{{ __('users.console_accounts_sign_in_with') }}</p>
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
                @forelse ($users as $user)
                    @php
                        $canTouch = $canManageUsers && ($isSuperAdmin
                            || (! $user->is_admin && $user->role_id === null && $user->id !== auth()->id()));
                    @endphp
                    <div class="rd-mini" wire:key="mu{{ $user->id }}">
                            <div class="rd-mini-head">
                                <div class="rd-cell {{ $user->is_admin ? 'rd-tone-accent' : 'rd-tone-purple' }}">
                                    <span class="rd-avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                                    <div class="min-width-0">
                                        <a href="{{ route('users.show', $user->id) }}" class="rd-mini-title text-truncate">{{ $user->username }}</a>
                                        <span class="rd-mini-sub text-truncate">{{ $user->name ?: ($user->email ?: '—') }}</span>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    @if ($user->is_admin)
                                        <span class="badge bg-danger-subtle text-danger">{{ __('users.admin') }}</span>
                                    @elseif ($user->role)
                                        <span class="badge bg-info-subtle text-info">{{ $user->role->name }}</span>
                                    @endif
                                    @if ($user->isSsoPending())
                                        <span class="badge bg-warning-subtle text-warning">{{ __('users.awaiting_approval') }}</span>
                                    @elseif ($user->is_active)
                                        <span class="badge bg-success-subtle text-success">{{ __('common.active') }}</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">{{ __('common.disabled') }}</span>
                                    @endif
                                    @if ($user->totp_enabled)
                                        <span class="badge bg-info-subtle text-info" title="{{ __('users.2fa_enabled') }}"><i class="ri-shield-keyhole-line"></i></span>
                                    @endif
                                </div>
                            </div>
                            {{-- Meta on its own full-width line; actions in one non-wrapping row below --}}
                            <span class="rd-mini-sub mt-2">
                                {{ $user->groups->isNotEmpty() ? $user->groups->pluck('name')->join(', ') : __('devices.no_group') }} ·
                                {{ trans_choice('common.count_devices', $user->devices_count) }} ·
                                <span class="text-nowrap">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </span>
                            <div class="rd-mini-acts justify-content-end mt-2">
                                <a href="{{ route('users.show', $user->id) }}" class="rd-iconbtn" title="{{ __('common.view_details') }}"><i class="ri-eye-line"></i></a>
                                @if ($canTouch)
                                <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}" wire:click="edit({{ $user->id }})"><i class="ri-pencil-line"></i></a>
                                <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('users.assign_devices_2') }}" wire:click="openAssign({{ $user->id }})"><i class="ri-computer-line"></i></a>
                                @unless ($user->id === auth()->id())
                                    @if ($user->isSsoPending())
                                        <a href="javascript:void(0);" class="rd-iconbtn text-success" title="{{ __('users.approve') }}"
                                           wire:click="approveSso({{ $user->id }})"
                                           wire:confirm="{{ __('users.approve_confirm', ['user' => $user->username]) }}">
                                            <i class="ri-user-follow-line"></i>
                                        </a>
                                    @endif
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ $user->is_active ? __('users.disable') : __('users.enable') }}"
                                       wire:click="toggleActive({{ $user->id }})">
                                        <i class="{{ $user->is_active ? 'ri-user-unfollow-line' : 'ri-user-follow-line' }}"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('users.force_logout_everywhere') }}"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="{{ __('users.force_user_to_log_out', ['user' => $user->username]) }}">
                                        <i class="ri-logout-box-r-line"></i>
                                    </a>
                                    @if ($user->totp_enabled)
                                        <a href="javascript:void(0);" class="rd-iconbtn text-warning" title="{{ __('users.reset_2fa') }}"
                                           wire:click="resetTwoFactor({{ $user->id }})"
                                           wire:confirm="{{ __('users.reset_2fa_for_user_their', ['user' => $user->username]) }}">
                                            <i class="ri-shield-keyhole-line"></i>
                                        </a>
                                    @endif
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="{{ __('users.delete_user_user_their_devices', ['user' => $user->username]) }}"><i class="ri-delete-bin-line"></i></a>
                                @endunless
                                @endif
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-user-line"></i></div>
                        <p class="rd-empty-title">{{ __('users.no_users_match_your_filters') }}</p>
                        <p class="rd-empty-text">{{ __('users.console_accounts_sign_in_with') }}</p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">{{ __('common.clear_filters') }}</button>
                    </div>
                @endforelse
            </div>

            <div class="rd-tablefoot">
                <span>{{ __('common.showing_from_to_of_total', ['from' => $users->firstItem() ?? 0, 'to' => $users->lastItem() ?? 0, 'total' => $users->total()]) }}</span>
                {{ $users->links() }}
            </div>
    </div>

    {{-- Create / Edit modal (plain Bootstrap markup, toggled by Livewire) --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editing ? __('users.edit_user') : __('users.add_user') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-username">{{ __('common.username') }} <span class="text-danger">*</span></label>
                                        <input type="text" id="ul-username" class="form-control @error('username') is-invalid @enderror"
                                               wire:model="username" autocomplete="off">
                                        @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-name">{{ __('users.display_name') }}</label>
                                        <input type="text" id="ul-name" class="form-control @error('name') is-invalid @enderror"
                                               wire:model="name" autocomplete="off">
                                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-email">{{ __('common.email') }}</label>
                                        <input type="email" id="ul-email" class="form-control @error('email') is-invalid @enderror"
                                               wire:model="email" autocomplete="off">
                                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-password">
                                            {{ __('common.password') }}
                                            @if ($editing)
                                                <small class="text-muted fw-normal">{{ __('users.leave_blank_to_keep_current') }}</small>
                                            @else
                                                <span class="text-danger">*</span>
                                            @endif
                                        </label>
                                        <input type="password" id="ul-password" class="form-control @error('password') is-invalid @enderror"
                                               wire:model="password" autocomplete="new-password">
                                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    @if ($isSuperAdmin)
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" role="switch" id="ul-admin" wire:model.live="is_admin">
                                            <label class="form-check-label" for="ul-admin">{{ __('common.administrator') }}</label>
                                            <small class="text-muted d-block">{{ __('users.admins_see_and_manage_everything') }}</small>
                                        </div>

                                        {{-- Delegated role (PLAN D4). Only a full administrator may
                                             grant one; save() strips it from anyone else's payload. --}}
                                        @unless ($is_admin)
                                            <div class="mb-3">
                                                <label class="form-label" for="ul-role">{{ __('common.role') }}</label>
                                                <select id="ul-role" class="form-select @error('role_id') is-invalid @enderror" wire:model="role_id">
                                                    <option value="">{{ __('users.standard_user') }}</option>
                                                    @foreach ($roles as $r)
                                                        <option value="{{ $r->id }}">{{ $r->name }}</option>
                                                    @endforeach
                                                </select>
                                                @error('role_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                <small class="text-muted d-block">
                                                    {{ __('users.role_opens_extra_console_sections') }}
                                                    <a href="{{ route('roles') }}">{{ __('users.manage_roles') }}</a>
                                                </small>
                                            </div>
                                        @endunless
                                    @endif
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ul-active" wire:model="is_active"
                                               @if ($editing === auth()->id()) disabled @endif>
                                        <label class="form-check-label" for="ul-active">{{ __('common.active') }}</label>
                                        @if ($editing === auth()->id())
                                            <small class="text-muted d-block">{{ __('users.you_cannot_disable_your_own_2') }}</small>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">{{ __('common.groups') }}</label>
                                        <p class="text-muted fs-13 mb-2">{{ __('users.user_can_belong_to_several') }}</p>
                                        <div class="rd-scrollbox p-2" style="max-height: 210px; overflow-y: auto;">
                                            @forelse ($userGroups as $g)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ul-ug-{{ $g->id }}"
                                                           value="{{ $g->id }}" wire:model="user_group_ids">
                                                    <label class="form-check-label" for="ul-ug-{{ $g->id }}">{{ $g->name }}</label>
                                                </div>
                                            @empty
                                                <p class="text-muted fs-13 mb-0">{{ auth()->user()?->is_admin ? __('users.no_user_groups_exist_yet') : __('users.no_user_groups_you_can') }}</p>
                                            @endforelse
                                        </div>
                                        @error('user_group_ids') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                        @error('user_group_ids.*') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                    </div>

                                    {{-- Device-group access (non-admins only) --}}
                                    @unless ($is_admin)
                                        <div class="mb-1">
                                            <label class="form-label">{{ __('common.device_access') }}</label>
                                            <p class="text-muted fs-13 mb-2">{{ __('users.this_user_sees_only_devices') }}</p>
                                            <div class="rd-scrollbox p-2" style="max-height: 210px; overflow-y: auto;">
                                                @forelse ($deviceGroups as $dg)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="ul-dg-{{ $dg->id }}"
                                                               value="{{ $dg->id }}" wire:model="device_group_ids">
                                                        <label class="form-check-label" for="ul-dg-{{ $dg->id }}">{{ $dg->name }}</label>
                                                    </div>
                                                @empty
                                                    <p class="text-muted fs-13 mb-0">{{ auth()->user()?->is_admin ? __('groups.no_device_groups_exist_yet') : __('groups.no_device_groups_you_can') }}</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endunless
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ $editing ? __('account.save_changes') : __('users.create_user') }}</span>
                                <span wire:loading wire:target="save">{{ __('common.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Assign devices modal: bulk-set which devices this user owns --}}
    @if ($showAssignModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" wire:key="assign-modal">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('users.assign_devices') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="{{ __('common.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            {!! __('users.select_devices_owned_by_user', ['user' => '<strong>'.e(optional(\App\Models\User::find($assignUserId))->username).'</strong>']) !!}
                        </p>
                        <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control mb-2" placeholder="{{ \App\Support\SearchHint::placeholder(__('common.search_id_alias_hostname')) }}"
                               wire:model.live.debounce.300ms="assignSearch">
                        <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    @forelse ($assignDevices as $d)
                                        <tr wire:key="ad{{ $d->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="ul-ad-{{ $d->id }}"
                                                       value="{{ $d->id }}" wire:model="assignDeviceIds"
                                                       aria-label="{{ __('users.assign_device_id', ['id' => $d->rustdesk_id]) }}">
                                            </td>
                                            <td>
                                                {{-- The label is the tap target: a 14px checkbox is not one, and
                                                     `for` makes the whole cell toggle it without a second binding. --}}
                                                <label class="rd-picklabel" for="ul-ad-{{ $d->id }}">
                                                    <span class="fw-semibold">{{ $d->rustdesk_id }}</span>
                                                    @if ($d->alias || $d->hostname)
                                                        <small class="text-muted d-block">{{ $d->alias ?: $d->hostname }}</small>
                                                    @endif
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if ($d->user_id && $d->user_id !== $assignUserId)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('users.owned_by_user', ['user' => optional($d->user)->username ?? '—']) }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">{{ __('common.no_devices_match') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">{{ __('common.count_selected', ['count' => count($assignDeviceIds)]) }} @if($assignDevices->count() >= 200) · {{ __('common.showing_first_200_refine_with') }} @endif</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeAssign">{{ __('common.cancel') }}</button>
                        <button type="button" class="btn btn-primary" wire:click="saveAssign">
                            <span wire:loading.remove wire:target="saveAssign">{{ __('common.save_assignment') }}</span>
                            <span wire:loading wire:target="saveAssign">{{ __('common.saving') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
