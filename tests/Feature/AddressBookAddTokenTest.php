<?php

use App\Livewire\ApiTokenManager;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

/*
 * #151: an Automation API token that may only add entries to chosen shared
 * address books, and a write-only peer password.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->book = AddressBook::create(['name' => 'Rollout', 'owner_user_id' => $this->admin->id, 'is_personal' => false]);
    $this->other = AddressBook::create(['name' => 'Other', 'owner_user_id' => $this->admin->id, 'is_personal' => false]);
    $this->other->entries()->create(['rustdesk_id' => '999000111', 'alias' => 'Keep out']);
    [, $this->add] = ApiToken::issue($this->admin, 'rollout', [], null, [$this->book->id]);
});

function addHeaders(string $plain): array
{
    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

it('adds an entry with a password and returns only its id', function () {
    $res = $this->postJson("/api/v1/address-books/{$this->book->id}/peers",
        ['rustdesk_id' => '123456789', 'alias' => 'Front desk PC', 'password' => 's3cret'], addHeaders($this->add))
        ->assertCreated();

    $entry = AddressBookEntry::where('rustdesk_id', '123456789')->firstOrFail();
    expect($res->json('data'))->toBe(['id' => $entry->id])
        ->and(Crypt::decryptString($entry->password_enc))->toBe('s3cret')
        ->and(ConsoleAudit::where('action', 'address-book.peer-add')->value('summary'))
        ->toContain('password set')->not->toContain('s3cret');
});

it('answers 409 for an ID already in the book, adding nothing', function () {
    $body = ['rustdesk_id' => '123456789'];
    $this->postJson("/api/v1/address-books/{$this->book->id}/peers", $body, addHeaders($this->add))->assertCreated();
    $this->postJson("/api/v1/address-books/{$this->book->id}/peers", $body, addHeaders($this->add))->assertStatus(409);

    expect($this->book->entries()->count())->toBe(1);
});

it('can do nothing else', function () {
    $h = addHeaders($this->add);
    $this->postJson("/api/v1/address-books/{$this->other->id}/peers", ['rustdesk_id' => '1'], $h)->assertForbidden();
    $this->getJson('/api/v1/address-books', $h)->assertForbidden();
    $this->getJson("/api/v1/address-books/{$this->book->id}", $h)->assertForbidden();
    $this->getJson("/api/v1/address-books/{$this->book->id}/peers", $h)->assertForbidden();
    $this->getJson("/api/v1/address-books/{$this->other->id}/peers", $h)->assertForbidden();
    $peer = $this->other->entries()->first();
    $this->putJson("/api/v1/address-books/{$this->other->id}/peers/{$peer->id}", ['alias' => 'x'], $h)->assertForbidden();
    $this->deleteJson("/api/v1/address-books/{$this->other->id}/peers/{$peer->id}", [], $h)->assertForbidden();
    $this->deleteJson("/api/v1/address-books/{$this->book->id}", [], $h)->assertForbidden();
    $this->getJson('/api/v1/devices', $h)->assertForbidden();
});

it('grants add-only only on shared books, and only from someone who manages address books', function () {
    $personal = AddressBook::create(['name' => 'Mine', 'owner_user_id' => $this->admin->id, 'is_personal' => true]);
    [$token] = ApiToken::issue($this->admin, 't', [], null, [$this->book->id, $personal->id, 99999]);
    expect($token->address_book_add)->toBe([$this->book->id]);

    $role = Role::create(['name' => 'ab-viewer', 'permissions' => Role::normalizePermissions(['address_book' => 'r', 'token' => 'rw'])]);
    [$token] = ApiToken::issue(User::factory()->create(['role_id' => $role->id]), 't', [], null, [$this->book->id]);
    expect($token->address_book_add)->toBeNull();
});

it('sets, keeps and clears a password through an rw token, never returning it', function () {
    [, $rw] = ApiToken::issue($this->admin, 'rw', ['address_book' => 'rw']);
    $h = addHeaders($rw);
    $id = $this->postJson("/api/v1/address-books/{$this->book->id}/peers", ['rustdesk_id' => '555', 'password' => 'one'], $h)
        ->assertCreated()->assertJsonPath('data.has_password', true)->json('data.id');
    $url = "/api/v1/address-books/{$this->book->id}/peers/{$id}";

    $this->putJson($url, ['alias' => 'renamed'], $h)->assertOk()->assertJsonPath('data.has_password', true)->assertJsonMissingPath('data.password');
    expect(Crypt::decryptString(AddressBookEntry::find($id)->password_enc))->toBe('one');

    $this->putJson($url, ['password' => 'two'], $h)->assertOk();
    expect(Crypt::decryptString(AddressBookEntry::find($id)->password_enc))->toBe('two');

    $this->putJson($url, ['password' => null], $h)->assertOk()->assertJsonPath('data.has_password', false);
    expect(AddressBookEntry::find($id)->password_enc)->toBeNull();

    expect(ConsoleAudit::where('action', 'address-book.peer-update')->pluck('summary')->implode('|'))
        ->toContain('password changed')->toContain('password cleared')->not->toContain('two');
});

it('refuses a password for a personal book instead of dropping it', function () {
    [, $rw] = ApiToken::issue($this->admin, 'rw', ['address_book' => 'rw']);
    $personal = AddressBook::create(['name' => 'Mine', 'owner_user_id' => $this->admin->id, 'is_personal' => true]);

    $this->postJson("/api/v1/address-books/{$personal->id}/peers", ['rustdesk_id' => '555', 'password' => 'x'], addHeaders($rw))
        ->assertStatus(422);
    expect($personal->entries()->count())->toBe(0);
});

it('creates an add-only token in the console', function () {
    Livewire::actingAs($this->admin)->test(ApiTokenManager::class)
        ->call('create')
        ->set('name', 'Rollout script')
        ->set('addBooks', [(string) $this->book->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee(__('api_tokens.add_entries_to', ['books' => 'Rollout']));

    $token = ApiToken::where('name', 'Rollout script')->firstOrFail();
    expect($token->address_book_add)->toBe([$this->book->id])
        ->and(collect($token->permissions)->unique()->values()->all())->toBe(['none']);
});
