<div wire:poll.15s>
    {{-- Header: who this is and how to reach it. --}}
    <div class="card">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-platform-icon :platform="$device->platform()" size="fs-36"/>
            <div class="min-width-0 me-auto">
                <h4 class="mb-0 text-truncate">{{ $device->alias ?: $device->hostname ?: $device->rustdesk_id }}</h4>
                <span class="text-muted">
                    {{ $device->rustdesk_id }}
                    @if ($device->alias && $device->hostname) · {{ $device->hostname }} @endif
                </span>
            </div>
            {{-- Status sits in the button row so flex stretches it to the
                 buttons' height. --}}
            <div class="d-flex flex-wrap gap-2">
                @if ($device->trashed())
                    <span class="badge bg-warning-subtle text-warning rd-status-block">{{ __('devices.in_recycle_bin') }}</span>
                @elseif ($device->isOnline())
                    <span class="badge bg-success-subtle text-success rd-status-block"><i class="rd-dot"></i>{{ __('common.online') }}</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary rd-status-block"><i class="rd-dot"></i>{{ __('devices.offline') }}</span>
                @endif
                @if ($device->isIncomingOnly())
                    <span class="badge bg-info-subtle text-info rd-status-block" title="{{ __('devices.can_be_controlled_cannot_start') }}">{{ __('devices.incoming_only') }}</span>
                @endif
                @unless ($device->trashed())
                    <a href="{{ \App\Support\ClientLink::to($device->rustdesk_id) }}" class="btn btn-sm btn-outline-light"
                       title="{{ __('common.connect_with_rustdesk') }}"><i class="ri-links-line me-1"></i>{{ __('devices.rustdesk') }}</a>
                    @if (config('cortendesk.native_webclient'))
                        <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                           target="cortendesk-webclient" rel="noopener" class="btn btn-sm btn-primary">
                            <i class="ri-remote-control-line me-1"></i>{{ __('devices.connect') }}</a>
                    @endif
                    @if ($canEdit)
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="editDevice">
                            <i class="ri-pencil-line me-1"></i>{{ __('common.edit') }}</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                            <i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}</button>
                    @endif
                @endunless
            </div>
        </div>
    </div>

    {{-- Possible duplicates (issue #91): the same machine under another
         RustDesk ID. Each twin gets its facts and the fixes. --}}
    @if ($twins !== [])
        @php $first = $twins[0]['device']; @endphp
        <div class="alert alert-warning" role="status">
            <div class="d-flex gap-2">
                <i class="ri-file-copy-2-line fs-18 lh-1 mt-1"></i>
                <div class="min-width-0 flex-grow-1">
                    <p class="fw-semibold mb-1">
                        {{ count($twins) === 1 ? __('devices.possible_duplicate_of_id', ['id' => $first->rustdesk_id]) : __('devices.possible_duplicate_of_count_devices', ['count' => count($twins)]) }}
                    </p>
                    <p class="fs-13 mb-2">
                        {{ __('devices.rustdesk_gives_machine_new_id') }}
                        {{ $device->isOnline() ? __('devices.this_device_was_online_just') : __('devices.this_device_was_last_seen', ['when' => $device->last_online_at?->diffForHumans() ?? __('common.never')]) }}
                    </p>
                    @foreach ($twins as $twin)
                        @php $other = $twin['device']; @endphp
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 border-top" wire:key="twin{{ $other->id }}">
                            <div class="min-width-0">
                                <a href="{{ route('devices.show', $other->id) }}" class="fw-semibold">{{ $other->rustdesk_id }}</a>
                                @if ($other->alias || $other->hostname)
                                    <span>· {{ $other->alias ?: $other->hostname }}</span>
                                @endif
                                <span class="d-block fs-13">
                                    {{ ucfirst(\App\Support\Label::get('dup_reason', $twin['reason'], $twin['label'])) }} ·
                                    {{ $other->isOnline() ? __('devices.online_now') : __('devices.last_seen_when', ['when' => $other->last_online_at?->diffForHumans() ?? __('common.never')]) }} ·
                                    {{ __('devices.first_seen_date', ['date' => $other->created_at?->format('Y-m-d') ?? __('common.unknown')]) }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('devices.show', $other->id) }}" class="btn btn-sm btn-outline-light">{{ __('devices.open') }}</a>
                                @if ($canEdit)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDeleteTwin({{ $other->id }})">
                                        <i class="ri-delete-bin-line me-1"></i>{{ __('devices.delete_id', ['id' => $other->rustdesk_id]) }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismissDuplicate({{ $other->id }})"
                                            wire:confirm="{{ __('devices.stop_flagging_and_b_as', ['a' => $device->rustdesk_id, 'b' => $other->rustdesk_id]) }}">
                                        {{ __('devices.not_duplicate') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($canEdit)
                        <div class="border-top pt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                                <i class="ri-delete-bin-line me-1"></i>{{ __('devices.delete_this_device_id', ['id' => $device->rustdesk_id]) }}</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if ($duplicateResult !== '')
        <div class="fs-13 text-success mb-3"><i class="ri-check-line me-1"></i>{{ $duplicateResult }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            {{-- Facts. Everything the client reports, plus console assignment. --}}
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('common.device') }}</h5></div>
                <div class="card-body pt-0">
                    <dl class="rd-deflist">
                        <div class="rd-def"><dt>{{ __('common.os') }}</dt><dd class="text-end" title="{{ $device->os }}">{{ $device->osDescription() ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.cpu') }}</dt><dd class="text-end">{{ $device->cpu ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.memory') }}</dt><dd>{{ $device->memory ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('common.client_version') }}</dt><dd>{{ $device->version ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.client_user') }}</dt><dd>{{ $device->username ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.last_ip') }}</dt><dd>{{ $device->last_online_ip ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.lan_ip') }}</dt>
                            <dd class="text-end" title="{{ __('devices.learned_by_id_server_when') }}">
                                @if ($device->lan_ip)
                                    <span>{{ $device->lan_ip }}</span>
                                    <span class="d-block text-muted fs-12">{{ __('devices.last_seen_during_connection_when', ['when' => $device->lan_ip_seen_at?->diffForHumans()]) }}</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>{{ __('common.sessions') }}</dt><dd>{{ $device->isIncomingOnly() ? __('devices.incoming_only') : __('devices.incoming_and_outgoing') }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.registered_from') }}</dt><dd title="{{ __('devices.address_this_device_first_appeared') }}">{{ $device->registered_ip ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.uuid') }}</dt><dd class="rd-mono rd-select-all min-width-0">{{ $device->uuid ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.first_seen_2') }}</dt><dd>{{ $device->created_at?->diffForHumans() ?? '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.last_seen') }}</dt><dd>{{ $device->last_online_at?->diffForHumans() ?? __('common.never') }}</dd></div>
                        <div class="rd-def"><dt>{{ __('common.group') }}</dt><dd>{{ $device->group?->name ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>{{ __('devices.owner') }}</dt>
                            <dd>
                                @if ($device->user)
                                    <span class="badge bg-info-subtle text-info">{{ $device->user->username }}</span>
                                @else — @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>{{ __('devices.strategy') }}</dt><dd>{{ $device->resolvedStrategy?->name ?: '—' }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- Note: the one editable field here. Everything else is either
                 client-reported or has its own screen. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">{{ __('common.note') }}</h5>
                    @if ($canEdit && ! $editingNote)
                        <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="editNote">
                            <i class="ri-pencil-line me-1"></i>{{ __('common.edit') }}</a>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if ($editingNote)
                        <textarea class="form-control @error('note') is-invalid @enderror" rows="3"
                                  wire:model="note" maxlength="500"></textarea>
                        @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="saveNote">{{ __('common.save') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="cancelNote">{{ __('common.cancel') }}</button>
                        </div>
                    @else
                        <p class="mb-0 {{ $device->note ? '' : 'text-muted' }}">{{ $device->note ?: __('devices.no_note') }}</p>
                    @endif
                </div>
            </div>

            {{-- Membership: which books hand this device out. Add and remove
                 follow the same per-book rule as the list's bulk add. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">{{ __('common.address_books_2') }}</h5>
                    @if ($canWriteBooks && ! $device->trashed() && ! $abPickerOpen)
                        <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="openAbPicker">
                            <i class="ri-add-line me-1"></i>{{ __('common.add') }}</a>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if ($abPickerOpen)
                        <form wire:submit="addToBook" class="mb-3">
                            <label class="form-label" for="dd-ab-book">{{ __('devices.add_to_address_book_2') }}</label>
                            <div class="d-flex gap-2">
                                <select id="dd-ab-book" class="form-select @error('abBookId') is-invalid @enderror" wire:model="abBookId">
                                    <option value="0">{{ __('devices.choose') }}</option>
                                    @foreach ($addableBooks as $book)
                                        <option value="{{ $book->id }}">{{ $book->name }}{{ $book->is_personal ? ' (personal)' : '' }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary">{{ __('common.add') }}</button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="closeAbPicker">{{ __('common.cancel') }}</button>
                            </div>
                            @error('abBookId')<div class="text-danger fs-13 mt-1">{{ $message }}</div>@enderror
                            @if ($addableBooks->isEmpty())
                                <div class="form-text">{{ __('devices.it_is_already_in_every') }}</div>
                            @endif
                        </form>
                    @endif
                    @forelse ($books as $book)
                        <span class="badge bg-secondary-subtle text-secondary me-1 mb-1">
                            <i class="ri-contacts-book-2-line me-1"></i>{{ $book->is_personal ? ($book->owner?->username ? __('address_books.owners_personal', ['name' => $book->owner->username]) : __('address_books.personal')) : $book->name }}
                            @if (in_array($book->id, $writableBookIds, true))
                                <a href="javascript:void(0);" class="text-secondary ms-1" title="{{ __('devices.remove_from_this_address_book') }}"
                                   aria-label="{{ __('devices.remove_from_book', ['book' => $book->name]) }}"
                                   wire:click="removeFromBook({{ $book->id }})"
                                   wire:confirm="{{ __('devices.remove_this_device_from_book', ['book' => $book->name]) }}"><i class="ri-close-line"></i></a>
                            @endif
                        </span>
                    @empty
                        <span class="text-muted">{{ __('devices.in_no_address_book_you') }}</span>
                    @endforelse
                    @if ($abResult !== '')
                        <div class="fs-13 text-success mt-2"><i class="ri-check-line me-1"></i>{{ $abResult }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if ($canAudit)
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ __('devices.recent_connections') }}</h5>
                        <a href="{{ route('logs.connections') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">{{ __('common.all') }}</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($connections as $c)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    {{ $c->from_name ?: $c->from_peer ?: 'unknown' }}
                                    <span class="text-muted">· {{ $c->ip }}</span>
                                    @if ($c->note)
                                        <span class="rd-cell-sub rd-conn-note-full"><i class="ri-sticky-note-line"></i> {{ $c->note }}@if ($c->noteUser) · {{ $c->noteUser->username }}@endif</span>
                                    @endif
                                </dt>
                                <dd class="text-end text-muted mb-0">
                                    {{ $c->created_at->diffForHumans() }}
                                    @if ($c->closed_at) · {{ $c->created_at->diffAsCarbonInterval($c->closed_at)->cascade()->forHumans(short: true) }} @endif
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">{{ __('common.no_connections_recorded') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ __('devices.recent_file_transfers') }}</h5>
                        <a href="{{ route('logs.file-transfers') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">{{ __('common.all') }}</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($transfers as $t)
                            <div class="rd-def">
                                <dt class="fw-normal text-truncate" style="max-width: 70%" title="{{ $t->isClipboard() ? implode(', ', $t->fileNames()) : $t->path }}">
                                    @if ($t->direction === 1)
                                        <i class="ri-arrow-down-line text-warning me-1" title="{{ __('common.receive') }}"></i>
                                    @else
                                        <i class="ri-arrow-up-line text-info me-1" title="{{ __('common.send') }}"></i>
                                    @endif
                                    @if ($t->isClipboard())
                                        <span class="badge bg-secondary-subtle text-secondary me-1">{{ __('common.clipboard') }}</span>
                                    @endif
                                    {{ $t->pathLabel() ?: '—' }}
                                </dt>
                                <dd class="text-end text-muted mb-0">{{ $t->created_at->diffForHumans() }}</dd>
                            </div>
                        @empty
                            <span class="text-muted">{{ __('devices.no_file_transfers_recorded') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ __('common.alarms') }}</h5>
                        <a href="{{ route('logs.alarms') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">{{ __('common.all') }}</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($alarms as $a)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    <span class="badge bg-{{ $a->typeSeverity() }}-subtle text-{{ $a->typeSeverity() }}">{{ $a->typeLabel() }}</span>
                                </dt>
                                <dd class="text-end text-muted mb-0">{{ $a->created_at->diffForHumans() }}</dd>
                            </div>
                        @empty
                            <span class="text-muted">{{ __('devices.no_alarms_recorded') }}</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('devices.notifications') }}</h5></div>
                    <div class="card-body pt-0">
                        @forelse ($notifications as $n)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    {{ \App\Services\AppriseNotifications::eventLabel($n->event) }}
                                    @if ($n->error)<span class="d-block text-danger fs-13 text-break">{{ $n->error }}</span>@endif
                                </dt>
                                <dd class="text-end text-muted mb-0 text-nowrap">
                                    <span title="{{ $n->created_at?->format('Y-m-d H:i:s T') }}">{{ $n->created_at?->diffForHumans() }}</span>
                                    @php $tone = ['sent' => 'success', 'failed' => 'danger'][$n->status] ?? 'secondary'; @endphp
                                    <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} ms-1">{{ $n->status }}</span>
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">{{ __('devices.no_notifications_sent_for_this') }}</span>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body text-muted">
                        {{ __('common.activity_history_needs_audit_permission') }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    @include('livewire.partials.device-edit-modal')

    @include('livewire.partials.device-delete-modal')
</div>
