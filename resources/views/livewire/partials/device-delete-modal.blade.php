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
                                Delete device {{ $deleteState['devices']->first()->rustdesk_id }}?
                            @else
                                Delete {{ $delCount }} devices?
                            @endif
                        </h5>
                        <button type="button" class="btn-close" aria-label="Close" wire:click="cancelDelete"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            {{ $delCount === 1 ? 'It moves' : 'They move' }} to the recycle bin and can be restored from there.
                        </p>
                        @if ($delEntries > 0)
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="delete-from-books" wire:model="deleteFromBooks">
                                <label class="form-check-label" for="delete-from-books">
                                    Also remove from address books ({{ $delEntries }} {{ Str::plural('entry', $delEntries) }})
                                </label>
                            </div>
                            <div class="form-text">
                                Only books you can edit are changed. Restoring the device later does not put the entries back.
                            </div>
                        @else
                            <p class="text-muted fs-13 mb-0">{{ $delCount === 1 ? 'It is' : 'They are' }} in no address book.</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="cancelDelete">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
