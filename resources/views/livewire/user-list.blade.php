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
                        <input type="search" class="form-control" placeholder="Search username, name, email…"
                               wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <select class="form-select rd-toolbar-filter" wire:model.live="role" aria-label="Role">
                    <option value="all">All roles</option>
                    <option value="admin">Administrators</option>
                    <option value="user">Users</option>
                </select>
                <select class="form-select rd-toolbar-filter" wire:model.live="status" aria-label="Status">
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
                <div class="rd-toolbar-actions">
                    <button type="button" class="btn btn-outline-light" wire:click="resetFilters">Reset</button>
                    @if ($canManageUsers)
                        <button type="button" class="btn btn-primary" wire:click="create">
                            <i class="ri-add-line"></i>Add User
                        </button>
                    @endif
                </div>
            </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Group</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Devices</th>
                        <th>Created</th>
                        <th class="text-end">Action</th>
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
                                    <span class="badge bg-danger-subtle text-danger">Administrator</span>
                                @elseif ($user->role)
                                    <span class="badge bg-info-subtle text-info">{{ $user->role->name }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">User</span>
                                @endif
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning">Disabled</span>
                                @endif
                                @if ($user->totp_enabled)
                                    <span class="badge bg-info-subtle text-info" title="Two-factor authentication enabled"><i class="ri-shield-keyhole-line"></i> 2FA</span>
                                @endif
                            </td>
                            <td>{{ $user->devices_count }}</td>
                            <td>
                                <span class="text-nowrap" title="{{ $user->created_at }}">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </td>
                            <td class="text-end rd-rowact">
                                <a href="{{ route('users.show', $user->id) }}" class="rd-act me-2" title="View details"><i class="ri-eye-line"></i></a>
                                @if ($canTouch)
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $user->id }})">Edit</a>
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="openAssign({{ $user->id }})">Devices</a>
                                @if ($user->id === auth()->id())
                                    <span class="text-muted me-2" title="You cannot disable your own account" style="cursor:not-allowed;">
                                        {{ $user->is_active ? 'Disable' : 'Enable' }}
                                    </span>
                                    <span class="text-muted" title="You cannot delete your own account" style="cursor:not-allowed;">Delete</span>
                                @else
                                    <a href="javascript:void(0);" class="rd-act me-2" wire:click="toggleActive({{ $user->id }})">
                                        {{ $user->is_active ? 'Disable' : 'Enable' }}
                                    </a>
                                    <a href="javascript:void(0);" class="rd-act me-2"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="Force {{ $user->username }} to log out everywhere? This signs out their RustDesk clients and console sessions.">Log out</a>
                                    @if ($user->totp_enabled)
                                        <a href="javascript:void(0);" class="text-warning me-2"
                                           wire:click="resetTwoFactor({{ $user->id }})"
                                           wire:confirm="Reset 2FA for {{ $user->username }}? Their authenticator and recovery codes are removed and they must re-enroll.">Reset 2FA</a>
                                    @endif
                                    <a href="javascript:void(0);" class="text-danger"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="Delete user {{ $user->username }}? Their devices will be kept but no longer assigned to any user.">Delete</a>
                                @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-user-line"></i></div>
                                    <p class="rd-empty-title">No users match your filters.</p>
                                    <p class="rd-empty-text">Console accounts sign in with a username, not an email address.</p>
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
                                        <span class="badge bg-danger-subtle text-danger">Admin</span>
                                    @elseif ($user->role)
                                        <span class="badge bg-info-subtle text-info">{{ $user->role->name }}</span>
                                    @endif
                                    @if ($user->is_active)
                                        <span class="badge bg-success-subtle text-success">Active</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Disabled</span>
                                    @endif
                                    @if ($user->totp_enabled)
                                        <span class="badge bg-info-subtle text-info" title="2FA enabled"><i class="ri-shield-keyhole-line"></i></span>
                                    @endif
                                </div>
                            </div>
                            {{-- Meta on its own full-width line; actions in one non-wrapping row below --}}
                            <span class="rd-mini-sub mt-2">
                                {{ $user->groups->isNotEmpty() ? $user->groups->pluck('name')->join(', ') : 'No group' }} ·
                                {{ $user->devices_count }} {{ Str::plural('device', $user->devices_count) }} ·
                                <span class="text-nowrap">{{ $user->created_at?->format('Y-m-d') }}</span>
                            </span>
                            <div class="rd-mini-acts justify-content-end mt-2">
                                <a href="{{ route('users.show', $user->id) }}" class="rd-iconbtn" title="View details"><i class="ri-eye-line"></i></a>
                                @if ($canTouch)
                                <a href="javascript:void(0);" class="rd-iconbtn" title="Edit" wire:click="edit({{ $user->id }})"><i class="ri-pencil-line"></i></a>
                                <a href="javascript:void(0);" class="rd-iconbtn" title="Assign devices" wire:click="openAssign({{ $user->id }})"><i class="ri-computer-line"></i></a>
                                @unless ($user->id === auth()->id())
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ $user->is_active ? 'Disable' : 'Enable' }}"
                                       wire:click="toggleActive({{ $user->id }})">
                                        <i class="{{ $user->is_active ? 'ri-user-unfollow-line' : 'ri-user-follow-line' }}"></i>
                                    </a>
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="Force logout everywhere"
                                       wire:click="forceLogout({{ $user->id }})"
                                       wire:confirm="Force {{ $user->username }} to log out everywhere? This signs out their RustDesk clients and console sessions.">
                                        <i class="ri-logout-box-r-line"></i>
                                    </a>
                                    @if ($user->totp_enabled)
                                        <a href="javascript:void(0);" class="rd-iconbtn text-warning" title="Reset 2FA"
                                           wire:click="resetTwoFactor({{ $user->id }})"
                                           wire:confirm="Reset 2FA for {{ $user->username }}? Their authenticator and recovery codes are removed and they must re-enroll.">
                                            <i class="ri-shield-keyhole-line"></i>
                                        </a>
                                    @endif
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="Delete"
                                       wire:click="deleteUser({{ $user->id }})"
                                       wire:confirm="Delete user {{ $user->username }}? Their devices will be kept but no longer assigned to any user."><i class="ri-delete-bin-line"></i></a>
                                @endunless
                                @endif
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-user-line"></i></div>
                        <p class="rd-empty-title">No users match your filters.</p>
                        <p class="rd-empty-text">Console accounts sign in with a username, not an email address.</p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="resetFilters">Clear filters</button>
                    </div>
                @endforelse
            </div>

            <div class="rd-tablefoot">
                <span>Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ $users->total() }}</span>
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
                            <h5 class="modal-title">{{ $editing ? 'Edit User' : 'Add User' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-username">Username <span class="text-danger">*</span></label>
                                        <input type="text" id="ul-username" class="form-control @error('username') is-invalid @enderror"
                                               wire:model="username" autocomplete="off">
                                        @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-name">Display Name</label>
                                        <input type="text" id="ul-name" class="form-control @error('name') is-invalid @enderror"
                                               wire:model="name" autocomplete="off">
                                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-email">Email</label>
                                        <input type="email" id="ul-email" class="form-control @error('email') is-invalid @enderror"
                                               wire:model="email" autocomplete="off">
                                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="ul-password">
                                            Password
                                            @if ($editing)
                                                <small class="text-muted fw-normal">(leave blank to keep current)</small>
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
                                            <label class="form-check-label" for="ul-admin">Administrator</label>
                                            <small class="text-muted d-block">Admins see and manage everything.</small>
                                        </div>

                                        {{-- Delegated role (PLAN D4). Only a full administrator may
                                             grant one; save() strips it from anyone else's payload. --}}
                                        @unless ($is_admin)
                                            <div class="mb-3">
                                                <label class="form-label" for="ul-role">Role</label>
                                                <select id="ul-role" class="form-select @error('role_id') is-invalid @enderror" wire:model="role_id">
                                                    <option value="">Standard user</option>
                                                    @foreach ($roles as $r)
                                                        <option value="{{ $r->id }}">{{ $r->name }}</option>
                                                    @endforeach
                                                </select>
                                                @error('role_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                <small class="text-muted d-block">
                                                    A role opens extra console sections. It never widens which devices
                                                    or address books this user can see — that stays with the grants below.
                                                    <a href="{{ route('roles') }}">Manage roles</a>
                                                </small>
                                            </div>
                                        @endunless
                                    @endif
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ul-active" wire:model="is_active"
                                               @if ($editing === auth()->id()) disabled @endif>
                                        <label class="form-check-label" for="ul-active">Active</label>
                                        @if ($editing === auth()->id())
                                            <small class="text-muted d-block">You cannot disable your own account.</small>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Groups</label>
                                        <p class="text-muted fs-13 mb-2">A user can belong to several groups. Groups control address-book sharing and device-group access.</p>
                                        <div class="rd-scrollbox p-2" style="max-height: 210px; overflow-y: auto;">
                                            @forelse ($userGroups as $g)
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ul-ug-{{ $g->id }}"
                                                           value="{{ $g->id }}" wire:model="user_group_ids">
                                                    <label class="form-check-label" for="ul-ug-{{ $g->id }}">{{ $g->name }}</label>
                                                </div>
                                            @empty
                                                <p class="text-muted fs-13 mb-0">{{ auth()->user()?->is_admin ? 'No user groups exist yet.' : 'No user groups you can grant.' }}</p>
                                            @endforelse
                                        </div>
                                        @error('user_group_ids') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                        @error('user_group_ids.*') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                    </div>

                                    {{-- Device-group access (non-admins only) --}}
                                    @unless ($is_admin)
                                        <div class="mb-1">
                                            <label class="form-label">Device access</label>
                                            <p class="text-muted fs-13 mb-2">This user sees only devices they own plus devices in the groups checked below.</p>
                                            <div class="rd-scrollbox p-2" style="max-height: 210px; overflow-y: auto;">
                                                @forelse ($deviceGroups as $dg)
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="ul-dg-{{ $dg->id }}"
                                                               value="{{ $dg->id }}" wire:model="device_group_ids">
                                                        <label class="form-check-label" for="ul-dg-{{ $dg->id }}">{{ $dg->name }}</label>
                                                    </div>
                                                @empty
                                                    <p class="text-muted fs-13 mb-0">{{ auth()->user()?->is_admin ? 'No device groups exist yet.' : 'No device groups you can grant.' }}</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endunless
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ $editing ? 'Save Changes' : 'Create User' }}</span>
                                <span wire:loading wire:target="save">Saving…</span>
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
                        <h5 class="modal-title">Assign Devices</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            Select the devices owned by <strong>{{ optional(\App\Models\User::find($assignUserId))->username }}</strong>.
                            Checked devices become theirs; unchecking a device they currently own releases it (no owner).
                        </p>
                        <input type="search" class="form-control mb-2" placeholder="Search ID, alias, hostname…"
                               wire:model.live.debounce.300ms="assignSearch">
                        <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    @forelse ($assignDevices as $d)
                                        <tr wire:key="ad{{ $d->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="ul-ad-{{ $d->id }}"
                                                       value="{{ $d->id }}" wire:model="assignDeviceIds"
                                                       aria-label="Assign device {{ $d->rustdesk_id }}">
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
                                                    <span class="badge bg-secondary-subtle text-secondary">owned by {{ optional($d->user)->username ?? '—' }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">No devices match.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">{{ count($assignDeviceIds) }} selected @if($assignDevices->count() >= 200) · showing first 200, refine with search @endif</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeAssign">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="saveAssign">
                            <span wire:loading.remove wire:target="saveAssign">Save Assignment</span>
                            <span wire:loading wire:target="saveAssign">Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
