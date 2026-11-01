{{-- Delete confirm step (issue #85). Driven by App\Livewire\Concerns\DeletesDevices;
     the host passes deleteConfirmState() as $deleteState. --}}
@if ($deleteState !== null)
    @php
        $delCount = $deleteState['devices']->count();
        $delEntries = $deleteState['entries'];
    @endphp
    <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="delete-device-title"
         style="background: rgba(0,0,0,.6);" wire:keydown.escape="cancelDelete">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form wire:submit="performDelete">
                    <div class="modal-header">
                        <h5 id="delete-device-title" class="modal-title">
                            @if ($delCount === 1)
                                {{ __('devices.delete_device_id', ['id' => $deleteState['devices']->first()->rustdesk_id]) }}
                            @else
                                {{ __('devices.delete_count_devices', ['count' => $delCount]) }}
                            @endif
                        </h5>
                        <button type="button" class="btn-close" aria-label="{{ __('common.close') }}" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            {{ $delCount === 1 ? __('devices.it_moves_to_recycle_bin') : __('devices.they_move_to_recycle_bin') }}
                        </p>
                        @if ($delEntries > 0)
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="delete-from-books" wire:model="deleteFromBooks">
                                <label class="form-check-label" for="delete-from-books">
                                    {{ trans_choice('devices.also_remove_from_address_books', $delEntries) }}
                                </label>
                            </div>
                            <div class="form-text">
                                {{ __('devices.only_books_you_can_edit') }}
                            </div>
                        @else
                            <p class="text-muted fs-13 mb-0">{{ $delCount === 1 ? __('devices.it_is_in_no_address') : __('devices.they_are_in_no_address') }}</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="cancelDelete">{{ __('common.cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ __('common.delete') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
