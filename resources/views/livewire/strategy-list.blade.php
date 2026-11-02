<div>
    <div class="card">

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div>
                    <h4 class="header-title">{{ __('common.strategies') }}</h4>
                    <p class="rd-card-sub mb-0">{{ __('strategies.client_settings_pushed_to_devices') }}</p>
                </div>
                <div class="rd-toolbar-actions">
                    <button type="button" class="btn btn-primary" wire:click="create">
                        <i class="ri-add-line"></i>{{ __('strategies.add_strategy') }}
                    </button>
                </div>
            </div>

            @if ($strategies->isNotEmpty() && $strategies->firstWhere('is_default', true) === null)
                <div class="rd-toolbar">
                    <div class="alert alert-secondary py-2 mb-0 w-100">
                        <i class="ri-information-line me-1"></i>{{ __('strategies.no_default_strategy_devices_with') }}
                    </div>
                </div>
            @endif

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('common.name') }}</th>
                        <th>{{ __('strategies.options') }}</th>
                        <th>{{ __('strategies.assigned_to') }}</th>
                        <th>{{ __('strategies.in_force_on') }}</th>
                        <th>{{ __('common.enabled') }}</th>
                        <th class="text-end">{{ __('common.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($strategies as $strategy)
                        <tr wire:key="s{{ $strategy->id }}">
                            <td>
                                <span class="rd-cell-title d-inline">{{ $strategy->name }}</span>
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary ms-1">{{ __('strategies.default') }}</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning ms-1" title="{{ __('strategies.re_pushed_on_every_heartbeat') }}">{{ __('strategies.enforced') }}</span>
                                @endif
                                @if ($strategy->note)
                                    <small class="text-muted d-block">{{ $strategy->note }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">{{ count($strategy->optionMap()) }}</span>
                            </td>
                            <td>
                                <span class="text-nowrap" title="{{ __('common.devices') }}">
                                    <i class="ri-computer-line me-1 text-muted"></i>{{ $strategy->devices_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="{{ __('common.users') }}">
                                    <i class="ri-user-line me-1 text-muted"></i>{{ $strategy->users_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="{{ __('strategies.device_groups') }}">
                                    <i class="ri-folder-line me-1 text-muted"></i>{{ $strategy->device_groups_count }}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info">{{ trans_choice('strategies.count_device_s', $strategy->resolved_devices_count) }}</span>
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="strategy-enabled-{{ $strategy->id }}"
                                           @checked($strategy->enabled)
                                           wire:click="toggleEnabled({{ $strategy->id }})">
                                    <label class="form-check-label visually-hidden" for="strategy-enabled-{{ $strategy->id }}">{{ __('common.enabled') }}</label>
                                </div>
                            </td>
                            <td class="text-end rd-rowact">
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="showHistory({{ $strategy->id }})">{{ __('strategies.history') }}</a>
                                @if ($isAdmin)
                                    <a href="javascript:void(0);" class="rd-act me-2" wire:click="showCompliance({{ $strategy->id }})">{{ __('strategies.compliance') }}</a>
                                @endif
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="openAssign({{ $strategy->id }})">{{ __('strategies.assign') }}</a>
                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="edit({{ $strategy->id }})">{{ __('common.edit') }}</a>
                                <a href="javascript:void(0);" class="text-danger"
                                   wire:click="deleteStrategy({{ $strategy->id }})">{{ __('common.delete') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                                    <p class="rd-empty-title">{{ __('strategies.no_strategies_yet_click_add') }}</p>
                                    <p class="rd-empty-text">{{ __('strategies.strategy_is_set_of_client') }}</p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('strategies.add_strategy') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($strategies as $strategy)
                    <div class="rd-mini" wire:key="ms{{ $strategy->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title text-truncate">{{ $strategy->name }}</span>
                                    <span class="rd-mini-sub text-truncate">{{ $strategy->note ?: count($strategy->optionMap()).' option(s)' }}</span>
                                </div>
                                <div class="form-check form-switch mb-0 flex-shrink-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="m-strategy-enabled-{{ $strategy->id }}"
                                           @checked($strategy->enabled)
                                           wire:click="toggleEnabled({{ $strategy->id }})">
                                    <label class="form-check-label visually-hidden" for="m-strategy-enabled-{{ $strategy->id }}">{{ __('common.enabled') }}</label>
                                </div>
                            </div>
                            <div class="mt-2">
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary">{{ __('strategies.default') }}</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning">{{ __('strategies.enforced') }}</span>
                                @endif
                                <span class="badge bg-info-subtle text-info">{{ __('strategies.count_in_force', ['count' => $strategy->resolved_devices_count]) }}</span>
                            </div>
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub text-nowrap">
                                    <i class="ri-computer-line me-1"></i>{{ $strategy->devices_count }}
                                    <i class="ri-user-line ms-2 me-1"></i>{{ $strategy->users_count }}
                                    <i class="ri-folder-line ms-2 me-1"></i>{{ $strategy->device_groups_count }}
                                </span>
                                <div class="rd-mini-acts">
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('strategies.history') }}"
                                       wire:click="showHistory({{ $strategy->id }})"><i class="ri-history-line"></i></a>
                                    @if ($isAdmin)
                                        <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('strategies.compliance') }}"
                                           wire:click="showCompliance({{ $strategy->id }})"><i class="ri-pulse-line"></i></a>
                                    @endif
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('strategies.assign') }}"
                                       wire:click="openAssign({{ $strategy->id }})"><i class="ri-links-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}"
                                       wire:click="edit({{ $strategy->id }})"><i class="ri-pencil-line"></i></a>
                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('common.delete') }}"
                                       wire:click="deleteStrategy({{ $strategy->id }})"><i class="ri-delete-bin-line"></i></a>
                                </div>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                        <p class="rd-empty-title">{{ __('strategies.no_strategies_yet_tap_add') }}</p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">{{ __('strategies.add_strategy') }}</button>
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create / edit modal --}}
    @if ($editingId !== null)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             style="background: rgba(0,0,0,.6);" wire:key="strategy-editor">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editingId === 0 ? __('strategies.add_strategy') : __('strategies.edit_strategy') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-name">{{ __('common.name') }} <span class="text-danger">*</span></label>
                                    <input type="text" id="sl-name" class="form-control @error('formName') is-invalid @enderror"
                                           wire:model="formName" autocomplete="off">
                                    @error('formName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-note">{{ __('common.note') }}</label>
                                    <input type="text" id="sl-note" class="form-control @error('formNote') is-invalid @enderror"
                                           wire:model="formNote" maxlength="500">
                                    @error('formNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label" for="sl-revnote">{{ __('strategies.change_note') }} <span class="text-muted fw-normal">{{ __('strategies.kept_in_revision_history') }}</span></label>
                                    <input type="text" id="sl-revnote" class="form-control @error('revisionNote') is-invalid @enderror"
                                           wire:model="revisionNote" maxlength="500" placeholder="{{ __('strategies.what_changed_and_why') }}">
                                    @error('revisionNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enabled" wire:model="formEnabled">
                                        <label class="form-check-label" for="sl-enabled">{{ __('common.enabled') }}</label>
                                    </div>
                                    <small class="text-muted">{{ __('strategies.disabled_strategy_is_skipped_as') }}</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-default" wire:model="formIsDefault">
                                        <label class="form-check-label" for="sl-default">{{ __('strategies.default_strategy') }}</label>
                                    </div>
                                    <small class="text-muted">{{ __('strategies.applied_to_every_device_with') }}</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enforce" wire:model="formEnforce">
                                        <label class="form-check-label" for="sl-enforce">{{ __('strategies.enforce') }}</label>
                                    </div>
                                    <small class="text-muted">{{ __('strategies.re_push_on_every_heartbeat') }}</small>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="sl-timeout">{{ __('strategies.confirmation_timeout_minutes') }}</label>
                                    <input type="number" id="sl-timeout" min="1" max="10080" class="form-control @error('formConfirmationTimeout') is-invalid @enderror"
                                           wire:model="formConfirmationTimeout">
                                    @error('formConfirmationTimeout') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <small class="text-muted">{{ __('strategies.how_long_device_may_go') }}</small>
                                </div>
                            </div>

                            <div class="alert alert-secondary py-2 fs-13 mb-3">
                                <i class="ri-information-line me-1"></i>{{ __('strategies.controls_left_on') }} <strong>{{ __('strategies.not_managed') }}</strong> {{ __('strategies.are_not_part_of_this') }}
                            </div>

                            @foreach ($catalog as $groupKey => $group)
                                <h5 class="mt-3 mb-1 fs-15">
                                    <i class="{{ $group['icon'] }} me-1 text-muted"></i>{{ $group['title'] }}
                                </h5>
                                <p class="text-muted fs-13">{{ $group['help'] }}</p>

                                <div class="row">
                                    @foreach ($group['options'] as $key => $opt)
                                        <div class="col-12 {{ $opt['wide'] ? '' : 'col-md-6' }} mb-3" wire:key="opt-{{ $key }}">
                                            <label class="form-label mb-1" for="sl-opt-{{ $key }}">{{ $opt['label'] }}</label>
                                            @if ($opt['type'] === 'groups')
                                                @if ($whitelistGroups->isEmpty())
                                                    <div class="text-muted fs-13">{{ __('strategies.no_device_groups_yet') }}</div>
                                                @else
                                                    <div class="rd-check-grid" id="sl-opt-{{ $key }}">
                                                        @foreach ($whitelistGroups as $wg)
                                                            <label class="form-check mb-0" wire:key="wg-{{ $wg->id }}">
                                                                <input type="checkbox" class="form-check-input" value="{{ $wg->id }}" wire:model="formIdWhitelistGroups">
                                                                <span class="form-check-label">{{ $wg->name }} <span class="text-muted">({{ $wg->devices_count }})</span></span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            @elseif ($opt['choices'] !== null)
                                                <select id="sl-opt-{{ $key }}" class="form-select"
                                                        wire:model="formOptions.{{ $key }}">
                                                    <option value="">{{ __('strategies.not_managed') }}</option>
                                                    @foreach ($opt['choices'] as $value => $choiceLabel)
                                                        <option value="{{ $value }}">{{ $choiceLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" id="sl-opt-{{ $key }}"
                                                       class="form-control @error('formOptions.'.$key) is-invalid @enderror"
                                                       placeholder="{{ __('strategies.not_managed') }}"
                                                       wire:model="formOptions.{{ $key }}">
                                                @error('formOptions.'.$key) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                            @endif
                                            <small class="text-muted d-block">
                                                <code class="fs-12">{{ $opt['key'] }}</code>
                                                @if ($opt['help']) — {{ $opt['help'] }} @endif
                                            </small>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save">{{ __('strategies.review_impact') }}</span>
                                <span wire:loading wire:target="save">{{ __('strategies.calculating') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Policy impact preview --}}
    @if ($previewing && $editingId !== null)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="strategy-impact-title" style="background: rgba(0,0,0,.72); z-index: 1080;" wire:key="strategy-impact-preview">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-impact-title">
                                {{ $pendingDeleteId ? __('strategies.review_strategy_deletion') : ($restoreRevisionId ? __('strategies.review_revision_restore') : __('strategies.review_strategy_impact')) }}
                            </h5>
                            <small class="text-muted">{{ __('strategies.nothing_has_been_changed_yet') }}</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closePreview" aria-label="{{ __('strategies.back_to_editor') }}"></button>
                    </div>
                    <div class="modal-body">
                        @error('preview') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
                        @if ($pendingDeleteId)
                            <div class="alert alert-danger"><strong>{{ __('strategies.this_strategy_will_be_deleted') }}</strong> {{ __('strategies.direct_assignments_are_released_automati') }}</div>
                        @elseif ($restoreRevisionId)
                            <div class="alert alert-warning"><strong>{{ __('strategies.this_restore_creates_new_revision') }}</strong> {{ __('strategies.historical_revision_remains_unchanged') }}</div>
                        @endif
                        <div class="alert alert-info"><strong>{{ trans_choice('strategies.count_device_s', $impactPreview['affected_count'] ?? 0) }}</strong> {{ __('strategies.will_receive_different_effective_strateg') }}</div>
                        @if (!empty($impactPreview['dangerous']))
                            <div class="alert alert-warning"><strong>{{ __('strategies.high_impact_controls') }}</strong><ul class="mb-0 mt-1">
                                @foreach ($impactPreview['dangerous'] as $warning)
                                    <li><code>{{ $warning['key'] }}</code> {{ __('strategies.changes_to') }} <strong>{{ $warning['after'] }}</strong>.</li>
                                @endforeach
                            </ul></div>
                        @endif
                        @if (!empty($impactPreview['resets']))
                            <div class="alert alert-warning"><strong>{{ trans_choice('strategies.count_managed_option_s_will', count($impactPreview['resets'])) }}</strong> {{ implode(', ', $impactPreview['resets']) }}</div>
                        @endif
                        <h6>{{ __('strategies.policy_diff') }}</h6>
                        <div class="table-responsive mb-3"><table class="table table-sm mb-0">
                            <thead><tr><th>{{ __('strategies.setting') }}</th><th>{{ __('strategies.before') }}</th><th>{{ __('strategies.after') }}</th></tr></thead><tbody>
                            @forelse (($impactPreview['option_changes'] ?? []) as $key => $change)
                                <tr><td><code>{{ $key }}</code></td><td class="text-break">{{ $this->optionValueLabel($key, $change['before']) }}</td><td class="text-break">{{ $this->optionValueLabel($key, $change['after']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('strategies.no_option_changes') }}</td></tr>
                            @endforelse
                            @foreach (($impactPreview['metadata_changes'] ?? []) as $key => $change)
                                <tr><td>{{ str_replace('_', ' ', ucfirst($key)) }}</td><td>{{ is_bool($change['before']) ? ($change['before'] ? __('strategies.yes') : 'No') : ($change['before'] ?? '—') }}</td><td>{{ is_bool($change['after']) ? ($change['after'] ? __('strategies.yes') : 'No') : ($change['after'] ?? '—') }}</td></tr>
                            @endforeach
                            </tbody>
                        </table></div>
                        @if (!empty($impactPreview['affected_devices']))
                            <details><summary class="fw-semibold">{{ __('strategies.affected_device_sample_up_to') }}</summary><div class="d-flex flex-wrap gap-1 mt-2">
                                @foreach ($impactPreview['affected_devices'] as $device)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $device['rustdesk_id'] }} · {{ $device['label'] }} @if ($device['winning_level']) · {{ str_replace('_', ' ', $device['winning_level']) }} @endif</span>
                                @endforeach
                            </div></details>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light me-auto" wire:click="closePreview">{{ __('strategies.back') }}</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmSave">
                            <span wire:loading.remove wire:target="confirmSave">{{ $pendingDeleteId ? __('strategies.delete_strategy') : ($restoreRevisionId ? __('strategies.restore_revision') : __('strategies.apply_strategy')) }}</span>
                            <span wire:loading wire:target="confirmSave">{{ __('strategies.applying') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Assignment modal --}}
    @if ($assigning)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             style="background: rgba(0,0,0,.6);" wire:key="strategy-assign">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('strategies.assign_name', ['name' => $assigning->name]) }}</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="{{ __('common.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            {{ __('strategies.device_gets_one_strategy_its') }}
                        </p>

                        <ul class="nav nav-tabs nav-bordered mb-3">
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'devices' ? 'active' : '' }}"
                                   wire:click="setAssignTab('devices')">
                                    <i class="ri-computer-line me-1"></i>{{ __('common.devices') }}
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignDeviceIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'users' ? 'active' : '' }}"
                                   wire:click="setAssignTab('users')">
                                    <i class="ri-user-line me-1"></i>{{ __('common.users') }}
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignUserIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'groups' ? 'active' : '' }}"
                                   wire:click="setAssignTab('groups')">
                                    <i class="ri-folder-line me-1"></i>{{ __('strategies.device_groups') }}
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignGroupIds) }}</span>
                                </a>
                            </li>
                        </ul>

                        @if ($assignTab === 'devices')
                            <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control mb-2" placeholder="{{ __('common.search_id_alias_hostname') }}"
                                   wire:model.live.debounce.300ms="assignSearch">
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignDevices as $d)
                                        <tr wire:key="ad{{ $d->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-dev-{{ $d->id }}"
                                                       value="{{ $d->id }}" wire:model="assignDeviceIds"
                                                       aria-label="Assign device {{ $d->rustdesk_id }}">
                                            </td>
                                            <td>
                                                {{-- The label is the tap target: a 14px checkbox is not one, and
                                                     `for` makes the whole cell toggle it without a second binding. --}}
                                                <label class="rd-picklabel" for="sa-dev-{{ $d->id }}">
                                                    <span class="fw-semibold">{{ $d->rustdesk_id }}</span>
                                                    @if ($d->alias || $d->hostname)
                                                        <small class="text-muted d-block">{{ $d->alias ?: $d->hostname }}</small>
                                                    @endif
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['devices'][$d->id] ?? null) && $assignTaken['devices'][$d->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['devices'][$d->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">{{ __('common.no_devices_match') }}</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">
                                {{ __('common.count_selected', ['count' => count($assignDeviceIds)]) }}
                                @if ($assignDevices->count() >= 200) · {{ __('common.showing_first_200_refine_with') }} @endif
                            </small>
                        @elseif ($assignTab === 'users')
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignUsers as $u)
                                        <tr wire:key="au{{ $u->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-usr-{{ $u->id }}"
                                                       value="{{ $u->id }}" wire:model="assignUserIds"
                                                       aria-label="Assign user {{ $u->username }}">
                                            </td>
                                            <td>
                                                <label class="rd-picklabel" for="sa-usr-{{ $u->id }}">
                                                    <span class="fw-semibold">{{ $u->username }}</span>
                                                    @if ($u->name)
                                                        <small class="text-muted d-block">{{ $u->name }}</small>
                                                    @endif
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['users'][$u->id] ?? null) && $assignTaken['users'][$u->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['users'][$u->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">{{ __('strategies.no_users_yet') }}</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">{{ __('strategies.applies_to_every_device_owned') }}</small>
                        @else
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignGroups as $g)
                                        <tr wire:key="ag{{ $g->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-grp-{{ $g->id }}"
                                                       value="{{ $g->id }}" wire:model="assignGroupIds"
                                                       aria-label="Assign device group {{ $g->name }}">
                                            </td>
                                            <td>
                                                <label class="rd-picklabel" for="sa-grp-{{ $g->id }}">
                                                    <span class="fw-semibold">{{ $g->name }}</span>
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['groups'][$g->id] ?? null) && $assignTaken['groups'][$g->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['groups'][$g->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">{{ __('strategies.no_device_groups_yet') }}</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">{{ __('strategies.applies_to_every_device_in') }}</small>
                        @endif
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

    {{-- Immutable revision history and comparison --}}
    @if ($historyStrategy)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="strategy-history-title" wire:keydown.escape.window="closeHistory"
             style="background: rgba(0,0,0,.65);" wire:key="strategy-history">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-history-title">{{ __('strategies.name_revision_history', ['name' => $historyStrategy->name]) }}</h5>
                            <small class="text-muted">{{ __('strategies.restoring_creates_new_revision_existing') }}</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeHistory" aria-label="{{ __('strategies.close_history') }}"></button>
                    </div>
                    <div class="modal-body">
                        @error('history') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
                        @if ($revisionHistory->isNotEmpty())
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-from">{{ __('strategies.compare_from') }}</label>
                                    <select id="compare-from" class="form-select" wire:model.live="compareFromRevisionId">
                                        <option value="">{{ __('strategies.choose_revision') }}</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">{{ __('strategies.revision_n', ['n' => $revision->revision]) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-to">{{ __('strategies.compare_to') }}</label>
                                    <select id="compare-to" class="form-select" wire:model.live="compareToRevisionId">
                                        <option value="">{{ __('strategies.choose_revision') }}</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">{{ __('strategies.revision_n', ['n' => $revision->revision]) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @if ($compareFromRevisionId && $compareToRevisionId)
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm"><thead><tr><th>{{ __('strategies.field') }}</th><th>{{ __('strategies.compare_from') }}</th><th>{{ __('strategies.compare_to') }}</th></tr></thead><tbody>
                                    @forelse ($revisionComparison as $change)
                                        <tr><td><code>{{ $change['key'] }}</code></td><td class="text-break">{{ is_bool($change['before']) ? ($change['before'] ? __('strategies.yes') : 'No') : $this->optionValueLabel($change['key'], $change['before']) }}</td><td class="text-break">{{ is_bool($change['after']) ? ($change['after'] ? __('strategies.yes') : 'No') : $this->optionValueLabel($change['key'], $change['after']) }}</td></tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted">{{ __('strategies.these_revisions_are_identical') }}</td></tr>
                                    @endforelse
                                    </tbody></table>
                                </div>
                            @endif
                            <div class="list-group">
                                @foreach ($revisionHistory as $revision)
                                    <div class="list-group-item d-flex flex-column flex-md-row justify-content-between gap-2" wire:key="revision-{{ $revision->id }}">
                                        <div>
                                            <strong>{{ __('strategies.revision_n', ['n' => $revision->revision]) }}</strong>
                                            @if ($historyStrategy->active_revision_id === $revision->id)<span class="badge bg-success-subtle text-success ms-1">Active</span>@endif
                                            <div class="text-muted fs-13">{{ $revision->created_by_name ?? $revision->creator?->username ?? __('common.system') }} · {{ $revision->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }} · {{ trans_choice('strategies.count_affected_device_s', $revision->affected_devices) }}</div>
                                            @if ($revision->change_note)<div class="mt-1">{{ $revision->change_note }}</div>@endif
                                        </div>
                                        @if ($historyStrategy->active_revision_id !== $revision->id)
                                            <button type="button" class="btn btn-sm btn-outline-warning align-self-md-center" wire:click="restoreRevision({{ $revision->id }})">{{ __('strategies.restore_as_new_revision') }}</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-muted">{{ __('strategies.no_revisions_have_been_captured') }}</div>
                        @endif
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeHistory">{{ __('common.close') }}</button></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Compliance drill-down (admins only) --}}
    @if ($complianceStrategy && $complianceSummary)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="strategy-compliance-title"
             wire:keydown.escape.window="closeCompliance" style="background: rgba(0,0,0,.65);" wire:key="strategy-compliance">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-compliance-title">{{ __('strategies.name_compliance', ['name' => $complianceStrategy->name]) }}</h5>
                            <small class="text-muted">{{ __('strategies.what_each_device_is_holding') }}</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeCompliance" aria-label="{{ __('common.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @foreach (['all' => 'All', 'confirmed' => 'Confirmed', 'pending' => 'Pending', 'stale' => 'Stale', 'offline' => 'Offline', 'overridden' => 'Overridden'] as $state => $label)
                                <button type="button" class="btn btn-sm {{ $complianceState === $state ? 'btn-primary' : 'btn-outline-secondary' }}"
                                        wire:click="setComplianceState('{{ $state }}')">
                                    {{ $label }}@if ($state !== 'all') ({{ $complianceSummary['counts'][$state] }})@endif
                                </button>
                            @endforeach
                        </div>
                        @php($complianceTotal = $complianceState === 'all' ? array_sum($complianceSummary['counts']) : ($complianceSummary['counts'][$complianceState] ?? 0))
                        @if ($complianceTotal > count($complianceDevices))
                            <div class="alert alert-info py-2 fs-13">{{ __('strategies.showing_first_count_of_total', ['count' => count($complianceDevices), 'total' => $complianceTotal]) }}</div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead><tr><th>{{ __('common.device') }}</th><th>{{ __('strategies.state') }}</th><th>{{ __('strategies.last_online') }}</th><th>{{ __('strategies.sent') }}</th><th>{{ __('strategies.confirmed') }}</th></tr></thead>
                                <tbody>
                                @forelse ($complianceDevices as $device)
                                    <tr wire:key="compliance-{{ $device['id'] }}-{{ $device['state'] }}">
                                        <td><strong>{{ $device['rustdesk_id'] }}</strong><small class="d-block text-muted">{{ $device['label'] }}</small></td>
                                        <td>
                                            @php($tone = ['confirmed' => 'success', 'pending' => 'info', 'stale' => 'warning', 'offline' => 'secondary', 'overridden' => 'secondary'][$device['state']])
                                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} text-capitalize">{{ $device['state'] }}</span>
                                        </td>
                                        <td class="text-nowrap">{{ $device['last_online'] }}</td>
                                        <td class="text-nowrap">{{ $device['sent'] }}</td>
                                        <td class="text-nowrap">{{ $device['confirmed'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">{{ __('strategies.no_devices_in_this_state') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeCompliance">{{ __('common.close') }}</button></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
