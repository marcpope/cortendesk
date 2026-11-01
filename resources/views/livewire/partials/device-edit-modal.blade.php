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
                        <h5 class="modal-title">{{ $editingId === 0 ? __('devices.add_device') : __('devices.edit_device') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">{{ __('common.rustdesk_id') }}</label>
                            <input type="text" class="form-control @error('formRustdeskId') is-invalid @enderror"
                                   wire:model="formRustdeskId" @disabled($editingId !== 0)>
                            @error('formRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if ($editingId === 0)
                                <div class="form-text">{{ __('devices.pre_register_device_by_its') }}</div>
                            @endif
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('common.alias') }}</label>
                            <input type="text" class="form-control" wire:model="formAlias" placeholder="{{ __('devices.friendly_name') }}">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('common.group') }}</label>
                                <select class="form-select" wire:model="formGroupId">
                                    <option value="0">{{ __('devices.no_group') }}</option>
                                    @foreach ($editorGroups as $g)
                                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('devices.owner') }}</label>
                                <select class="form-select" wire:model="formUserId">
                                    <option value="0">{{ __('devices.unassigned') }}</option>
                                    @foreach ($editorUsers as $u)
                                        <option value="{{ $u->id }}">{{ $u->username }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('common.note') }}</label>
                            <textarea class="form-control" rows="2" wire:model="formNote" maxlength="500"></textarea>
                        </div>
                        <div class="mb-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="dl-can-initiate"
                                       wire:model="formCanInitiate">
                                <label class="form-check-label" for="dl-can-initiate">{{ __('devices.can_start_sessions') }}</label>
                            </div>
                            <div class="form-text">{{ __('devices.off_makes_device_incoming_only') }}</div>
                        </div>

                        {{-- Effective strategy inspector (PLAN C4) --}}
                        @if ($editingId !== 0 && auth()->user()?->is_admin && $strategyExplain)
                            <hr class="my-3">
                            <label class="form-label" for="dl-strategy">{{ __('devices.strategy') }}</label>
                            <select id="dl-strategy" class="form-select" wire:model="formStrategyId">
                                <option value="0">{{ __('devices.inherit_owner_group_or_default') }}</option>
                                @foreach ($strategies as $s)
                                    <option value="{{ $s->id }}">
                                        {{ $s->name }}@unless ($s->enabled) (disabled)@endunless
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('devices.strategy_set_here_wins_over') }}</div>

                            <div class="mt-2 rd-inset">
                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="fs-13 text-muted">{{ __('devices.in_force_now') }}</span>
                                    @if ($strategyExplain['resolved'])
                                        <span class="badge bg-success-subtle text-success">{{ $strategyExplain['resolved']->name }}</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">{{ __('common.none') }}</span>
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
                                                        <span class="badge bg-success-subtle text-success ms-1">{{ __('devices.wins') }}</span>
                                                        @break
                                                    @case ('overridden')
                                                        <span>{{ $step['strategy']->name }}</span>
                                                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ __('devices.overridden') }}</span>
                                                        @break
                                                    @case ('disabled')
                                                        <span>{{ $step['strategy']->name }}</span>
                                                        <span class="badge bg-warning-subtle text-warning ms-1">{{ __('devices.disabled_skipped') }}</span>
                                                        @break
                                                    @case ('unset')
                                                        <span class="text-muted">{{ __('devices.not_set') }}</span>
                                                        @break
                                                    @default
                                                        <span class="text-muted">{{ __('devices.no_strategy') }}</span>
                                                @endswitch
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($strategyExplain['acked_at'])
                                    <div class="fs-13 text-muted mt-2">
                                        {{ __('devices.device_last_confirmed_policy_when', ['when' => $strategyExplain['acked_at']->diffForHumans()]) }}
                                    </div>
                                @elseif ($strategyExplain['resolved'])
                                    <div class="fs-13 text-muted mt-2">
                                        {{ __('devices.not_confirmed_by_device_yet') }}
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ $editingId === 0 ? __('devices.add_device') : __('account.save_changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
