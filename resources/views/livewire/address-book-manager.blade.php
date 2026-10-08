@php
    use App\Livewire\AddressBookManager as ABM;
    // Every personal book is created with this default name — hide it and show the owner instead.
    $isDefaultBookName = fn ($b) => strcasecmp(trim($b->name), 'My address book') === 0;
@endphp
<div>
    <div class="row g-3">

        {{-- ============ LEFT: master list ============ --}}
        <div class="col-12 col-lg-4">

            {{-- Tabs: personal vs shared books --}}
            <ul class="nav nav-tabs nav-bordered mb-2">
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'personal' ? 'active' : '' }}"
                       wire:click="setTab('personal')">
                        <i class="ri-user-line me-1"></i>Personal
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $personalCount }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'shared' ? 'active' : '' }}"
                       wire:click="setTab('shared')">
                        <i class="ri-share-line me-1"></i>Shared
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $sharedCount }}</span>
                    </a>
                </li>
            </ul>

            {{-- Mobile: collapse master list to a select --}}
            <div class="d-lg-none mb-2">
                <div class="d-flex gap-2">
                    <select class="form-select" wire:model.live="selectedBookId" aria-label="Select address book">
                        @foreach ($books as $b)
                            <option value="{{ $b->id }}">
                                @if ($tab === 'personal')
                                    {{ $b->owner?->username ?? 'unknown' }}{{ $isDefaultBookName($b) ? '' : ' — '.$b->name }}
                                @else
                                    {{ $b->name }} ({{ $b->owner?->username ?? '?' }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <button type="button" class="btn btn-primary flex-shrink-0" wire:click="openNewBook" title="New shared address book">
                            <i class="ri-add-line"></i>
                        </button>
                    @endif
                </div>
            </div>

            {{-- Desktop: list group --}}
            <div class="card d-none d-lg-block">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="header-title">Address Books</h4>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <div class="rd-card-actions">
                            <button type="button" class="btn btn-primary" wire:click="openNewBook">
                                <i class="ri-add-line"></i>New shared
                            </button>
                        </div>
                    @endif
                </div>
                <div class="list-group list-group-flush rd-masterlist">
                    @forelse ($books as $b)
                        <a href="javascript:void(0);" wire:key="book{{ $b->id }}" wire:click="selectBook({{ $b->id }})"
                           class="list-group-item list-group-item-action {{ $b->id === $selectedBookId ? 'active' : '' }}">
                            <span class="rd-cell-title text-truncate">
                                @if ($tab === 'personal')
                                    <i class="ri-user-line me-1"></i>{{ $b->owner?->username ?? 'unknown' }}
                                @else
                                    <i class="ri-contacts-book-2-line me-1"></i>{{ $b->name }}
                                @endif
                            </span>
                            {{-- No text-muted: the active row's colour comes from the
                                 list group's own --bs-list-group-active-color, and a
                                 hard-coded grey here would flatten the selection. --}}
                            <small class="rd-cell-sub">
                                @if ($tab === 'personal')
                                    @unless ($isDefaultBookName($b)) {{ $b->name }} · @endunless
                                @else
                                    {{ $b->owner?->username ?? 'unknown' }} ·
                                @endif
                                {{ $b->entries_count }} {{ Str::plural('entry', $b->entries_count) }} ·
                                {{ $b->tags_count }} {{ Str::plural('tag', $b->tags_count) }}
                            </small>
                        </a>
                    @empty
                        <div class="list-group-item">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-contacts-book-2-line"></i></div>
                                <p class="rd-empty-title">No {{ $tab }} address books yet.</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ============ RIGHT: detail ============ --}}
        <div class="col-12 col-lg-8">
            @if ($book)
                <div class="card">
                        {{-- Header --}}
                        <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h4 class="header-title d-flex align-items-center gap-2 flex-wrap">
                                    {{ $book->name }}
                                    @if ($book->is_personal && $book->isOrphaned())
                                        {{-- Its owner was deleted, so nobody can read it. Named
                                             rather than left as "Personal / unknown", which gave
                                             no clue why it was there or what to do about it. --}}
                                        <span class="badge bg-warning-subtle text-warning">Orphaned</span>
                                    @elseif ($book->is_personal)
                                        <span class="badge bg-info-subtle text-info">Personal</span>
                                    @else
                                        <span class="badge bg-primary-subtle text-primary">Shared</span>
                                    @endif
                                </h4>
                                <p class="rd-card-sub mb-0">
                                    <i class="ri-user-line me-1"></i>{{ $book->owner?->username ?? 'unknown' }}
                                    @if ($book->note)
                                        <span class="ms-2">{{ $book->note }}</span>
                                    @endif
                                </p>
                            </div>
                            @if (! $book->is_personal && $canManage)
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-light" wire:click="openRenameBook">
                                        <i class="ri-pencil-line me-1"></i>Rename
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="deleteBook"
                                            wire:confirm="Delete address book “{{ $book->name }}”? This also permanently deletes all of its entries, tags, and sharing rules.">
                                        <i class="ri-delete-bin-line me-1"></i>Delete
                                    </button>
                                </div>
                            @elseif ($book->is_personal && $book->isOrphaned() && $canManage)
                                {{-- The whole reason this book is reachable at all. Without a
                                     control here the backend permission was unusable: the fix
                                     for #14 shipped in 1.0.2 with no way for anyone to press it.
                                     No Rename — the only useful action on an orphan is removal. --}}
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="deleteBook"
                                            wire:confirm="Delete this orphaned address book? Its owner no longer exists, so nobody can read it. This permanently deletes its entries and tags.">
                                        <i class="ri-delete-bin-line me-1"></i>Delete
                                    </button>
                                </div>
                            @elseif (! $book->is_personal)
                                <span class="badge bg-secondary-subtle text-secondary align-self-start">
                                    {{ $permission >= 2 ? 'Read/Write' : 'Read only' }}
                                </span>
                            @endif
                        </div>

                        {{-- Tags row --}}
                        <div class="rd-toolbar">
                            <span class="text-muted fw-semibold me-1"><i class="ri-price-tag-3-line me-1"></i>Tags:</span>
                            @forelse ($tags as $tag)
                                @php $hex = ABM::colorToHex($tag->color); @endphp
                                <span class="badge d-inline-flex align-items-center gap-1 {{ ABM::chipTextClass($hex) }}"
                                      style="background-color: {{ $hex }};" wire:key="tag{{ $tag->id }}">
                                    {{ $tag->name }}
                                    @if ($canManage)
                                        <a href="javascript:void(0);" class="{{ ABM::chipTextClass($hex) }} text-decoration-none lh-1"
                                           wire:click="deleteTag({{ $tag->id }})"
                                           wire:confirm="Delete tag “{{ $tag->name }}”? It will be removed from all entries."
                                           title="Delete tag"><i class="ri-close-line align-middle"></i></a>
                                    @endif
                                </span>
                            @empty
                                <span class="text-muted fst-italic">none</span>
                            @endforelse
                            @if ($canManage)
                                <button type="button" class="btn btn-sm btn-light" wire:click="openAddTag">
                                    <i class="ri-add-line"></i> Add tag
                                </button>
                            @endif
                        </div>

                        {{-- Entries toolbar --}}
                        <div class="rd-toolbar">
                            <h4 class="header-title">Entries</h4>
                            @if ($canWriteEntries)
                                <div class="rd-toolbar-actions">
                                    <button type="button" class="btn btn-primary" wire:click="openAddEntry">
                                        <i class="ri-add-line"></i>Add entry
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Filter: matches machine name as well as id, since an entry
                             with no alias is otherwise just a number (#5). --}}
                        <div class="mb-3">
                            <div class="input-group" style="max-width: 320px;">
                                <span class="input-group-text"><i class="ri-search-line"></i></span>
                                <input type="search" class="form-control"
                                       placeholder="Search device, ID or alias…"
                                       wire:model.live.debounce.300ms="entrySearch">
                            </div>
                        </div>

                        {{-- Desktop entries table (md and up) --}}
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover table-centered mb-0">
                                <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Alias</th>
                                    <th>User</th>
                                    <th>Tags</th>
                                    <th>Created</th>
                                    <th class="text-end">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($entries as $entry)
                                    <tr wire:key="e{{ $entry->id }}">
                                        <td>
                                            <div class="rd-cell">
                                                <x-platform-icon :platform="$entry->platform ?: 'unknown'" size="fs-20"/>
                                                <div class="min-width-0">
                                                    <a href="rustdesk://{{ $entry->rustdesk_id }}"
                                                       class="rd-cell-title text-truncate"
                                                       title="Connect with RustDesk">{{ $entry->hostname ?: $entry->rustdesk_id }}</a>
                                                    <span class="rd-cell-sub">
                                                        {{ $entry->rustdesk_id }}@if ($entry->platform) · {{ ucfirst($entry->platform) }}@endif
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $entry->alias ?: '—' }}</td>
                                        <td>{{ $entry->username ?: '—' }}</td>
                                        <td>
                                            @forelse (collect($entry->tag_ids ?? [])->map(fn ($id) => $tagMap->get((int) $id))->filter() as $t)
                                                @php $hex = ABM::colorToHex($t->color); @endphp
                                                <span class="badge {{ ABM::chipTextClass($hex) }}" style="background-color: {{ $hex }};">{{ $t->name }}</span>
                                            @empty
                                                <span class="text-muted">—</span>
                                            @endforelse
                                        </td>
                                        <td><span title="{{ $entry->created_at }}">{{ $entry->created_at?->diffForHumans() ?? '—' }}</span></td>
                                        <td class="text-end rd-rowact">
                                            @if ($canWriteEntries)
                                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="openEditEntry({{ $entry->id }})">Edit</a>
                                                <a href="javascript:void(0);" class="text-danger"
                                                   wire:click="deleteEntry({{ $entry->id }})"
                                                   wire:confirm="Remove {{ $entry->rustdesk_id }} from this address book?">Remove</a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="rd-empty-cell">
                                            <div class="rd-empty">
                                                <div class="rd-empty-icon"><i class="ri-contacts-book-2-line"></i></div>
                                                <p class="rd-empty-title">No entries in this address book.</p>
                                                <p class="rd-empty-text">Entries are the machines a user keeps to hand — they sync straight into the RustDesk client.</p>
                                                @if ($canWriteEntries)
                                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddEntry">Add entry</button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile entries card list (below md) --}}
                        <div class="d-md-none rd-cardlist">
                            @forelse ($entries as $entry)
                                <div class="rd-mini" wire:key="me{{ $entry->id }}">
                                        <div class="rd-mini-head">
                                            <div class="d-flex align-items-center gap-2 min-width-0">
                                                <x-platform-icon :platform="$entry->platform ?: 'unknown'" size="fs-22"/>
                                                <div class="min-width-0">
                                                    <a href="rustdesk://{{ $entry->rustdesk_id }}" class="rd-mini-title text-truncate"
                                                       title="Connect with RustDesk">{{ $entry->hostname ?: $entry->rustdesk_id }}</a>
                                                    <span class="rd-mini-sub text-truncate">
                                                        {{ $entry->rustdesk_id }}@if ($entry->alias) · {{ $entry->alias }}@endif
                                                    </span>
                                                </div>
                                            </div>
                                            @if ($canWriteEntries)
                                                <div class="rd-mini-acts">
                                                    <a href="javascript:void(0);" class="rd-iconbtn" title="Edit" wire:click="openEditEntry({{ $entry->id }})"><i class="ri-pencil-line"></i></a>
                                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="Remove"
                                                       wire:click="deleteEntry({{ $entry->id }})"
                                                       wire:confirm="Remove {{ $entry->rustdesk_id }} from this address book?"><i class="ri-delete-bin-line"></i></a>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                                            @foreach (collect($entry->tag_ids ?? [])->map(fn ($id) => $tagMap->get((int) $id))->filter() as $t)
                                                @php $hex = ABM::colorToHex($t->color); @endphp
                                                <span class="badge {{ ABM::chipTextClass($hex) }}" style="background-color: {{ $hex }};">{{ $t->name }}</span>
                                            @endforeach
                                            <small class="text-muted ms-auto">{{ $entry->created_at?->diffForHumans(short: true) ?? '' }}</small>
                                        </div>
                                </div>
                            @empty
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-contacts-book-2-line"></i></div>
                                    <p class="rd-empty-title">No entries in this address book.</p>
                                    @if ($canWriteEntries)
                                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddEntry">Add entry</button>
                                    @endif
                                </div>
                            @endforelse
                        </div>

                        @if ($entries && $entries->hasPages())
                            <div class="rd-tablefoot">
                                <span>Showing {{ $entries->firstItem() ?? 0 }}–{{ $entries->lastItem() ?? 0 }} of {{ $entries->total() }}</span>
                                {{ $entries->links() }}
                            </div>
                        @endif
                </div>

                {{-- Sharing rules (shared books, FULL control only) --}}
                @if (! $book->is_personal && $canManage)
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <h4 class="header-title">Sharing rules</h4>
                            <div class="rd-card-actions">
                                <button type="button" class="btn btn-primary" wire:click="openAddRule">
                                    <i class="ri-add-line"></i>Add rule
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            @forelse ($bookRules as $rule)
                                <div class="d-flex align-items-center gap-2 rd-inset mb-2 flex-wrap" wire:key="rule{{ $rule->id }}">
                                    <span class="flex-grow-1 text-truncate">
                                        @if ($rule->subject_type === 'everyone')
                                            <i class="ri-global-line me-1 text-muted"></i>Everyone
                                        @elseif ($rule->subject_type === 'user')
                                            <i class="ri-user-line me-1 text-muted"></i>{{ $users->firstWhere('id', $rule->subject_id)?->username ?? 'user #'.$rule->subject_id }}
                                        @else
                                            <i class="ri-team-line me-1 text-muted"></i>{{ $userGroups->firstWhere('id', $rule->subject_id)?->name ?? 'group #'.$rule->subject_id }}
                                        @endif
                                    </span>
                                    <select class="form-select form-select-sm w-auto"
                                            wire:change="updateRulePermission({{ $rule->id }}, parseInt($event.target.value))">
                                        @foreach ($permissionLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($rule->permission === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="rd-iconbtn text-danger"
                                            wire:click="deleteRule({{ $rule->id }})"
                                            wire:confirm="Delete this sharing rule?" title="Delete rule">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            @empty
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-share-line"></i></div>
                                    <p class="rd-empty-title">Not shared with anyone yet.</p>
                                    <p class="rd-empty-text">Add a rule to share this address book with a person, a user group, or everyone.</p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddRule">Add rule</button>
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endif
            @else
                <div class="card">
                    <div class="card-body">
                        <div class="rd-empty">
                            <div class="rd-empty-icon"><i class="ri-contacts-book-2-line"></i></div>
                            <p class="rd-empty-title">Select an address book to view its entries.</p>
                            <p class="rd-empty-text">Personal books belong to one user; shared books are handed out by rule.</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ============ Modals (Livewire-controlled) ============ --}}

    @if ($modal === 'newBook' || $modal === 'renameBook')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="{{ $modal === 'newBook' ? 'createBook' : 'renameBook' }}">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $modal === 'newBook' ? 'New shared address book' : 'Rename address book' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-book-name">Name</label>
                                <input type="text" id="ab-book-name" class="form-control @error('bookName') is-invalid @enderror"
                                       wire:model="bookName" autofocus>
                                @error('bookName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="ab-book-note">Note <span class="text-muted">(optional)</span></label>
                                <textarea id="ab-book-note" class="form-control @error('bookNote') is-invalid @enderror" rows="2"
                                          wire:model="bookNote"></textarea>
                                @error('bookNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">{{ $modal === 'newBook' ? 'Create' : 'Save' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'addTag')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="addTag">
                        <div class="modal-header">
                            <h5 class="modal-title">Add tag</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-8">
                                    <label class="form-label" for="ab-tag-name">Name</label>
                                    <input type="text" id="ab-tag-name" class="form-control @error('tagName') is-invalid @enderror"
                                           wire:model="tagName" autofocus>
                                    @error('tagName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="ab-tag-color">Color</label>
                                    <input type="color" id="ab-tag-color" class="form-control form-control-color w-100 @error('tagColor') is-invalid @enderror"
                                           wire:model="tagColor">
                                    @error('tagColor') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'entry')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="saveEntry">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $entryId ? 'Edit entry' : 'Add entry' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-id">RustDesk ID</label>
                                <input type="text" id="ab-entry-id" class="form-control @error('entryRustdeskId') is-invalid @enderror"
                                       wire:model="entryRustdeskId" @disabled($entryId !== null)>
                                @error('entryRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-alias">Alias <span class="text-muted">(optional)</span></label>
                                <input type="text" id="ab-entry-alias" class="form-control @error('entryAlias') is-invalid @enderror"
                                       wire:model="entryAlias">
                                @error('entryAlias') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label d-block">Tags</label>
                                @forelse ($tags as $tag)
                                    <div class="form-check form-check-inline" wire:key="etag{{ $tag->id }}">
                                        <input class="form-check-input" type="checkbox" id="ab-etag-{{ $tag->id }}"
                                               value="{{ $tag->id }}" wire:model="entryTagIds">
                                        <label class="form-check-label" for="ab-etag-{{ $tag->id }}">
                                            <span class="badge {{ ABM::chipTextClass(ABM::colorToHex($tag->color)) }}"
                                                  style="background-color: {{ ABM::colorToHex($tag->color) }};">{{ $tag->name }}</span>
                                        </label>
                                    </div>
                                @empty
                                    <span class="text-muted fst-italic">No tags in this address book yet.</span>
                                @endforelse
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">{{ $entryId ? 'Save' : 'Add' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'addRule')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="addRule">
                        <div class="modal-header">
                            <h5 class="modal-title">Add sharing rule</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-rule-type">Share with</label>
                                <select id="ab-rule-type" class="form-select @error('ruleSubjectType') is-invalid @enderror"
                                        wire:model.live="ruleSubjectType">
                                    <option value="everyone">Everyone</option>
                                    <option value="user">A specific user</option>
                                    <option value="group">A user group</option>
                                </select>
                                @error('ruleSubjectType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @if ($ruleSubjectType === 'user')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-user">User</label>
                                    <select id="ab-rule-user" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">Choose a user…</option>
                                        @foreach ($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->username }}{{ $u->name ? ' — '.$u->name : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @elseif ($ruleSubjectType === 'group')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-group">Group</label>
                                    <select id="ab-rule-group" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">Choose a group…</option>
                                        @foreach ($userGroups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif
                            <div class="mb-0">
                                <label class="form-label" for="ab-rule-perm">Permission</label>
                                <select id="ab-rule-perm" class="form-select @error('rulePermission') is-invalid @enderror"
                                        wire:model="rulePermission">
                                    @foreach ($permissionLabels as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('rulePermission') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add rule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal)
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
