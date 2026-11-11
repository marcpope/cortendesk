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
