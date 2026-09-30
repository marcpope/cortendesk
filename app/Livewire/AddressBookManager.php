<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AddressBookManager extends Component
{
    use AuthorizesConsole, WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: 0)]
    public int $selectedBookId = 0;

    /** Which books-list tab is active: personal | shared */
    public string $tab = 'shared';

    /** Which modal is open: newBook | renameBook | addTag | entry | addRule */
    public ?string $modal = null;

    // Book form (create / rename)
    public string $bookName = '';

    public string $bookNote = '';

    // Tag form
    public string $tagName = '';

    public string $tagColor = '#2563EB';

    // Entry form (create when $entryId is null, edit otherwise)
    public ?int $entryId = null;

    public string $entryRustdeskId = '';

    public string $entryAlias = '';

    /** Filters the entry list. Matches the machine name, not just the ID (#5). */
    public string $entrySearch = '';

    /** @var array<int, int|string> tag ids checked in the entry modal */
    public array $entryTagIds = [];

    // Sharing rule form
    public string $ruleSubjectType = 'everyone';

    public ?int $ruleSubjectId = null;

    public int $rulePermission = AddressBookRule::PERM_READ;

    public function mount(): void
    {
        $this->authorizeConsole('address_book', 'r');

        if ($user = auth()->user()) {
            AddressBook::personalFor($user);
            // Admins live in the shared list; regular users mostly care about their own book.
            $this->tab = $user->is_admin ? 'shared' : 'personal';
        }

        // A deep-linked ?selectedBookId wins and pulls the tab along with it.
        if ($this->selectedBookId !== 0) {
            if ($book = $this->book()) {
                $this->tab = $book->is_personal ? 'personal' : 'shared';

                return;
            }
            $this->selectedBookId = 0;
        }

        $this->selectedBookId = $this->defaultBookId();
        if ($this->selectedBookId === 0) {
            // Active tab is empty (e.g. no shared books yet) — fall back to the other one.
            $this->tab = $this->tab === 'shared' ? 'personal' : 'shared';
            $this->selectedBookId = $this->defaultBookId();
        }
    }

    /** First visible book on the active tab, or 0 when the tab is empty. */
    protected function defaultBookId(): int
    {
        return (int) (self::orderedBooks()
            ->where('is_personal', $this->tab === 'personal')
            ->value('id') ?? 0);
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['personal', 'shared'], true) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;
        $this->selectedBookId = $this->defaultBookId();
        $this->resetPage();
    }

    /* ---------------------------------------------------------------------
     | Color helpers — RustDesk stores tag colors as u32 ARGB (0xAARRGGBB).
     * ------------------------------------------------------------------- */

    public static function colorToHex(int $color): string
    {
        if ($color === 0) {
            return '#6C757D'; // default gray when the client never set a color
        }

        return sprintf('#%06X', $color & 0xFFFFFF);
    }

    public static function hexToColor(string $hex): int
    {
        $rgb = hexdec(ltrim($hex, '#')) & 0xFFFFFF;

        return 0xFF000000 | $rgb; // opaque alpha, as the RustDesk client does
    }

    /** Pick a readable text color class for a chip with the given background. */
    public static function chipTextClass(string $hex): string
    {
        $rgb = hexdec(ltrim($hex, '#'));
        $luma = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);

        return $luma > 160 ? 'text-dark' : 'text-white';
    }

    /* ---------------------------------------------------------------------
     | Selection & modals
     * ------------------------------------------------------------------- */

    public function selectBook(int $id): void
    {
        $this->selectedBookId = $id;
        if ($book = $this->book()) {
            $this->tab = $book->is_personal ? 'personal' : 'shared';
        }
        $this->resetPage();
    }

    public function updatedSelectedBookId(): void
    {
        $this->resetPage();
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->resetValidation();
    }

    public function openNewBook(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        $this->reset('bookName', 'bookNote');
        $this->resetValidation();
        $this->modal = 'newBook';
    }

    public function openRenameBook(): void
    {
        $book = $this->book();
        if (! $book || $book->is_personal) {
            return;
        }
        $this->bookName = $book->name;
        $this->bookNote = (string) $book->note;
        $this->resetValidation();
        $this->modal = 'renameBook';
    }

    public function openAddTag(): void
    {
        $this->reset('tagName');
        $this->tagColor = '#2563EB';
        $this->resetValidation();
        $this->modal = 'addTag';
    }

    public function openAddEntry(): void
    {
        $this->reset('entryId', 'entryRustdeskId', 'entryAlias', 'entryTagIds');
        $this->resetValidation();
        $this->modal = 'entry';
    }

    public function openEditEntry(int $id): void
    {
        $entry = AddressBookEntry::where('address_book_id', $this->selectedBookId)->findOrFail($id);
        $this->entryId = $entry->id;
        $this->entryRustdeskId = $entry->rustdesk_id;
        $this->entryAlias = (string) $entry->alias;
        $this->entryTagIds = array_map('strval', $entry->tag_ids ?? []);
        $this->resetValidation();
        $this->modal = 'entry';
    }

    public function openAddRule(): void
    {
        $this->ruleSubjectType = 'everyone';
        $this->ruleSubjectId = null;
        $this->rulePermission = AddressBookRule::PERM_READ;
        $this->resetValidation();
        $this->modal = 'addRule';
    }

    public function updatedRuleSubjectType(): void
    {
        $this->ruleSubjectId = null;
    }

    /* ---------------------------------------------------------------------
     | Address books
     * ------------------------------------------------------------------- */

    public function createBook(): void
    {
        $this->authorizeConsole('address_book', 'rw');

        $this->validate([
            'bookName' => 'required|string|max:255',
            'bookNote' => 'nullable|string|max:500',
        ], [], ['bookName' => 'name', 'bookNote' => 'note']);

        $book = AddressBook::create([
            'name' => trim($this->bookName),
            'note' => $this->bookNote !== '' ? $this->bookNote : null,
            'owner_user_id' => auth()->id() ?? 0,
            'is_personal' => false,
        ]);

        ConsoleAudit::record('address-book.create', 'Created address book '.$book->name, 'address-book', $book->name);

        $this->closeModal();
        $this->selectBook($book->id);
    }

    public function renameBook(): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_FULL);
        if (! $book || $book->is_personal) {
            return;
        }

        $this->validate([
            'bookName' => 'required|string|max:255',
            'bookNote' => 'nullable|string|max:500',
        ], [], ['bookName' => 'name', 'bookNote' => 'note']);

        $oldName = $book->name;
        $book->update([
            'name' => trim($this->bookName),
            'note' => $this->bookNote !== '' ? $this->bookNote : null,
        ]);

        ConsoleAudit::record('address-book.update', $oldName !== $book->name ? 'Renamed address book '.$oldName.' to '.$book->name : 'Updated address book '.$book->name, 'address-book', $book->name);

        $this->closeModal();
    }

    public function deleteBook(): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_FULL);
        if (! $book) {
            return;
        }
        // A personal book belongs to its owner and is not the admin's to remove
        // — unless that owner is gone, in which case it is unreachable data that
        // nothing else can ever clean up.
        if ($book->is_personal && ! $book->isOrphaned()) {
            return;
        }

        $bookName = $book->name;
        $book->entries()->delete();
        $book->tags()->delete();
        $book->rules()->delete();
        $book->delete();

        ConsoleAudit::record('address-book.delete', 'Deleted address book '.$bookName, 'address-book', $bookName);

        $this->selectedBookId = $this->defaultBookId();
        $this->resetPage();
    }

    /* ---------------------------------------------------------------------
     | Tags
     * ------------------------------------------------------------------- */

    public function addTag(): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_FULL);
        if (! $book) {
            return;
        }

        $this->validate([
            'tagName' => [
                'required', 'string', 'max:64',
                Rule::unique('tags', 'name')->where('address_book_id', $book->id),
            ],
            'tagColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], ['tagName' => 'tag name', 'tagColor' => 'color']);

        $tag = Tag::create([
            'address_book_id' => $book->id,
            'name' => trim($this->tagName),
            'color' => self::hexToColor($this->tagColor),
        ]);

        ConsoleAudit::record('address-book.tag-add', 'Added tag '.$tag->name.' to address book '.$book->name, 'address-book', $book->name);

        $this->closeModal();
    }

    public function deleteTag(int $id): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_FULL);
        if (! $book) {
            return;
        }

        $tag = Tag::where('address_book_id', $this->selectedBookId)->findOrFail($id);

        // Strip the tag id from every entry that references it.
        $tag->addressBook->entries()->get()->each(function (AddressBookEntry $entry) use ($tag) {
            $ids = array_map('intval', $entry->tag_ids ?? []);
            if (in_array($tag->id, $ids, true)) {
                $entry->update(['tag_ids' => array_values(array_diff($ids, [$tag->id]))]);
            }
        });

        $tag->delete();

        ConsoleAudit::record('address-book.tag-delete', 'Removed tag '.$tag->name.' from address book '.$book->name, 'address-book', $book->name);
    }

    /* ---------------------------------------------------------------------
     | Entries
     * ------------------------------------------------------------------- */

    public function saveEntry(): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_READ_WRITE);
        if (! $book) {
            return;
        }

        $validTagIds = $book->tags()->pluck('id')->all();
        $tagIds = array_values(array_intersect(array_map('intval', $this->entryTagIds), $validTagIds));

        if ($this->entryId !== null) {
            $entry = $book->entries()->findOrFail($this->entryId);
            $this->validate(['entryAlias' => 'nullable|string|max:255'], [], ['entryAlias' => 'alias']);
            $entry->update([
                'alias' => $this->entryAlias !== '' ? trim($this->entryAlias) : null,
                'tag_ids' => $tagIds,
            ]);

            ConsoleAudit::record('address-book.peer-update', 'Updated peer '.$entry->rustdesk_id.' in address book '.$book->name, 'address-book', $book->name);
        } else {
            $this->validate([
                'entryRustdeskId' => [
                    'required', 'string', 'max:255',
                    Rule::unique('address_book_entries', 'rustdesk_id')->where('address_book_id', $book->id),
                ],
                'entryAlias' => 'nullable|string|max:255',
            ], [], ['entryRustdeskId' => 'RustDesk ID', 'entryAlias' => 'alias']);

            $entry = $book->entries()->create([
                'rustdesk_id' => trim($this->entryRustdeskId),
                'alias' => $this->entryAlias !== '' ? trim($this->entryAlias) : null,
                'tag_ids' => $tagIds,
            ]);

            ConsoleAudit::record('address-book.peer-add', 'Added peer '.$entry->rustdesk_id.' to address book '.$book->name, 'address-book', $book->name);
        }

        $this->closeModal();
    }

    public function deleteEntry(int $id): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_READ_WRITE);
        if (! $book) {
            return;
        }

        $entry = AddressBookEntry::where('address_book_id', $book->id)->findOrFail($id);
        $entry->delete();

        ConsoleAudit::record('address-book.peer-delete', 'Removed peer '.$entry->rustdesk_id.' from address book '.$book->name, 'address-book', $book->name);
    }

    /* ---------------------------------------------------------------------
     | Sharing rules
     * ------------------------------------------------------------------- */

    public function addRule(): void
    {
        $book = $this->authorizeBook(AddressBookRule::PERM_FULL);
        if (! $book || $book->is_personal) {
            return;
        }

        $rules = [
            'ruleSubjectType' => 'required|in:everyone,user,group',
            'rulePermission' => 'required|integer|in:1,2,3',
        ];
        if ($this->ruleSubjectType === 'user') {
            $rules['ruleSubjectId'] = 'required|integer|exists:users,id';
        } elseif ($this->ruleSubjectType === 'group') {
            $rules['ruleSubjectId'] = 'required|integer|exists:user_groups,id';
        }

        $this->validate($rules, [], [
            'ruleSubjectType' => 'subject',
            'ruleSubjectId' => 'subject',
            'rulePermission' => 'permission',
        ]);

        AddressBookRule::create([
            'address_book_id' => $book->id,
            'subject_type' => $this->ruleSubjectType,
            'subject_id' => $this->ruleSubjectType === 'everyone' ? null : $this->ruleSubjectId,
            'permission' => $this->rulePermission,
        ]);

        ConsoleAudit::record('address-book.rule-add', 'Added sharing rule to address book '.$book->name, 'address-book', $book->name);

        $this->closeModal();
    }

    public function updateRulePermission(int $id, int $permission): void
    {
        if (! in_array($permission, [AddressBookRule::PERM_READ, AddressBookRule::PERM_READ_WRITE, AddressBookRule::PERM_FULL], true)) {
            return;
        }

        if (! $this->authorizeBook(AddressBookRule::PERM_FULL)) {
            return;
        }

        $rule = AddressBookRule::where('address_book_id', $this->selectedBookId)->findOrFail($id);
        $rule->update(['permission' => $permission]);

        ConsoleAudit::record('address-book.rule-update', 'Updated sharing rule on address book '.$this->book()?->name, 'address-book', $this->book()?->name);
    }

    public function deleteRule(int $id): void
    {
        if (! $this->authorizeBook(AddressBookRule::PERM_FULL)) {
            return;
        }

        AddressBookRule::where('address_book_id', $this->selectedBookId)->findOrFail($id)->delete();

        ConsoleAudit::record('address-book.rule-delete', 'Removed sharing rule from address book '.$this->book()?->name, 'address-book', $this->book()?->name);
    }

    /* ---------------------------------------------------------------------
     | Render
     * ------------------------------------------------------------------- */

    protected static function orderedBooks()
    {
        return AddressBook::query()
            ->visibleTo(auth()->user())
            ->orderByDesc('is_personal')
            ->orderBy('name');
    }

    protected function book(): ?AddressBook
    {
        // Only resolve a book the current user is allowed to see.
        return $this->selectedBookId > 0
            ? AddressBook::query()->visibleTo(auth()->user())->find($this->selectedBookId)
            : null;
    }

    /**
     * Current user's effective permission tier on the selected book (PLAN B4).
     *
     * A delegated role with only "View" on address books (PLAN D4) is clamped
     * to read here, which is the single choke point the whole screen runs
     * through: canWriteEntries(), canManage() and authorizeBook() all derive
     * from it, so the write affordances disappear AND the mutators refuse.
     * A role can only ever narrow what the per-book rules already granted.
     */
    public function permission(): int
    {
        $book = $this->book();
        $permission = $book ? $book->permissionFor(auth()->user()) : 0;

        if (! $this->consoleAllows('address_book', 'rw')) {
            $permission = min($permission, AddressBookRule::PERM_READ);
        }

        return $permission;
    }

    /** True when the user may manage entries (peers) — RW or FULL. */
    public function canWriteEntries(): bool
    {
        return $this->permission() >= AddressBookRule::PERM_READ_WRITE;
    }

    /** True when the user may manage tags, rules, and the book itself — FULL. */
    public function canManage(): bool
    {
        return $this->permission() >= AddressBookRule::PERM_FULL;
    }

    /**
     * Guard a mutating action against the required tier. Returns the selected
     * book when allowed, or null (caller aborts) when not. This is the console
     * half of the same ro / rw / full authority the client AB API enforces.
     */
    protected function authorizeBook(int $required): ?AddressBook
    {
        $book = $this->book();

        if (! $book || $this->permission() < $required) {
            return null;
        }

        return $book;
    }

    public function updatedEntrySearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $allBooks = self::orderedBooks()
            ->with('owner')
            ->withCount(['entries', 'tags'])
            ->get();

        // Personal books all share the same default name, so sort them by owner instead.
        $personalBooks = $allBooks->filter->is_personal
            ->sortBy(fn (AddressBook $b) => mb_strtolower($b->owner?->username ?? ''), SORT_STRING)
            ->values();
        $sharedBooks = $allBooks->reject->is_personal->values();

        $books = $this->tab === 'personal' ? $personalBooks : $sharedBooks;

        $book = $this->book()?->load('owner');
        $tags = $book ? $book->tags()->orderBy('name')->get() : collect();
        // Entries carry the machine name and platform the client reported, so the
        // filter matches those as well as the id — an entry with no alias is just
        // a nine-digit number otherwise (#5).
        $entries = $book
            ? $book->entries()
                ->when($this->entrySearch !== '', function ($q) {
                    $term = '%'.trim($this->entrySearch).'%';
                    $q->where(fn ($w) => $w->where('rustdesk_id', 'like', $term)
                        ->orWhere('hostname', 'like', $term)
                        ->orWhere('alias', 'like', $term)
                        ->orWhere('username', 'like', $term));
                })
                ->orderByRaw('CASE WHEN hostname IS NULL OR hostname = "" THEN 1 ELSE 0 END')
                ->orderBy('hostname')
                ->orderBy('rustdesk_id')
                ->paginate(15)
            : null;
        $rules = ($book && ! $book->is_personal) ? $book->rules()->orderBy('id')->get() : collect();

        // Effective tier of the current user on the selected book (PLAN B4) —
        // drives which action buttons render.
        $permission = $this->permission();

        return view('livewire.address-book-manager', [
            'books' => $books,
            'personalCount' => $personalBooks->count(),
            'sharedCount' => $sharedBooks->count(),
            'book' => $book,
            'permission' => $permission,
            'canWriteEntries' => $permission >= AddressBookRule::PERM_READ_WRITE,
            'canManage' => $permission >= AddressBookRule::PERM_FULL,
            'tags' => $tags,
            'tagMap' => $tags->keyBy('id'),
            'entries' => $entries,
            'bookRules' => $rules,
            'users' => User::orderBy('username')->get(['id', 'username', 'name']),
            'userGroups' => UserGroup::orderBy('name')->get(['id', 'name']),
            'permissionLabels' => [
                AddressBookRule::PERM_READ => 'Read',
                AddressBookRule::PERM_READ_WRITE => 'Read/Write',
                AddressBookRule::PERM_FULL => 'Full Control',
            ],
        ]);
    }
}
