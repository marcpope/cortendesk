{{-- Add / Edit device modal. Driven by App\Livewire\Concerns\EditsDevices;
     the host passes editorViewData(). --}}
@if ($editingId !== null)
    {{-- Scrollable: with the strategy inspector open this form is taller than a
         390px screen, and an unbounded body puts "Save Changes" out of reach. --}}
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.6);" wire:keydown.escape="closeModal">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form wire:submit="save">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editingId === 0 ? 'Add Device' : 'Edit Device' }}</h5>
                        <button type="button" class="btn-close" wire:click="closeModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">RustDesk ID</label>
                            <input type="text" class="form-control @error('formRustdeskId') is-invalid @enderror"
                                   wire:model="formRustdeskId" @disabled($editingId !== 0)>
                            @error('formRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if ($editingId === 0)
                                <div class="form-text">Pre-register a device by its RustDesk ID; details fill in when it first reports.</div>
                            @endif
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Alias</label>
                            <input type="text" class="form-control" wire:model="formAlias" placeholder="Friendly name">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Group</label>
                                <select class="form-select" wire:model="formGroupId">
                                    <option value="0">No group</option>
                                    @foreach ($editorGroups as $g)
                                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Owner</label>
                                <select class="form-select" wire:model="formUserId">
                                    <option value="0">Unassigned</option>
                                    @foreach ($editorUsers as $u)
                                        <option value="{{ $u->id }}">{{ $u->username }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea class="form-control" rows="2" wire:model="formNote" maxlength="500"></textarea>
                        </div>
                        <div class="mb-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="dl-can-initiate"
                                       wire:model="formCanInitiate">
                                <label class="form-check-label" for="dl-can-initiate">Can start sessions</label>
                            </div>
                            <div class="form-text">Off makes the device incoming only: it can be controlled but cannot connect to others. Enforced by the ID server.</div>
                        </div>

                        {{-- Effective strategy inspector (PLAN C4) --}}
                        @if ($editingId !== 0 && auth()->user()?->is_admin && $strategyExplain)
                            <hr class="my-3">
                            <label class="form-label" for="dl-strategy">Strategy</label>
                            <select id="dl-strategy" class="form-select" wire:model="formStrategyId">
                                <option value="0">Inherit (owner, group, or default)</option>
                                @foreach ($strategies as $s)
                                    <option value="{{ $s->id }}">
                                        {{ $s->name }}@unless ($s->enabled) (disabled)@endunless
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">A strategy set here wins over the owner's, the group's and the default.</div>

                            <div class="mt-2 rd-inset">
                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="fs-13 text-muted">In force now</span>
                                    @if ($strategyExplain['resolved'])
                                        <span class="badge bg-success-subtle text-success">{{ $strategyExplain['resolved']->name }}</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">None</span>
                                    @endif
                                </div>
                                <ul class="list-unstyled mb-0 mt-2 fs-13">
                                    @foreach ($strategyExplain['steps'] as $step)
                                        <li class="d-flex justify-content-between align-items-start gap-2 py-1 border-top">
                                            <span class="text-muted">
                                                {{ $step['label'] }}@if ($step['target'])<span class="text-body"> · {{ $step['target'] }}</span>@endif
                                            </span>
                                            <span class="text-end">
                                                @switch ($step['state'])
                                                    @case ('applied')
                                                        <span class="fw-semibold">{{ $step['strategy']->name }}</span>
                                                        <span class="badge bg-success-subtle text-success ms-1">Wins</span>
                                                        @break
                                                    @case ('overridden')
                                                        <span>{{ $step['strategy']->name }}</span>
                                                        <span class="badge bg-secondary-subtle text-secondary ms-1">Overridden</span>
                                                        @break
                                                    @case ('disabled')
                                                        <span>{{ $step['strategy']->name }}</span>
                                                        <span class="badge bg-warning-subtle text-warning ms-1">Disabled — skipped</span>
                                                        @break
                                                    @case ('unset')
                                                        <span class="text-muted">Not set</span>
                                                        @break
                                                    @default
                                                        <span class="text-muted">No strategy</span>
                                                @endswitch
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($strategyExplain['acked_at'])
                                    <div class="fs-13 text-muted mt-2">
                                        Device last confirmed a policy {{ $strategyExplain['acked_at']->diffForHumans() }}.
                                    </div>
                                @elseif ($strategyExplain['resolved'])
                                    <div class="fs-13 text-muted mt-2">
                                        Not confirmed by the device yet — it applies on its next heartbeat.
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $editingId === 0 ? 'Add Device' : 'Save Changes' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
