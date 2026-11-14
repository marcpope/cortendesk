<?php

use App\Livewire\AddressBookManager;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** A shared book owned by the beforeEach admin, shared with $member at a tier. */
function sharedBookFor(User $owner, User $member, int $permission): AddressBook
{
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $book->rules()->create(['subject_type' => 'user', 'subject_id' => $member->id, 'permission' => $permission]);

    return $book;
}

function member(): User
{
    return User::create([
        'username' => 'm-'.uniqid(),
        'password' => 'secret-password',
        'is_admin' => false,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->user = User::create([
        'username' => 'abtester',
        'name' => 'AB Tester',
        'email' => 'abtester@example.com',
        'password' => 'secret-password',
        'is_admin' => true,
        'is_active' => true,
    ]);
});

it('renders the address books page', function () {
    $this->actingAs($this->user)
        ->get('/address-books')
        ->assertOk()
        ->assertSeeLivewire(AddressBookManager::class);

    // The personal address book is auto-created on mount.
    expect(AddressBook::where('owner_user_id', $this->user->id)->where('is_personal', true)->exists())->toBeTrue();
});

it('defaults admins to the shared tab and syncs the tab with the selected book', function () {
    $shared = AddressBook::create(['name' => 'Ops Team', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    $component = Livewire::actingAs($this->user)->test(AddressBookManager::class);
    $personal = AddressBook::where('owner_user_id', $this->user->id)->where('is_personal', true)->first();

    // Admin lands on the shared tab with the first shared book selected.
    $component->assertSet('tab', 'shared')
        ->assertSet('selectedBookId', $shared->id)
        ->assertSee('Ops Team');

    // Personal tab labels books by owner username, not the default book name.
    $component->call('setTab', 'personal')
        ->assertSet('tab', 'personal')
        ->assertSet('selectedBookId', $personal->id)
        ->assertSee('abtester');

    // Selecting a shared book flips the tab back.
    $component->call('selectBook', $shared->id)
        ->assertSet('tab', 'shared');
});

it('falls back to the personal tab when an admin has no shared books', function () {
    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->assertSet('tab', 'personal');
});

it('defaults non-admins to the personal tab scoped to their own book', function () {
    $member = User::create([
        'username' => 'abmember',
        'name' => 'AB Member',
        'email' => 'abmember@example.com',
        'password' => 'secret-password',
        'is_admin' => false,
        'is_active' => true,
    ]);

    // A shared book with no rules must stay invisible to the non-admin.
    AddressBook::create(['name' => 'Admins Only', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    Livewire::actingAs($member)
        ->test(AddressBookManager::class)
        ->assertSet('tab', 'personal')
        ->assertSet('selectedBookId', AddressBook::where('owner_user_id', $member->id)->where('is_personal', true)->value('id'))
        ->assertDontSee('Admins Only');
});

it('creates a shared address book', function () {
    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('openNewBook')
        ->set('bookName', 'Ops Team')
        ->set('bookNote', 'Shared ops machines')
        ->call('createBook')
        ->assertHasNoErrors();

    $book = AddressBook::where('name', 'Ops Team')->first();
    expect($book)->not->toBeNull()
        ->and($book->is_personal)->toBeFalse()
        ->and($book->note)->toBe('Shared ops machines')
        ->and($book->guid)->not->toBeEmpty()
        ->and($book->owner_user_id)->toBe($this->user->id);
});

it('adds an entry with tags', function () {
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $this->user->id, 'is_personal' => false]);
    $tagA = Tag::create(['address_book_id' => $book->id, 'name' => 'A', 'color' => 0xFFE53935]);
    $tagB = Tag::create(['address_book_id' => $book->id, 'name' => 'B', 'color' => 0xFF1E88E5]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddEntry')
        ->set('entryRustdeskId', '123456789')
        ->set('entryAlias', 'Test rig')
        ->set('entryTagIds', [(string) $tagA->id, (string) $tagB->id])
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry = AddressBookEntry::where('address_book_id', $book->id)->where('rustdesk_id', '123456789')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->alias)->toBe('Test rig')
        ->and($entry->tag_ids)->toEqualCanonicalizing([$tagA->id, $tagB->id]);
});

it('saves, shows and clears a note on an entry (#167)', function () {
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    $component = Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddEntry')
        ->set('entryRustdeskId', '123456789')
        ->set('entryNote', ' Server room, rack 3 ')
        ->call('saveEntry')
        ->assertHasNoErrors()
        ->assertSee('Server room, rack 3');

    $entry = AddressBookEntry::where('address_book_id', $book->id)->first();
    expect($entry->note)->toBe('Server room, rack 3');

    $component->call('openEditEntry', $entry->id)
        ->assertSet('entryNote', 'Server room, rack 3')
        ->set('entryNote', str_repeat('n', 2001))
        ->call('saveEntry')
        ->assertHasErrors(['entryNote' => 'max'])
        ->set('entryNote', '')
        ->call('saveEntry')
        ->assertHasNoErrors();

    expect($entry->fresh()->note)->toBeNull();

    // The next new entry starts with an empty note.
    $component->call('openAddEntry')->assertSet('entryNote', '');
});

it('round-trips tag colors between hex and u32 ARGB', function () {
    // hex → u32: opaque alpha ORed in
    expect(AddressBookManager::hexToColor('#2196F3'))->toBe(0xFF2196F3)
        ->and(AddressBookManager::hexToColor('#000000'))->toBe(0xFF000000)
        ->and(AddressBookManager::hexToColor('#FFFFFF'))->toBe(0xFFFFFFFF);

    // u32 → hex
    expect(AddressBookManager::colorToHex(0xFF2196F3))->toBe('#2196F3')
        ->and(AddressBookManager::colorToHex(AddressBookManager::hexToColor('#AB12CD')))->toBe('#AB12CD');

    // 0 (unset) renders as default gray
    expect(AddressBookManager::colorToHex(0))->toBe('#6C757D');

    // Full roundtrip through the component's addTag action
    $book = AddressBook::create(['name' => 'Colors', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddTag')
        ->set('tagName', 'Hot')
        ->set('tagColor', '#E53935')
        ->call('addTag')
        ->assertHasNoErrors();

    $tag = Tag::where('address_book_id', $book->id)->where('name', 'Hot')->first();
    expect($tag->color)->toBe(0xFFE53935)
        ->and(AddressBookManager::colorToHex($tag->color))->toBe('#E53935');
});

it('does not allow deleting a personal address book', function () {
    $personal = AddressBook::personalFor($this->user);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $personal->id)
        ->call('deleteBook');

    expect(AddressBook::whereKey($personal->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| B4 — console enforces the same ro / rw / full tiers as the client API.
|--------------------------------------------------------------------------
*/

it('blocks a READ-ONLY console member from adding entries, tags, or rules', function () {
    $member = member();
    $book = sharedBookFor($this->user, $member, AddressBookRule::PERM_READ);

    $component = Livewire::actingAs($member)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertSet('selectedBookId', $book->id);

    // View exposes RO tier and hides the write affordances.
    expect($component->instance()->permission())->toBe(AddressBookRule::PERM_READ)
        ->and($component->instance()->canWriteEntries())->toBeFalse()
        ->and($component->instance()->canManage())->toBeFalse();

    $component->set('entryRustdeskId', '111222333')->call('saveEntry');
    $component->set('tagName', 'nope')->set('tagColor', '#2563EB')->call('addTag');

    expect($book->entries()->count())->toBe(0)
        ->and($book->tags()->count())->toBe(0);
});

it('lets a READ/WRITE console member add entries but not tags', function () {
    $member = member();
    $book = sharedBookFor($this->user, $member, AddressBookRule::PERM_READ_WRITE);

    $component = Livewire::actingAs($member)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id);

    expect($component->instance()->canWriteEntries())->toBeTrue()
        ->and($component->instance()->canManage())->toBeFalse();

    $component->call('openAddEntry')
        ->set('entryRustdeskId', '111222333')
        ->set('entryAlias', 'ok')
        ->call('saveEntry')
        ->assertHasNoErrors();

    // Tag creation is a FULL-only operation and must be a no-op.
    $component->set('tagName', 'nope')->set('tagColor', '#2563EB')->call('addTag');

    expect($book->entries()->where('rustdesk_id', '111222333')->exists())->toBeTrue()
        ->and($book->tags()->count())->toBe(0);
});

it('lets a FULL-control console member manage entries, tags, and rules', function () {
    $member = member();
    $book = sharedBookFor($this->user, $member, AddressBookRule::PERM_FULL);

    $component = Livewire::actingAs($member)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id);

    expect($component->instance()->canManage())->toBeTrue();

    $component->call('openAddTag')->set('tagName', 'urgent')->set('tagColor', '#E53935')->call('addTag')
        ->assertHasNoErrors();
    $component->call('openAddEntry')->set('entryRustdeskId', '555')->call('saveEntry')
        ->assertHasNoErrors();

    expect($book->tags()->pluck('name')->all())->toBe(['urgent'])
        ->and($book->entries()->where('rustdesk_id', '555')->exists())->toBeTrue();
});

it('strips a deleted tag from entry tag_ids', function () {
    $book = AddressBook::create(['name' => 'Strip', 'owner_user_id' => $this->user->id, 'is_personal' => false]);
    $doomed = Tag::create(['address_book_id' => $book->id, 'name' => 'Doomed', 'color' => 0xFF43A047]);
    $kept = Tag::create(['address_book_id' => $book->id, 'name' => 'Kept', 'color' => 0xFF1E88E5]);

    $entry = AddressBookEntry::create([
        'address_book_id' => $book->id,
        'rustdesk_id' => '987654321',
        'tag_ids' => [$doomed->id, $kept->id],
    ]);

    Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('deleteTag', $doomed->id);

    expect(Tag::whereKey($doomed->id)->exists())->toBeFalse()
        ->and($entry->refresh()->tag_ids)->toEqual([$kept->id]);
});

// --- Entries identify the machine, not just the id (#5) --------------------

it('shows the machine name on an entry, not only the id', function () {
    // Entries already store hostname and platform from the client; they simply
    // were not rendered, so an entry with no alias read as a bare number.
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create(['guid' => (string) Str::uuid(), 'name' => 'Ops', 'owner_user_id' => $admin->id]);
    $book->entries()->create([
        'rustdesk_id' => '910000123',
        'hostname' => 'RECEPTION-PC',
        'platform' => 'windows',
        'alias' => '',
    ]);

    Livewire::actingAs($admin)->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->assertSee('RECEPTION-PC')
        ->assertSee('910000123');
});

it('finds an entry by machine name', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create(['guid' => (string) Str::uuid(), 'name' => 'Ops', 'owner_user_id' => $admin->id]);
    $book->entries()->create(['rustdesk_id' => '910000123', 'hostname' => 'RECEPTION-PC']);
    $book->entries()->create(['rustdesk_id' => '910000456', 'hostname' => 'WAREHOUSE-01']);

    Livewire::actingAs($admin)->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->set('entrySearch', 'warehouse')
        ->assertSee('WAREHOUSE-01')
        ->assertDontSee('RECEPTION-PC');
});

it('still finds an entry by id', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create(['guid' => (string) Str::uuid(), 'name' => 'Ops', 'owner_user_id' => $admin->id]);
    $book->entries()->create(['rustdesk_id' => '910000123', 'hostname' => 'RECEPTION-PC']);
    $book->entries()->create(['rustdesk_id' => '910000456', 'hostname' => 'WAREHOUSE-01']);

    Livewire::actingAs($admin)->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->set('entrySearch', '910000456')
        ->assertSee('WAREHOUSE-01')
        ->assertDontSee('RECEPTION-PC');
});

it('falls back to the id when an entry has no hostname', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create(['guid' => (string) Str::uuid(), 'name' => 'Ops', 'owner_user_id' => $admin->id]);
    $book->entries()->create(['rustdesk_id' => '910000789', 'hostname' => null]);

    Livewire::actingAs($admin)->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->assertSee('910000789');
});

it('audits entry, tag and rename changes made on the address books screen', function () {
    $book = AddressBook::create(['name' => 'Shared', 'owner_user_id' => $this->user->id, 'is_personal' => false]);

    $component = Livewire::actingAs($this->user)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openAddEntry')
        ->set('entryRustdeskId', '123456789')
        ->call('saveEntry')
        ->assertHasNoErrors();

    $entry = AddressBookEntry::where('address_book_id', $book->id)->firstOrFail();

    $component->call('openEditEntry', $entry->id)
        ->set('entryAlias', 'Front desk')
        ->call('saveEntry')
        ->call('deleteEntry', $entry->id)
        ->call('openAddTag')
        ->set('tagName', 'Kiosks')
        ->set('tagColor', '#1E88E5')
        ->call('addTag')
        ->call('deleteTag', Tag::where('address_book_id', $book->id)->value('id'))
        ->call('openRenameBook')
        ->set('bookName', 'Front office')
        ->call('renameBook')
        ->assertHasNoErrors();

    expect(ConsoleAudit::where('action', 'like', 'address-book.%')->orderBy('id')->pluck('action')->all())
        ->toBe([
            'address-book.peer-add',
            'address-book.peer-update',
            'address-book.peer-delete',
            'address-book.tag-add',
            'address-book.tag-delete',
            'address-book.update',
        ])
        ->and(ConsoleAudit::where('action', 'address-book.update')->value('summary'))
        ->toBe('Renamed address book Shared to Front office');
});

it('sets, keeps and removes a shared book default password (#162)', function () {
    $this->actingAs($this->user);

    Livewire::test(AddressBookManager::class)
        ->call('openNewBook')
        ->set('bookName', 'Shops')
        ->set('bookPassword', 'first')
        ->call('createBook')
        ->assertHasNoErrors();

    $book = AddressBook::where('name', 'Shops')->firstOrFail();
    expect(Crypt::decryptString($book->default_password_enc))->toBe('first');

    $component = Livewire::test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('openRenameBook')
        ->assertSet('bookPasswordSet', true)
        ->assertSet('bookPassword', '')
        ->call('renameBook');
    expect(Crypt::decryptString($book->fresh()->default_password_enc))->toBe('first');

    $component->call('openRenameBook')->set('bookPassword', 'second')->call('renameBook');
    expect(Crypt::decryptString($book->fresh()->default_password_enc))->toBe('second');

    $component->call('openRenameBook')->set('bookPasswordClear', true)->call('renameBook');
    expect($book->fresh()->default_password_enc)->toBeNull()
        ->and(ConsoleAudit::where('action', 'address-book.update')->orderBy('id')->pluck('summary')->all())
        ->toBe(['Updated address book Shops', 'Updated address book Shops, default password changed', 'Updated address book Shops, default password cleared'])
        ->and(ConsoleAudit::where('action', 'address-book.create')->value('summary'))->toBe('Created address book Shops, default password set');
});
