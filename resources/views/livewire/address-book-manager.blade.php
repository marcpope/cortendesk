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
                        <i class="ri-user-line me-1"></i>{{ __('address_books.personal') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $personalCount }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'shared' ? 'active' : '' }}"
                       wire:click="setTab('shared')">
                        <i class="ri-share-line me-1"></i>{{ __('address_books.shared') }}
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $sharedCount }}</span>
                    </a>
                </li>
            </ul>

            {{-- Mobile: collapse master list to a select --}}
            <div class="d-lg-none mb-2">
                <div class="d-flex gap-2">
                    <select class="form-select" wire:model.live="selectedBookId" aria-label="{{ __('address_books.select_address_book') }}">
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
                        <button type="button" class="btn btn-primary flex-shrink-0" wire:click="openNewBook" title="{{ __('address_books.new_shared_address_book') }}">
                            <i class="ri-add-line"></i>
                        </button>
                    @endif
                </div>
            </div>

            {{-- Desktop: list group --}}
            <div class="card d-none d-lg-block">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="header-title">{{ __('common.address_books') }}</h4>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <div class="rd-card-actions">
                            <button type="button" class="btn btn-primary" wire:click="openNewBook">
                                <i class="ri-add-line"></i>{{ __('address_books.new_shared') }}
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
                                {{ trans_choice('common.count_entries', $b->entries_count) }} ·
                                {{ trans_choice('common.count_tags', $b->tags_count) }}
                            </small>
                        </a>
                    @empty
                        <div class="list-group-item">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-contacts-book-2-line"></i></div>
                                <p class="rd-empty-title">{{ $tab === 'personal' ? __('address_books.no_personal_address_books_yet') : __('address_books.no_shared_address_books_yet') }}</p>
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
                                        <span class="badge bg-warning-subtle text-warning">{{ __('address_books.orphaned') }}</span>
                                    @elseif ($book->is_personal)
                                        <span class="badge bg-info-subtle text-info">{{ __('address_books.personal') }}</span>
                                    @else
                                        <span class="badge bg-primary-subtle text-primary">{{ __('address_books.shared') }}</span>
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
                                        <i class="ri-pencil-line me-1"></i>{{ __('address_books.rename') }}
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="deleteBook"
                                            wire:confirm="{{ __('address_books.delete_address_book_name_this', ['name' => $book->name]) }}">
                                        <i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}
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
                                            wire:confirm="{{ __('address_books.delete_this_orphaned_address_book') }}">
                                        <i class="ri-delete-bin-line me-1"></i>{{ __('common.delete') }}
                                    </button>
                                </div>
                            @elseif (! $book->is_personal)
                                <span class="badge bg-secondary-subtle text-secondary align-self-start">
                                    {{ $permission >= 2 ? 'Read/Write' : __('address_books.read_only') }}
                                </span>
                            @endif
                        </div>

                        {{-- Tags row --}}
                        <div class="rd-toolbar">
                            <span class="text-muted fw-semibold me-1"><i class="ri-price-tag-3-line me-1"></i>{{ __('address_books.tags_2') }}</span>
                            @forelse ($tags as $tag)
                                @php $hex = ABM::colorToHex($tag->color); @endphp
                                <span class="badge d-inline-flex align-items-center gap-1 {{ ABM::chipTextClass($hex) }}"
                                      style="background-color: {{ $hex }};" wire:key="tag{{ $tag->id }}">
                                    {{ $tag->name }}
                                    @if ($canManage)
                                        <a href="javascript:void(0);" class="{{ ABM::chipTextClass($hex) }} text-decoration-none lh-1"
                                           wire:click="deleteTag({{ $tag->id }})"
                                           wire:confirm="{{ __('address_books.delete_tag_name_it_will', ['name' => $tag->name]) }}"
                                           title="{{ __('address_books.delete_tag') }}"><i class="ri-close-line align-middle"></i></a>
                                    @endif
                                </span>
                            @empty
                                <span class="text-muted fst-italic">{{ __('address_books.none') }}</span>
                            @endforelse
                            @if ($canManage)
                                <button type="button" class="btn btn-sm btn-light" wire:click="openAddTag">
                                    <i class="ri-add-line"></i> {{ __('address_books.add_tag') }}
                                </button>
                            @endif
                        </div>

                        {{-- Entries toolbar --}}
                        <div class="rd-toolbar">
                            <h4 class="header-title">{{ __('address_books.entries') }}</h4>
                            @if ($canWriteEntries)
                                <div class="rd-toolbar-actions">
                                    <button type="button" class="btn btn-primary" wire:click="openAddEntry">
                                        <i class="ri-add-line"></i>{{ __('address_books.add_entry') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Filter: matches machine name as well as id, since an entry
                             with no alias is otherwise just a number (#5). --}}
                        <div class="mb-3">
                            <div class="input-group" style="max-width: 320px;">
                                <span class="input-group-text"><i class="ri-search-line"></i></span>
                                <input type="search" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" class="form-control"
                                       placeholder="{{ \App\Support\SearchHint::placeholder(__('address_books.search_device_id_or_alias')) }}"
                                       wire:model.live.debounce.300ms="entrySearch">
                            </div>
                        </div>

                        {{-- Desktop entries table (md and up) --}}
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover table-centered mb-0">
                                <thead>
                                <tr>
                                    <th>{{ __('common.device') }}</th>
                                    <th>{{ __('common.alias') }}</th>
                                    <th>{{ __('common.user') }}</th>
                                    <th>{{ __('address_books.tags') }}</th>
                                    <th>{{ __('common.created') }}</th>
                                    <th class="text-end">{{ __('common.action') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($entries as $entry)
                                    <tr wire:key="e{{ $entry->id }}">
                                        <td>
                                            <div class="rd-cell">
                                                <x-platform-icon :platform="$entry->platform ?: 'unknown'" size="fs-20"/>
                                                <div class="min-width-0">
                                                    @if ($entry->canBeControlled())
                                                        <a href="{{ \App\Support\ClientLink::to($entry->rustdesk_id) }}"
                                                           class="rd-cell-title text-truncate"
                                                           title="{{ __('common.connect_with_rustdesk') }}">{{ $entry->hostname ?: $entry->rustdesk_id }}</a>
                                                    @else
                                                        <span class="rd-cell-title text-truncate"
                                                              title="{{ __('devices.ios_cannot_be_controlled') }}">{{ $entry->hostname ?: $entry->rustdesk_id }}</span>
                                                    @endif
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
                                                <a href="javascript:void(0);" class="rd-act me-2" wire:click="openEditEntry({{ $entry->id }})">{{ __('common.edit') }}</a>
                                                <a href="javascript:void(0);" class="text-danger"
                                                   wire:click="deleteEntry({{ $entry->id }})"
                                                   wire:confirm="{{ __('address_books.remove_id_from_this_address', ['id' => $entry->rustdesk_id]) }}">{{ __('address_books.remove') }}</a>
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
                                                <p class="rd-empty-title">{{ __('address_books.no_entries_in_this_address') }}</p>
                                                <p class="rd-empty-text">{{ __('address_books.entries_are_machines_user_keeps') }}</p>
                                                @if ($canWriteEntries)
                                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddEntry">{{ __('address_books.add_entry') }}</button>
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
                                                    @if ($entry->canBeControlled())
                                                        <a href="{{ \App\Support\ClientLink::to($entry->rustdesk_id) }}" class="rd-mini-title text-truncate"
                                                           title="{{ __('common.connect_with_rustdesk') }}">{{ $entry->hostname ?: $entry->rustdesk_id }}</a>
                                                    @else
                                                        <span class="rd-mini-title text-truncate"
                                                              title="{{ __('devices.ios_cannot_be_controlled') }}">{{ $entry->hostname ?: $entry->rustdesk_id }}</span>
                                                    @endif
                                                    <span class="rd-mini-sub text-truncate">
                                                        {{ $entry->rustdesk_id }}@if ($entry->alias) · {{ $entry->alias }}@endif
                                                    </span>
                                                </div>
                                            </div>
                                            @if ($canWriteEntries)
                                                <div class="rd-mini-acts">
                                                    <a href="javascript:void(0);" class="rd-iconbtn" title="{{ __('common.edit') }}" wire:click="openEditEntry({{ $entry->id }})"><i class="ri-pencil-line"></i></a>
                                                    <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="{{ __('address_books.remove') }}"
                                                       wire:click="deleteEntry({{ $entry->id }})"
                                                       wire:confirm="{{ __('address_books.remove_id_from_this_address', ['id' => $entry->rustdesk_id]) }}"><i class="ri-delete-bin-line"></i></a>
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
                                    <p class="rd-empty-title">{{ __('address_books.no_entries_in_this_address') }}</p>
                                    @if ($canWriteEntries)
                                        <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddEntry">{{ __('address_books.add_entry') }}</button>
                                    @endif
                                </div>
                            @endforelse
                        </div>

                        @if ($entries && $entries->hasPages())
                            <div class="rd-tablefoot">
                                <span>{{ __('common.showing_from_to_of_total', ['from' => $entries->firstItem() ?? 0, 'to' => $entries->lastItem() ?? 0, 'total' => $entries->total()]) }}</span>
                                {{ $entries->links() }}
                            </div>
                        @endif
                </div>

                {{-- Sharing rules (shared books, FULL control only) --}}
                @if (! $book->is_personal && $canManage)
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <h4 class="header-title">{{ __('address_books.sharing_rules') }}</h4>
                            <div class="rd-card-actions">
                                <button type="button" class="btn btn-primary" wire:click="openAddRule">
                                    <i class="ri-add-line"></i>{{ __('address_books.add_rule') }}
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            @forelse ($bookRules as $rule)
                                <div class="d-flex align-items-center gap-2 rd-inset mb-2 flex-wrap" wire:key="rule{{ $rule->id }}">
                                    <span class="flex-grow-1 text-truncate">
                                        @if ($rule->subject_type === 'everyone')
                                            <i class="ri-global-line me-1 text-muted"></i>{{ __('address_books.everyone') }}
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
                                            wire:confirm="{{ __('address_books.delete_this_sharing_rule') }}" title="{{ __('address_books.delete_rule') }}">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            @empty
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-share-line"></i></div>
                                    <p class="rd-empty-title">{{ __('address_books.not_shared_with_anyone_yet') }}</p>
                                    <p class="rd-empty-text">{{ __('address_books.add_rule_to_share_this') }}</p>
                                    <button type="button" class="btn btn-sm btn-outline-light" wire:click="openAddRule">{{ __('address_books.add_rule') }}</button>
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
                            <p class="rd-empty-title">{{ __('address_books.select_address_book_to_view') }}</p>
                            <p class="rd-empty-text">{{ __('address_books.personal_books_belong_to_one') }}</p>
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
                            <h5 class="modal-title">{{ $modal === 'newBook' ? __('address_books.new_shared_address_book') : __('address_books.rename_address_book') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-book-name">{{ __('common.name') }}</label>
                                <input type="text" id="ab-book-name" class="form-control @error('bookName') is-invalid @enderror"
                                       wire:model="bookName" autofocus>
                                @error('bookName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="ab-book-note">{{ __('common.note') }} <span class="text-muted">{{ __('address_books.optional') }}</span></label>
                                <textarea id="ab-book-note" class="form-control @error('bookNote') is-invalid @enderror" rows="2"
                                          wire:model="bookNote"></textarea>
                                @error('bookNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ $modal === 'newBook' ? __('address_books.create') : __('common.save') }}</button>
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
                            <h5 class="modal-title">{{ __('address_books.add_tag') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-8">
                                    <label class="form-label" for="ab-tag-name">{{ __('common.name') }}</label>
                                    <input type="text" id="ab-tag-name" class="form-control @error('tagName') is-invalid @enderror"
                                           wire:model="tagName" autofocus>
                                    @error('tagName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="ab-tag-color">{{ __('address_books.color') }}</label>
                                    <input type="color" id="ab-tag-color" class="form-control form-control-color w-100 @error('tagColor') is-invalid @enderror"
                                           wire:model="tagColor">
                                    @error('tagColor') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('common.add') }}</button>
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
                            <h5 class="modal-title">{{ $entryId ? __('address_books.edit_entry') : __('address_books.add_entry') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-id">{{ __('common.rustdesk_id') }}</label>
                                <input type="text" id="ab-entry-id" class="form-control @error('entryRustdeskId') is-invalid @enderror"
                                       wire:model="entryRustdeskId" @disabled($entryId !== null)>
                                @error('entryRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-alias">{{ __('common.alias') }} <span class="text-muted">{{ __('address_books.optional') }}</span></label>
                                <input type="text" id="ab-entry-alias" class="form-control @error('entryAlias') is-invalid @enderror"
                                       wire:model="entryAlias">
                                @error('entryAlias') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label d-block">{{ __('address_books.tags') }}</label>
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
                                    <span class="text-muted fst-italic">{{ __('address_books.no_tags_in_this_address') }}</span>
                                @endforelse
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ $entryId ? __('common.save') : __('common.add') }}</button>
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
                            <h5 class="modal-title">{{ __('address_books.add_sharing_rule') }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="{{ __('common.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-rule-type">{{ __('address_books.share_with') }}</label>
                                <select id="ab-rule-type" class="form-select @error('ruleSubjectType') is-invalid @enderror"
                                        wire:model.live="ruleSubjectType">
                                    <option value="everyone">{{ __('address_books.everyone') }}</option>
                                    <option value="user">{{ __('address_books.specific_user') }}</option>
                                    <option value="group">{{ __('address_books.user_group') }}</option>
                                </select>
                                @error('ruleSubjectType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @if ($ruleSubjectType === 'user')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-user">{{ __('common.user') }}</label>
                                    <select id="ab-rule-user" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">{{ __('address_books.choose_user') }}</option>
                                        @foreach ($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->username }}{{ $u->name ? ' — '.$u->name : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @elseif ($ruleSubjectType === 'group')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-group">{{ __('common.group') }}</label>
                                    <select id="ab-rule-group" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">{{ __('address_books.choose_group') }}</option>
                                        @foreach ($userGroups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif
                            <div class="mb-0">
                                <label class="form-label" for="ab-rule-perm">{{ __('address_books.permission') }}</label>
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
                            <button type="button" class="btn btn-light" wire:click="closeModal">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary">{{ __('address_books.add_rule') }}</button>
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
