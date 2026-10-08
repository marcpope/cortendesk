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
                    <span class="badge bg-warning-subtle text-warning rd-status-block">In the recycle bin</span>
                @elseif ($device->isOnline())
                    <span class="badge bg-success-subtle text-success rd-status-block"><i class="rd-dot"></i>Online</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary rd-status-block"><i class="rd-dot"></i>Offline</span>
                @endif
                @if ($device->isIncomingOnly())
                    <span class="badge bg-info-subtle text-info rd-status-block" title="Can be controlled, cannot start sessions">Incoming only</span>
                @endif
                @unless ($device->trashed())
                    <a href="rustdesk://{{ $device->rustdesk_id }}" class="btn btn-sm btn-outline-light"
                       title="Connect with RustDesk"><i class="ri-links-line me-1"></i>RustDesk</a>
                    @if (config('cortendesk.native_webclient'))
                        <a href="{{ route('webclient') }}?id={{ $device->rustdesk_id }}"
                           target="cortendesk-webclient" rel="noopener" class="btn btn-sm btn-primary">
                            <i class="ri-remote-control-line me-1"></i>Connect</a>
                    @endif
                    @if ($canEdit)
                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="editDevice">
                            <i class="ri-pencil-line me-1"></i>Edit</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                            <i class="ri-delete-bin-line me-1"></i>Delete</button>
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
                        Possible duplicate of {{ count($twins) === 1 ? $first->rustdesk_id : count($twins).' devices' }}
                    </p>
                    <p class="fs-13 mb-2">
                        RustDesk gives a machine a new ID after a reinstall, a cloned image or a UUID mismatch.
                        Keep the one that still reports in and delete the other.
                        If both report in, they are likely two machines cloned from one image: mark them not a duplicate.
                        This device was {{ $device->isOnline() ? 'online just now' : 'last seen '.($device->last_online_at?->diffForHumans() ?? 'never') }}.
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
                                    {{ ucfirst($twin['label']) }} ·
                                    {{ $other->isOnline() ? 'online now' : 'last seen '.($other->last_online_at?->diffForHumans() ?? 'never') }} ·
                                    first seen {{ $other->created_at?->format('Y-m-d') ?? 'unknown' }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('devices.show', $other->id) }}" class="btn btn-sm btn-outline-light">Open</a>
                                @if ($canEdit)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDeleteTwin({{ $other->id }})">
                                        <i class="ri-delete-bin-line me-1"></i>Delete {{ $other->rustdesk_id }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismissDuplicate({{ $other->id }})"
                                            wire:confirm="Stop flagging {{ $device->rustdesk_id }} and {{ $other->rustdesk_id }} as the same machine?">
                                        Not a duplicate</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($canEdit)
                        <div class="border-top pt-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="confirmDelete">
                                <i class="ri-delete-bin-line me-1"></i>Delete this device ({{ $device->rustdesk_id }})</button>
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
                <div class="card-header"><h5 class="card-title mb-0">Device</h5></div>
                <div class="card-body pt-0">
                    <dl class="rd-deflist">
                        <div class="rd-def"><dt>OS</dt><dd class="text-end" title="{{ $device->os }}">{{ $device->osDescription() ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>CPU</dt><dd class="text-end">{{ $device->cpu ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Memory</dt><dd>{{ $device->memory ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Client version</dt><dd>{{ $device->version ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Client user</dt><dd>{{ $device->username ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Last IP</dt><dd>{{ $device->last_online_ip ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>LAN IP</dt>
                            <dd class="text-end" title="Learned by the ID server when a session is set up between devices on the same network. Clients do not report it.">
                                @if ($device->lan_ip)
                                    <span>{{ $device->lan_ip }}</span>
                                    <span class="d-block text-muted fs-12">last seen during a connection {{ $device->lan_ip_seen_at?->diffForHumans() }}</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>Sessions</dt><dd>{{ $device->isIncomingOnly() ? 'Incoming only' : 'Incoming and outgoing' }}</dd></div>
                        <div class="rd-def"><dt>Registered from</dt><dd title="The address this device first appeared from; blank for devices that registered before this was recorded.">{{ $device->registered_ip ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>UUID</dt><dd class="rd-mono rd-select-all min-width-0">{{ $device->uuid ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>First seen</dt><dd>{{ $device->created_at?->diffForHumans() ?? '—' }}</dd></div>
                        <div class="rd-def"><dt>Last seen</dt><dd>{{ $device->last_online_at?->diffForHumans() ?? 'never' }}</dd></div>
                        <div class="rd-def"><dt>Group</dt><dd>{{ $device->group?->name ?: '—' }}</dd></div>
                        <div class="rd-def"><dt>Owner</dt>
                            <dd>
                                @if ($device->user)
                                    <span class="badge bg-info-subtle text-info">{{ $device->user->username }}</span>
                                @else — @endif
                            </dd>
                        </div>
                        <div class="rd-def"><dt>Strategy</dt><dd>{{ $device->resolvedStrategy?->name ?: '—' }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- Note: the one editable field here. Everything else is either
                 client-reported or has its own screen. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Note</h5>
                    @if ($canEdit && ! $editingNote)
                        <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="editNote">
                            <i class="ri-pencil-line me-1"></i>Edit</a>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if ($editingNote)
                        <textarea class="form-control @error('note') is-invalid @enderror" rows="3"
                                  wire:model="note" maxlength="500"></textarea>
                        @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="saveNote">Save</button>
                            <button type="button" class="btn btn-sm btn-outline-light" wire:click="cancelNote">Cancel</button>
                        </div>
                    @else
                        <p class="mb-0 {{ $device->note ? '' : 'text-muted' }}">{{ $device->note ?: 'No note.' }}</p>
                    @endif
                </div>
            </div>

            {{-- Membership: which books hand this device out. Add and remove
                 follow the same per-book rule as the list's bulk add. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Address books</h5>
                    @if ($canWriteBooks && ! $device->trashed() && ! $abPickerOpen)
                        <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="openAbPicker">
                            <i class="ri-add-line me-1"></i>Add</a>
                    @endif
                </div>
                <div class="card-body pt-0">
                    @if ($abPickerOpen)
                        <form wire:submit="addToBook" class="mb-3">
                            <label class="form-label" for="dd-ab-book">Add to address book</label>
                            <div class="d-flex gap-2">
                                <select id="dd-ab-book" class="form-select @error('abBookId') is-invalid @enderror" wire:model="abBookId">
                                    <option value="0">Choose…</option>
                                    @foreach ($addableBooks as $book)
                                        <option value="{{ $book->id }}">{{ $book->name }}{{ $book->is_personal ? ' (personal)' : '' }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary">Add</button>
                                <button type="button" class="btn btn-sm btn-light" wire:click="closeAbPicker">Cancel</button>
                            </div>
                            @error('abBookId')<div class="text-danger fs-13 mt-1">{{ $message }}</div>@enderror
                            @if ($addableBooks->isEmpty())
                                <div class="form-text">It is already in every book you can edit.</div>
                            @endif
                        </form>
                    @endif
                    @forelse ($books as $book)
                        <span class="badge bg-secondary-subtle text-secondary me-1 mb-1">
                            <i class="ri-contacts-book-2-line me-1"></i>{{ $book->is_personal ? ($book->owner?->username ? $book->owner->username."'s personal" : 'Personal') : $book->name }}
                            @if (in_array($book->id, $writableBookIds, true))
                                <a href="javascript:void(0);" class="text-secondary ms-1" title="Remove from this address book"
                                   aria-label="Remove from {{ $book->name }}"
                                   wire:click="removeFromBook({{ $book->id }})"
                                   wire:confirm="Remove this device from {{ $book->name }}?"><i class="ri-close-line"></i></a>
                            @endif
                        </span>
                    @empty
                        <span class="text-muted">In no address book you can see.</span>
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
                        <h5 class="card-title mb-0">Recent connections</h5>
                        <a href="{{ route('logs.connections') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">All</a>
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
                            <span class="text-muted">No connections recorded.</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Recent file transfers</h5>
                        <a href="{{ route('logs.file-transfers') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">All</a>
                    </div>
                    <div class="card-body pt-0">
                        @forelse ($transfers as $t)
                            <div class="rd-def">
                                <dt class="fw-normal text-truncate" style="max-width: 70%" title="{{ $t->isClipboard() ? implode(', ', $t->fileNames()) : $t->path }}">
                                    @if ($t->direction === 1)
                                        <i class="ri-arrow-down-line text-warning me-1" title="Receive"></i>
                                    @else
                                        <i class="ri-arrow-up-line text-info me-1" title="Send"></i>
                                    @endif
                                    @if ($t->isClipboard())
                                        <span class="badge bg-secondary-subtle text-secondary me-1">Clipboard</span>
                                    @endif
                                    {{ $t->pathLabel() ?: '—' }}
                                </dt>
                                <dd class="text-end text-muted mb-0">{{ $t->created_at->diffForHumans() }}</dd>
                            </div>
                        @empty
                            <span class="text-muted">No file transfers recorded.</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Alarms</h5>
                        <a href="{{ route('logs.alarms') }}?search={{ $device->rustdesk_id }}" class="fs-13 text-muted">All</a>
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
                            <span class="text-muted">No alarms recorded.</span>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Notifications</h5></div>
                    <div class="card-body pt-0">
                        @forelse ($notifications as $n)
                            <div class="rd-def">
                                <dt class="fw-normal">
                                    {{ \App\Services\AppriseNotifications::EVENTS[$n->event] ?? $n->event }}
                                    @if ($n->error)<span class="d-block text-danger fs-13 text-break">{{ $n->error }}</span>@endif
                                </dt>
                                <dd class="text-end text-muted mb-0 text-nowrap">
                                    <span title="{{ $n->created_at?->format('Y-m-d H:i:s T') }}">{{ $n->created_at?->diffForHumans() }}</span>
                                    @php $tone = ['sent' => 'success', 'failed' => 'danger'][$n->status] ?? 'secondary'; @endphp
                                    <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} ms-1">{{ $n->status }}</span>
                                </dd>
                            </div>
                        @empty
                            <span class="text-muted">No notifications sent for this device.</span>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body text-muted">
                        Activity history needs the audit permission.
                    </div>
                </div>
            @endif
        </div>
    </div>

    @include('livewire.partials.device-edit-modal')

    @include('livewire.partials.device-delete-modal')
</div>
