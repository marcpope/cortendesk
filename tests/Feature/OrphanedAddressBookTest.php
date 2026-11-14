<?php

use App\Livewire\AddressBookManager;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Orphaned address books (issue #14)
|--------------------------------------------------------------------------
| Deleting a user left their personal address book behind. It listed as
| "unknown" and could never be removed by anyone: permissionFor() returned 0
| for every non-owner on a personal book — admins included — and the owner no
| longer existed, so no permission check could reach it.
*/

it('removes a personal address book when its owner is deleted', function () {
    $user = User::factory()->create();
    $book = AddressBook::personalFor($user);

    $user->delete();

    expect(AddressBook::find($book->id))->toBeNull();
});

it('takes the book contents with it rather than orphaning rows', function () {
    $user = User::factory()->create();
    $book = AddressBook::personalFor($user);
    AddressBookEntry::create(['address_book_id' => $book->id, 'rustdesk_id' => '123456789']);

    $user->delete();

    expect(AddressBookEntry::where('address_book_id', $book->id)->count())->toBe(0);
});

it('leaves shared books alone, since their access comes from rules not ownership', function () {
    $owner = User::factory()->create();
    $shared = AddressBook::create([
        'name' => 'Team', 'owner_user_id' => $owner->id, 'is_personal' => false,
    ]);

    $owner->delete();

    expect(AddressBook::find($shared->id))->not->toBeNull();
});

it('lets an admin reach a book orphaned before the fix existed', function () {
    // Simulates the state users already have: a personal book whose owner row
    // is gone. Written directly because the model now prevents creating one.
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create([
        'name' => 'My address book', 'owner_user_id' => 999999, 'is_personal' => true,
    ]);

    expect($book->isOrphaned())->toBeTrue()
        ->and($book->permissionFor($admin))->toBeGreaterThan(0);
});

it('still keeps a live user\'s personal book private from admins', function () {
    // The fix must not become a back door into everyone's personal book.
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $book = AddressBook::personalFor($owner);

    expect($book->isOrphaned())->toBeFalse()
        ->and($book->permissionFor($admin))->toBe(0);
});

it('allows an admin to delete an orphaned book from the console', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create([
        'name' => 'My address book', 'owner_user_id' => 999999, 'is_personal' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('deleteBook');

    expect(AddressBook::find($book->id))->toBeNull();
});

it('refuses to delete a live user\'s personal book', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $book = AddressBook::personalFor($owner);

    Livewire::actingAs($admin)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->call('deleteBook');

    expect(AddressBook::find($book->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The button must actually be on screen (issue #14, second report)
|--------------------------------------------------------------------------
| 1.0.2 fixed the backend and was verified by calling deleteBook() straight
| through Livewire — which bypasses the view. The blade template still hid the
| control behind `! $book->is_personal`, so the permission was real and utterly
| unreachable. These assert what a user can SEE, not what a method will do.
*/

it('shows a delete control on an orphaned book', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create([
        'name' => 'My address book', 'owner_user_id' => 999999, 'is_personal' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertSeeHtml('wire:click="deleteBook"');
});

it('labels an orphaned book so it is clear why it can be removed', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $book = AddressBook::create([
        'name' => 'My address book', 'owner_user_id' => 999999, 'is_personal' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertSee('Orphaned');
});

it('shows no delete control on a live user\'s personal book', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $book = AddressBook::personalFor($owner);

    Livewire::actingAs($admin)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertDontSeeHtml('wire:click="deleteBook"');
});

it('shows the owner their own personal book without a delete control', function () {
    $owner = User::factory()->create();
    $book = AddressBook::personalFor($owner);

    Livewire::actingAs($owner)
        ->test(AddressBookManager::class)
        ->call('selectBook', $book->id)
        ->assertDontSeeHtml('wire:click="deleteBook"');
});
