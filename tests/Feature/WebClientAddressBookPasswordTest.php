<?php

use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;

const AB_PEER = '123456789';
const AB_SALT = 'Zx8pQ2';
const AB_PASSWORD = 'Escrowed-Pa55';

function abUser(bool $admin = false): User
{
    $name = 'u-'.uniqid();

    return User::create([
        'username' => $name,
        'name' => $name,
        'email' => $name.'@example.com',
        'password' => 'secret-password',
        'is_admin' => $admin,
        'is_active' => true,
    ]);
}

/** A shared book owned by $owner holding AB_PEER with an escrowed password. */
function escrowBook(User $owner, string $password = AB_PASSWORD, string $name = 'Fleet'): AddressBook
{
    $book = AddressBook::create(['name' => $name, 'owner_user_id' => $owner->id, 'is_personal' => false]);
    $book->entries()->create([
        'rustdesk_id' => AB_PEER,
        'alias' => 'Front desk',
        'password_enc' => Crypt::encryptString($password),
    ]);

    return $book;
}

function askPassword(User $user, array $body = []): TestResponse
{
    return test()->actingAs($user)->postJson('/webclient/address-book-password', $body + [
        'id' => AB_PEER,
        'salt' => AB_SALT,
    ]);
}

beforeEach(function () {
    $this->owner = abUser(admin: true);
});

it('returns h1 for the device salt, never the password', function () {
    escrowBook($this->owner);

    $response = askPassword($this->owner)
        ->assertOk()
        ->assertExactJson(['hash' => hash('sha256', AB_PASSWORD.AB_SALT)]);

    expect($response->getContent())->not->toContain(AB_PASSWORD);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

// Fixed vector shared with webclient/src/core/session.address-book.test.ts: the
// console's h1 and the web client's computeLoginH1() must agree byte for byte.
it('matches the web client h1 for a fixed vector', function () {
    escrowBook($this->owner);

    askPassword($this->owner)->assertOk()
        ->assertExactJson(['hash' => 'a3359ea34ba87a32d2701ea1eec9b1873ba1f634c2880715e1ad40a37dbe182a']);
});

it('gives a different hash for a different salt', function () {
    escrowBook($this->owner);

    askPassword($this->owner, ['salt' => 'OtherSalt'])
        ->assertOk()
        ->assertExactJson(['hash' => hash('sha256', AB_PASSWORD.'OtherSalt')]);
});

it('serves a user with read access through a group rule', function () {
    $book = escrowBook($this->owner);
    $member = abUser();
    $group = UserGroup::create(['name' => 'Technicians']);
    $member->groups()->attach($group->id);
    $book->rules()->create(['subject_type' => 'group', 'subject_id' => $group->id, 'permission' => AddressBookRule::PERM_READ]);

    askPassword($member)->assertOk()->assertJsonPath('hash', hash('sha256', AB_PASSWORD.AB_SALT));
});

it('refuses a user without access to the book', function () {
    escrowBook($this->owner);

    askPassword(abUser())->assertNotFound()->assertJsonMissingPath('hash');
    expect(ConsoleAudit::where('action', 'address-book.password-use')->count())->toBe(0);
});

it('uses the owner\'s own personal-book hash', function () {
    $user = abUser();
    $h1 = hash('sha256', 'personal-pw'.AB_SALT, true);
    AddressBook::personalFor($user)->entries()->create([
        'rustdesk_id' => AB_PEER,
        'hash' => base64_encode($h1),
    ]);

    askPassword($user)->assertOk()->assertExactJson(['hash' => bin2hex($h1)]);
});

it('never hands out another user\'s personal-book hash, even to an admin', function () {
    $user = abUser();
    AddressBook::personalFor($user)->entries()->create([
        'rustdesk_id' => AB_PEER,
        'hash' => base64_encode(random_bytes(32)),
    ]);

    askPassword($this->owner)->assertNotFound();
});

it('prefers a shared escrowed password over a personal hash', function () {
    $user = abUser();
    $book = escrowBook($this->owner);
    $book->rules()->create(['subject_type' => 'user', 'subject_id' => $user->id, 'permission' => AddressBookRule::PERM_READ]);
    AddressBook::personalFor($user)->entries()->create([
        'rustdesk_id' => AB_PEER,
        'hash' => base64_encode(random_bytes(32)),
    ]);

    askPassword($user)->assertOk()->assertJsonPath('hash', hash('sha256', AB_PASSWORD.AB_SALT));
});

it('accepts a device id with spaces, as the web client shows it', function () {
    escrowBook($this->owner);

    askPassword($this->owner, ['id' => '123 456 789'])->assertOk();
});

it('audit-logs who used which device, without the secret', function () {
    escrowBook($this->owner, name: 'Front office');

    askPassword($this->owner)->assertOk();

    $row = ConsoleAudit::where('action', 'address-book.password-use')->sole();
    expect($row->user_id)->toBe($this->owner->id)
        ->and($row->target_type)->toBe('device')
        ->and($row->target_id)->toBe(AB_PEER)
        ->and($row->summary)->toContain('Front office')
        ->and($row->summary)->not->toContain(AB_PASSWORD)
        ->and($row->summary)->not->toContain(hash('sha256', AB_PASSWORD.AB_SALT));
});

it('is not reachable signed out or with GET', function () {
    escrowBook($this->owner);

    $this->postJson('/webclient/address-book-password', ['id' => AB_PEER, 'salt' => AB_SALT])->assertUnauthorized();
    $this->actingAs($this->owner)->get('/webclient/address-book-password?id='.AB_PEER.'&salt='.AB_SALT)
        ->assertMethodNotAllowed();
});

it('validates the salt', function () {
    escrowBook($this->owner);

    askPassword($this->owner, ['salt' => ''])->assertUnprocessable();
    askPassword($this->owner, ['salt' => str_repeat('a', 129)])->assertUnprocessable();
    askPassword($this->owner, ['salt' => "bad salt\n"])->assertUnprocessable();
});

it('can be switched off', function () {
    escrowBook($this->owner);
    config(['cortendesk.webclient_address_book_passwords' => false]);

    askPassword($this->owner)->assertNotFound();
});

it('tells the web client page whether the device has an entry, but not the password', function () {
    escrowBook($this->owner);

    $page = $this->actingAs($this->owner)->get('/webclient?id='.AB_PEER)->assertOk();
    $page->assertSee('"available":true', false);
    $page->assertSee('\/webclient\/address-book-password', false);
    $page->assertDontSee(AB_PASSWORD);

    $this->actingAs($this->owner)->get('/webclient?id=987654321')->assertOk()->assertSee('"available":false', false);
});
