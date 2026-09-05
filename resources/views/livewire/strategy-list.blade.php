<div>
    <div class="card">

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div>
                    <h4 class="header-title">Strategies</h4>
                    <p class="rd-card-sub mb-0">Client settings pushed to devices on their next heartbeat.</p>
                </div>
                <div class="rd-toolbar-actions">
                    <button type="button" class="btn btn-primary" wire:click="create">
                        <i class="ri-add-line"></i>Add Strategy
                    </button>
                </div>
            </div>

            @if ($strategies->isNotEmpty() && $strategies->firstWhere('is_default', true) === null)
                <div class="rd-toolbar">
                    <div class="alert alert-secondary py-2 mb-0 w-100">
                        <i class="ri-information-line me-1"></i>No default strategy. Devices with no assignment of their own keep whatever settings they already have.
                    </div>
                </div>
            @endif

            {{-- Desktop table (md and up) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Options</th>
                        <th>Assigned to</th>
                        <th>In force on</th>
                        <th>Enabled</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($strategies as $strategy)
                        <tr wire:key="s{{ $strategy->id }}">
                            <td>
                                <span class="rd-cell-title d-inline">{{ $strategy->name }}</span>
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary ms-1">Default</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning ms-1" title="Re-pushed on every heartbeat, overwriting local changes">Enforced</span>
                                @endif
                                @if ($strategy->note)
                                    <small class="text-muted d-block">{{ $strategy->note }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">{{ count($strategy->optionMap()) }}</span>
                            </td>
                            <td>
                                <span class="text-nowrap" title="Devices">
                                    <i class="ri-computer-line me-1 text-muted"></i>{{ $strategy->devices_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="Users">
                                    <i class="ri-user-line me-1 text-muted"></i>{{ $strategy->users_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="Device groups">
                                    <i class="ri-folder-line me-1 text-muted"></i>{{ $strategy->device_groups_count }}
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info">{{ $strategy->resolved_devices_count }} device(s)</span>
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="strategy-enabled-{{ $strategy->id }}"
                                           @checked($strategy->enabled)
                                           wire:click="toggleEnabled({{ $strategy->id }})">
                                    <label class="form-check-label visually-hidden" for="strategy-enabled-{{ $strategy->id }}">Enabled</label>
                                </div>
                            </td>
                            <td class="text-end rd-rowact">
                                <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" wire:click="showHistory({{ $strategy->id }})">History</button>
                                @if ($isAdmin)
                                    <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" wire:click="showCompliance({{ $strategy->id }})">Compliance</button>
                                @endif
                                @if ($canAssignFleet)
                                    <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" wire:click="openAssign({{ $strategy->id }})">Assign</button>
                                @endif
                                <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" wire:click="edit({{ $strategy->id }})">Edit</button>
                                <button type="button" class="btn btn-link btn-sm p-0 text-danger"
                                   wire:click="deleteStrategy({{ $strategy->id }})"
                                   wire:confirm="Delete strategy {{ $strategy->name }}? Devices assigned to it fall back to the default strategy, and the options it pushed are reset to the client defaults on the next heartbeat.">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                                    <p class="rd-empty-title">No strategies yet. Click "Add Strategy" to create one.</p>
                                    <p class="rd-empty-text">A strategy is a set of client options the server pushes out — permissions, defaults, whatever you want held steady across a fleet.</p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">Add Strategy</button>
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
                                    <label class="form-check-label visually-hidden" for="m-strategy-enabled-{{ $strategy->id }}">Enabled</label>
                                </div>
                            </div>
                            <div class="mt-2">
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary">Default</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning">Enforced</span>
                                @endif
                                <span class="badge bg-info-subtle text-info">{{ $strategy->resolved_devices_count }} in force</span>
                            </div>
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub text-nowrap">
                                    <i class="ri-computer-line me-1"></i>{{ $strategy->devices_count }}
                                    <i class="ri-user-line ms-2 me-1"></i>{{ $strategy->users_count }}
                                    <i class="ri-folder-line ms-2 me-1"></i>{{ $strategy->device_groups_count }}
                                </span>
                                <div class="rd-mini-acts">
                                    <button type="button" class="rd-iconbtn border-0" title="Revision history" aria-label="Revision history for {{ $strategy->name }}"
                                       wire:click="showHistory({{ $strategy->id }})"><i class="ri-history-line"></i></button>
                                    @if ($isAdmin)
                                        <button type="button" class="rd-iconbtn border-0" title="Compliance" aria-label="Compliance for {{ $strategy->name }}"
                                           wire:click="showCompliance({{ $strategy->id }})"><i class="ri-pulse-line"></i></button>
                                    @endif
                                    @if ($canAssignFleet)
                                        <button type="button" class="rd-iconbtn border-0" title="Assign" aria-label="Assign {{ $strategy->name }}"
                                           wire:click="openAssign({{ $strategy->id }})"><i class="ri-links-line"></i></button>
                                    @endif
                                    <button type="button" class="rd-iconbtn border-0" title="Edit" aria-label="Edit {{ $strategy->name }}"
                                       wire:click="edit({{ $strategy->id }})"><i class="ri-pencil-line"></i></button>
                                    <button type="button" class="rd-iconbtn border-0 text-danger" title="Delete" aria-label="Delete {{ $strategy->name }}"
                                       wire:click="deleteStrategy({{ $strategy->id }})"
                                       wire:confirm="Delete strategy {{ $strategy->name }}? Devices assigned to it fall back to the default strategy."><i class="ri-delete-bin-line"></i></button>
                                </div>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                        <p class="rd-empty-title">No strategies yet. Tap "Add Strategy" to create one.</p>
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">Add Strategy</button>
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create / edit modal --}}
    @if ($editingId !== null && ! $previewing)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             style="background: rgba(0,0,0,.6);" wire:key="strategy-editor">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="previewSave">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $editingId === 0 ? 'Add Strategy' : 'Edit Strategy' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-name">Name <span class="text-danger">*</span></label>
                                    <input type="text" id="sl-name" class="form-control @error('formName') is-invalid @enderror"
                                           wire:model="formName" autocomplete="off">
                                    @error('formName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-note">Note</label>
                                    <input type="text" id="sl-note" class="form-control @error('formNote') is-invalid @enderror"
                                           wire:model="formNote" maxlength="500">
                                    @error('formNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enabled" wire:model="formEnabled">
                                        <label class="form-check-label" for="sl-enabled">Enabled</label>
                                    </div>
                                    <small class="text-muted">A disabled strategy is skipped as if it were not assigned.</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-default" wire:model="formIsDefault">
                                        <label class="form-check-label" for="sl-default">Default strategy</label>
                                    </div>
                                    <small class="text-muted">Applied to every device with no assignment of its own. Only one strategy can hold this.</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enforce" wire:model="formEnforce">
                                        <label class="form-check-label" for="sl-enforce">Enforce</label>
                                    </div>
                                    <small class="text-muted">Re-push on every heartbeat, so a change made on the device is undone within a minute. Off = push once, then leave the device alone.</small>
                                </div>
                            </div>

                            <div class="alert alert-secondary py-2 fs-13 mb-3">
                                <i class="ri-information-line me-1"></i>Controls left on <strong>Not managed</strong> are not part of this strategy: the device keeps whatever it has. Changing a managed option back to Not managed resets that option to the client's built-in default on the next heartbeat.
                            </div>

                            @foreach ($catalog as $groupKey => $group)
                                <h5 class="mt-3 mb-1 fs-15">
                                    <i class="{{ $group['icon'] }} me-1 text-muted"></i>{{ $group['title'] }}
                                </h5>
                                <p class="text-muted fs-13">{{ $group['help'] }}</p>

                                <div class="row">
                                    @foreach ($group['options'] as $key => $opt)
                                        <div class="col-12 col-md-6 mb-3" wire:key="opt-{{ $key }}">
                                            <label class="form-label mb-1" for="sl-opt-{{ $key }}">{{ $opt['label'] }}</label>
                                            @if ($opt['choices'] !== null)
                                                <select id="sl-opt-{{ $key }}" class="form-select"
                                                        wire:model="formOptions.{{ $key }}">
                                                    <option value="">Not managed</option>
                                                    @foreach ($opt['choices'] as $value => $choiceLabel)
                                                        <option value="{{ $value }}">{{ $choiceLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" id="sl-opt-{{ $key }}"
                                                       class="form-control @error('formOptions.'.$key) is-invalid @enderror"
                                                       placeholder="Not managed"
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
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="previewSave">Review Impact</span>
                                <span wire:loading wire:target="previewSave">Calculating…</span>
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
                                {{ $pendingDeleteId ? 'Review strategy deletion' : ($restoreRevisionId ? 'Review revision restore' : 'Review strategy impact') }}
                            </h5>
                            <small class="text-muted">Nothing has been changed yet.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closePreview" aria-label="Back to editor"></button>
                    </div>
                    <div class="modal-body">
                        @error('preview') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
                        @if ($pendingDeleteId)
                            <div class="alert alert-danger"><strong>This strategy will be deleted.</strong> Its immutable revision history remains available in storage, while direct assignments are released.</div>
                        @elseif ($restoreRevisionId)
                            <div class="alert alert-warning"><strong>This restore creates a new revision.</strong> The historical row itself remains unchanged.</div>
                        @endif
                        <div class="alert alert-info"><strong>{{ $impactPreview['affected_count'] ?? 0 }} device(s)</strong> will receive a different effective strategy or policy.</div>
                        @if (!empty($impactPreview['dangerous']))
                            <div class="alert alert-warning"><strong>High-impact controls</strong><ul class="mb-0 mt-1">
                                @foreach ($impactPreview['dangerous'] as $warning)
                                    <li><code>{{ $warning['key'] }}</code> changes to <strong>{{ $warning['after'] }}</strong>.</li>
                                @endforeach
                            </ul></div>
                        @endif
                        @if (!empty($impactPreview['resets']))
                            <div class="alert alert-warning"><strong>{{ count($impactPreview['resets']) }} managed option(s) will reset to the client default:</strong> {{ implode(', ', $impactPreview['resets']) }}</div>
                        @endif
                        <h6>Policy diff</h6>
                        <div class="table-responsive mb-3"><table class="table table-sm mb-0">
                            <thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead><tbody>
                            @forelse (($impactPreview['option_changes'] ?? []) as $key => $change)
                                <tr><td><code>{{ $key }}</code></td><td>{{ $change['before'] ?? 'Not managed' }}</td><td>{{ $change['after'] ?? 'Not managed' }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">No option changes.</td></tr>
                            @endforelse
                            @foreach (($impactPreview['metadata_changes'] ?? []) as $key => $change)
                                <tr><td>{{ str_replace('_', ' ', ucfirst($key)) }}</td><td>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] ?? '—') }}</td><td>{{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] ?? '—') }}</td></tr>
                            @endforeach
                            </tbody>
                        </table></div>
                        @if (!empty($impactPreview['affected_devices']))
                            <details><summary class="fw-semibold">Affected-device sample (up to 50)</summary><div class="d-flex flex-wrap gap-1 mt-2">
                                @foreach ($impactPreview['affected_devices'] as $device)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $device['rustdesk_id'] }} · {{ $device['label'] }} @if ($device['winning_level']) · {{ str_replace('_', ' ', $device['winning_level']) }} @endif</span>
                                @endforeach
                            </div></details>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light me-auto" wire:click="closePreview">Back</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmSave">
                            <span wire:loading.remove wire:target="confirmSave">{{ $pendingDeleteId ? 'Delete strategy' : ($restoreRevisionId ? 'Restore revision' : 'Apply strategy') }}</span>
                            <span wire:loading wire:target="confirmSave">Applying…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Assignment modal --}}
    @if ($assigning && ! $assignPreviewing)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             style="background: rgba(0,0,0,.6);" wire:key="strategy-assign">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign "{{ $assigning->name }}"</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            A device gets one strategy: its own assignment wins, then its owner's, then its device group's, then the default.
                            Checking a target that already belongs to another strategy moves it here.
                        </p>

                        <ul class="nav nav-tabs nav-bordered mb-3">
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'devices' ? 'active' : '' }}"
                                   wire:click="setAssignTab('devices')">
                                    <i class="ri-computer-line me-1"></i>Devices
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignDeviceIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'users' ? 'active' : '' }}"
                                   wire:click="setAssignTab('users')">
                                    <i class="ri-user-line me-1"></i>Users
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignUserIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'groups' ? 'active' : '' }}"
                                   wire:click="setAssignTab('groups')">
                                    <i class="ri-folder-line me-1"></i>Device groups
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignGroupIds) }}</span>
                                </a>
                            </li>
                        </ul>

                        @if ($assignTab === 'devices')
                            <input type="search" class="form-control mb-2" placeholder="Search ID, alias, hostname…"
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
                                        <tr><td class="text-center text-muted py-3">No devices match.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">
                                {{ count($assignDeviceIds) }} selected
                                @if ($assignDevices->count() >= 200) · showing first 200, refine with search @endif
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
                                        <tr><td class="text-center text-muted py-3">No users yet.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">Applies to every device owned by the checked users.</small>
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
                                        <tr><td class="text-center text-muted py-3">No device groups yet.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">Applies to every device in the checked groups that has no closer assignment.</small>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeAssign">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="previewAssign">
                            <span wire:loading.remove wire:target="previewAssign">Review Assignment Impact</span>
                            <span wire:loading wire:target="previewAssign">Calculating…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Assignment impact preview --}}
    @if ($assignPreviewing && $assigning)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="assignment-impact-title" style="background: rgba(0,0,0,.72); z-index: 1080;" wire:key="assignment-impact-preview">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="assignment-impact-title">Review assignment impact</h5>
                            <small class="text-muted">Nothing has been reassigned yet.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeAssignPreview" aria-label="Back to assignments"></button>
                    </div>
                    <div class="modal-body">
                        @error('assignment') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
                        <div class="alert alert-info"><strong>{{ $assignmentImpact['affected_count'] ?? 0 }} device(s)</strong> will resolve to a different strategy.</div>
                        <div class="row g-2 mb-3">
                            @foreach (['device' => 'Direct devices', 'user' => 'Users', 'device_group' => 'Device groups'] as $level => $label)
                                @php($change = $assignmentImpact['assignment_changes'][$level] ?? ['added_count' => 0, 'removed_count' => 0])
                                <div class="col-12 col-md-4"><div class="card border h-100"><div class="card-body py-2">
                                    <strong>{{ $label }}</strong><div class="text-success">+ {{ $change['added_count'] }}</div><div class="text-danger">− {{ $change['removed_count'] }}</div>
                                </div></div></div>
                            @endforeach
                        </div>
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>Device</th><th>Before</th><th>After</th><th>Winning level</th></tr></thead><tbody>
                            @forelse (($assignmentImpact['affected_devices'] ?? []) as $device)
                                <tr><td><strong>{{ $device['rustdesk_id'] }}</strong><small class="text-muted d-block">{{ $device['label'] }}</small></td><td>{{ $device['before_strategy'] }}</td><td>{{ $device['after_strategy'] }}</td><td class="text-capitalize">{{ str_replace('_', ' ', $device['winning_level'] ?? 'none') }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No device changes. Only assignment metadata will change.</td></tr>
                            @endforelse
                            </tbody>
                        </table></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light me-auto" wire:click="closeAssignPreview">Back</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmAssign">
                            <span wire:loading.remove wire:target="confirmAssign">Apply assignments</span>
                            <span wire:loading wire:target="confirmAssign">Applying…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
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
                            <h5 class="modal-title" id="strategy-history-title">{{ $historyStrategy->name }} revision history</h5>
                            <small class="text-muted">Restoring creates a new revision; existing history is never rewritten.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeHistory" aria-label="Close history"></button>
                    </div>
                    <div class="modal-body">
                        @error('history') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
                        @if ($revisionHistory->isNotEmpty())
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-from">Compare from</label>
                                    <select id="compare-from" class="form-select" wire:model.live="compareFromRevisionId">
                                        <option value="">Choose revision</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">Revision {{ $revision->revision }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-to">Compare to</label>
                                    <select id="compare-to" class="form-select" wire:model.live="compareToRevisionId">
                                        <option value="">Choose revision</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">Revision {{ $revision->revision }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @if ($compareFromRevisionId && $compareToRevisionId)
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm"><thead><tr><th>Field</th><th>From</th><th>To</th></tr></thead><tbody>
                                    @forelse ($revisionComparison as $change)
                                        <tr><td><code>{{ $change['key'] }}</code></td><td>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] ?? 'Not managed') }}</td><td>{{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] ?? 'Not managed') }}</td></tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted">These revisions are identical.</td></tr>
                                    @endforelse
                                    </tbody></table>
                                </div>
                            @endif
                            <div class="list-group">
                                @foreach ($revisionHistory as $revision)
                                    <div class="list-group-item d-flex flex-column flex-md-row justify-content-between gap-2" wire:key="revision-{{ $revision->id }}">
                                        <div>
                                            <strong>Revision {{ $revision->revision }}</strong>
                                            @if ($historyStrategy->active_revision_id === $revision->id)<span class="badge bg-success-subtle text-success ms-1">Active</span>@endif
                                            <div class="text-muted fs-13">{{ $revision->created_by_name ?? $revision->creator?->username ?? 'System' }} · {{ $revision->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }} · {{ $revision->affected_devices }} affected device(s)</div>
                                            @if ($revision->change_note)<div class="mt-1">{{ $revision->change_note }}</div>@endif
                                        </div>
                                        @if ($historyStrategy->active_revision_id !== $revision->id)
                                            <button type="button" class="btn btn-sm btn-outline-warning align-self-md-center" wire:click="restoreRevision({{ $revision->id }})" wire:confirm="Restore the options from revision {{ $revision->revision }}? This creates a new revision. Name, enabled and default are not changed.">Restore as new revision</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-muted">No revisions have been captured yet.</div>
                        @endif
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeHistory">Close</button></div>
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
                            <h5 class="modal-title" id="strategy-compliance-title">{{ $complianceStrategy->name }} compliance</h5>
                            <small class="text-muted">What each device is holding versus what this strategy wants.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeCompliance" aria-label="Close"></button>
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
                            <div class="alert alert-info py-2 fs-13">Showing the first {{ count($complianceDevices) }} of {{ $complianceTotal }} devices.</div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead><tr><th>Device</th><th>State</th><th>Last online</th><th>Sent</th><th>Confirmed</th></tr></thead>
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
                                    <tr><td colspan="5" class="text-center text-muted py-4">No devices in this state.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeCompliance">Close</button></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif</div>
