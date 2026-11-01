<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <h4 class="header-title">{{ __('common.roles') }}</h4>
                <p class="rd-card-sub mb-0">
                    {{ __('roles.role_opens_console_sections_for') }}
                </p>
            </div>
            <div class="rd-card-actions">
                <button type="button" class="btn btn-primary" wire:click="create">
                    <i class="ri-add-line"></i>{{ __('roles.new_role') }}
                </button>
            </div>
        </div>

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.role') }}</th>
                        <th>{{ __('common.permissions') }}</th>
                        <th>{{ __('common.2fa') }}</th>
                        <th>{{ __('common.users') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($roles as $role)
                        <tr wire:key="r{{ $role->id }}">
                            <td>
                                <div class="rd-cell rd-tone-teal">
                                    <span class="rd-avatar"><i class="ri-shield-user-line"></i></span>
                                    <div class="min-width-0">
                                        <span class="rd-cell-title">{{ $role->name }}</span>
                                        <span class="rd-cell-sub">{{ $role->description ?: '—' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @php $granted = collect($resources)->filter(fn ($r) => $role->levelFor($r) !== 'none'); @endphp
                                @forelse ($granted as $res)
                                    <span class="badge {{ $role->levelFor($res) === 'rw' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }}">
                                        {{ $resourceLabels[$res] ?? $res }}: {{ $levelLabels[$role->levelFor($res)] }}
                                    </span>
                                @empty
                                    <span class="text-muted">{{ __('roles.no_permissions') }}</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($role->require_two_factor)
                                    <span class="badge bg-info-subtle text-info"><i class="ri-shield-keyhole-line"></i> {{ __('roles.required') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $role->users_count }}</td>
                            <td class="text-end rd-rowact">
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $role->id }})">{{ __('common.edit') }}</a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="deleteRole({{ $role->id }})"
                                   wire:confirm="{{ trans_choice('roles.delete_this_role_count_user', $role->users_count) }}">{{ __('common.delete') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-shield-user-line"></i></div>
                                    <p class="rd-empty-title">
                                        {{ __('roles.no_roles_yet_every_non') }}
                                    </p>
                                    <p class="rd-empty-text">{{ __('roles.create_one_to_let_colleague') }}</p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('roles.new_role') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($roles as $role)
                    <div class="rd-mini" wire:key="mr{{ $role->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title text-truncate">{{ $role->name }}</span>
                                    <span class="rd-mini-sub text-truncate">{{ $role->description ?: '—' }}</span>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary flex-shrink-0">
                                    {{ trans_choice('common.count_users', $role->users_count) }}
                                </span>
                            </div>
                            <div class="mt-2">
                                @php $granted = collect($resources)->filter(fn ($r) => $role->levelFor($r) !== 'none'); @endphp
                                @forelse ($granted as $res)
                                    <span class="badge {{ $role->levelFor($res) === 'rw' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' }}">
                                        {{ $resourceLabels[$res] ?? $res }}: {{ $levelLabels[$role->levelFor($res)] }}
                                    </span>
                                @empty
                                    <small class="text-muted">{{ __('roles.no_permissions') }}</small>
                                @endforelse
                                @if ($role->require_two_factor)
                                    <span class="badge bg-info-subtle text-info"><i class="ri-shield-keyhole-line"></i> {{ __('common.2fa') }}</span>
                                @endif
                            </div>
                            <div class="rd-mini-acts justify-content-end mt-2">
                                <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}" wire:click="edit({{ $role->id }})"><i class="ri-pencil-line"></i></a>
                                <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                   wire:click="deleteRole({{ $role->id }})"
                                   wire:confirm="{{ trans_choice('roles.delete_this_role_count_user', $role->users_count) }}"><i class="ri-delete-bin-line"></i></a>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-shield-user-line"></i></div>
                        <p class="rd-empty-title">
                            {{ __('roles.no_roles_yet_every_non') }}
                        </p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('roles.new_role') }}</button>
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create / Edit modal --}}
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editing ? __('roles.edit_role') : __('roles.new_role') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="rl-name">{{ __('common.name') }} <span class="text-danger">*</span></label>
                                <input type="text" id="rl-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" autocomplete="off" placeholder="{{ __('roles.helpdesk') }}">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="rl-description">{{ __('roles.description') }}</label>
                                <input type="text" id="rl-description" class="form-control @error('description') is-invalid @enderror"
                                       wire:model="description" autocomplete="off" placeholder="{{ __('common.optional') }}">
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <label class="form-label">{{ __('common.permissions') }}</label>
                            @error('permissions.*') <div class="text-danger fs-13 mb-1">{{ $message }}</div> @enderror

                            {{-- Desktop: radio grid --}}
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-sm table-centered mb-0">
                                    <thead>
                                    <tr>
                                        <th>{{ __('roles.area') }}</th>
                                        @foreach ($levels as $lvl)
                                            <th class="text-center">{{ $levelLabels[$lvl] }}</th>
                                        @endforeach
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($resources as $res)
                                        <tr wire:key="perm-{{ $res }}">
                                            <td>
                                                {{ $resourceLabels[$res] ?? $res }}
                                                <small class="text-muted d-block">{{ $resourceHints[$res] ?? '' }}</small>
                                            </td>
                                            @foreach ($levels as $lvl)
                                                <td class="text-center">
                                                    <input class="form-check-input" type="radio"
                                                           aria-label="{{ ($resourceLabels[$res] ?? $res).' '.$levelLabels[$lvl] }}"
                                                           wire:model="permissions.{{ $res }}" value="{{ $lvl }}">
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Mobile: one select per area (a 3-column radio grid does not fit 390px) --}}
                            <div class="d-md-none">
                                @foreach ($resources as $res)
                                    <div class="mb-2" wire:key="mperm-{{ $res }}">
                                        <label class="form-label mb-1" for="rl-perm-{{ $res }}">{{ $resourceLabels[$res] ?? $res }}</label>
                                        <select id="rl-perm-{{ $res }}" class="form-select" wire:model="permissions.{{ $res }}">
                                            @foreach ($levels as $lvl)
                                                <option value="{{ $lvl }}">{{ $levelLabels[$lvl] }}</option>
                                            @endforeach
                                        </select>
                                        <small class="text-muted d-block">{{ $resourceHints[$res] ?? '' }}</small>
                                    </div>
                                @endforeach
                            </div>

                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="rl-2fa"
                                       wire:model="require_two_factor">
                                <label class="form-check-label" for="rl-2fa">{{ __('roles.require_two_factor_authentication') }}</label>
                                <small class="text-muted d-block">
                                    {{ __('roles.holders_of_this_role_must') }}
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ $editing ? __('roles.save_role') : __('roles.create_role') }}</span>
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
