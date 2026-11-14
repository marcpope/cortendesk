<?php

use App\Livewire\DeviceDetail;
use App\Livewire\DeviceList;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/*
 * Deleting a device can take it out of address books too (issue #85).
 */

function abDelDevice(string $rid, array $attrs = []): Device
{
    return Device::create(array_merge(['rustdesk_id' => $rid, 'uuid' => 'u-'.$rid, 'hostname' => 'h-'.$rid], $attrs));
}

function abDelBook(string $name, User $owner, bool $personal = false): AddressBook
{
    return AddressBook::create(['name' => $name, 'owner_user_id' => $owner->id, 'is_personal' => $personal]);
}

it('offers address-book removal in the confirm step, checked when entries exist', function () {
    $admin = User::factory()->admin()->create();
    $device = abDelDevice('700000001');
    abDelBook('Team', $admin)->entries()->create(['rustdesk_id' => '700000001']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('confirmDelete', $device->id)
        ->assertSet('deleteIds', [$device->id])
        ->assertSet('deleteFromBooks', true)
        ->assertSee('Also remove from address books (1 entry)');

    $other = abDelDevice('700000002');
    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('confirmDelete', $other->id)
        ->assertSet('deleteFromBooks', false)
        ->assertSee('It is in no address book.');
});

it('removes entries only from books the actor can write and says what was kept', function () {
    $actor = User::factory()->create();
    $stranger = User::factory()->create();
    $device = abDelDevice('700000010', ['user_id' => $actor->id]);

    $own = abDelBook('Mine', $actor, true);
    $own->entries()->create(['rustdesk_id' => '700000010']);
    $writable = abDelBook('Shared RW', $stranger);
    $writable->rules()->create(['subject_type' => 'user', 'subject_id' => $actor->id, 'permission' => AddressBookRule::PERM_READ_WRITE]);
    $writable->entries()->create(['rustdesk_id' => '700000010']);
    $readOnly = abDelBook('Shared RO', $stranger);
    $readOnly->rules()->create(['subject_type' => 'user', 'subject_id' => $actor->id, 'permission' => AddressBookRule::PERM_READ]);
    $readOnly->entries()->create(['rustdesk_id' => '700000010']);
    $private = abDelBook('Stranger personal', $stranger, true);
    $private->entries()->create(['rustdesk_id' => '700000010']);

    $c = Livewire::actingAs($actor)->test(DeviceList::class)
        ->call('confirmDelete', $device->id)
        ->assertSee('Also remove from address books (4 entries)')
        ->call('performDelete');

    expect($device->fresh()->trashed())->toBeTrue()
        ->and(AddressBookEntry::where('rustdesk_id', '700000010')->pluck('address_book_id')->sort()->values()->all())
        ->toBe([$readOnly->id, $private->id])
        ->and($c->get('bulkResult'))->toContain('Removed 2 address-book entries')->toContain('2 kept');

    $this->assertDatabaseHas('console_audits', ['action' => 'address-book.peer-delete', 'target_id' => 'Shared RW']);
    $this->assertDatabaseHas('console_audits', ['action' => 'address-book.peer-delete', 'target_id' => 'Mine']);
    $this->assertDatabaseMissing('console_audits', ['action' => 'address-book.peer-delete', 'target_id' => 'Shared RO']);
});

it('leaves address books alone when the box is unchecked', function () {
    $admin = User::factory()->admin()->create();
    $device = abDelDevice('700000020');
    abDelBook('Team', $admin)->entries()->create(['rustdesk_id' => '700000020']);

    Livewire::actingAs($admin)->test(DeviceList::class)
        ->call('confirmDelete', $device->id)
        ->set('deleteFromBooks', false)
        ->call('performDelete');

    expect($device->fresh()->trashed())->toBeTrue()
        ->and(AddressBookEntry::where('rustdesk_id', '700000020')->count())->toBe(1);
});

it('bulk delete goes through the same confirm step', function () {
    $admin = User::factory()->admin()->create();
    $a = abDelDevice('700000030');
    $b = abDelDevice('700000031');
    $book = abDelBook('Team', $admin);
    $book->entries()->create(['rustdesk_id' => '700000030']);
    $book->entries()->create(['rustdesk_id' => '700000031']);

    $c = Livewire::actingAs($admin)->test(DeviceList::class)
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('confirmDeleteSelected')
        ->assertSee('Delete 2 devices?')
        ->assertSee('(2 entries)')
        ->call('performDelete');

    expect(Device::onlyTrashed()->count())->toBe(2)
        ->and($book->entries()->count())->toBe(0)
        ->and($c->get('bulkResult'))->toContain('2 devices moved')->toContain('Removed 2');
});

it('the detail page delete removes from books and returns to the list', function () {
    $admin = User::factory()->admin()->create();
    $device = abDelDevice('700000040');
    abDelBook('Team', $admin)->entries()->create(['rustdesk_id' => '700000040']);

    Livewire::actingAs($admin)->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('confirmDelete')
        ->assertSet('deleteFromBooks', true)
        ->call('performDelete')
        ->assertRedirect(route('devices'));

    expect($device->fresh()->trashed())->toBeTrue()
        ->and(AddressBookEntry::where('rustdesk_id', '700000040')->count())->toBe(0);
    $this->assertDatabaseHas('console_audits', ['action' => 'device.delete', 'target_id' => '700000040']);

    // The list picks the outcome up from the session.
    $this->actingAs($admin)->withSession(['devices.result' => 'Device 700000040 moved to the recycle bin.'])
        ->get(route('devices'))->assertOk()->assertSee('Device 700000040 moved to the recycle bin.');
});

it('refuses the delete for a role without device manage', function () {
    $role = Role::create(['name' => 'viewer', 'permissions' => ['device' => 'r']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $device = abDelDevice('700000050', ['user_id' => $viewer->id]);

    Livewire::actingAs($viewer)->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->call('confirmDelete')
        ->assertForbidden();
});

it('admin API delete keeps address books by default', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'del-'.uniqid(), ['device' => 'rw']);
    $device = abDelDevice('700000060');
    abDelBook('Team', $creator)->entries()->create(['rustdesk_id' => '700000060']);

    $this->deleteJson("/api/v1/devices/{$device->id}", [], ['Authorization' => "Bearer {$plain}"])
        ->assertOk()->assertJsonPath('data', null);

    expect(Device::withTrashed()->find($device->id))->toBeNull()
        ->and(AddressBookEntry::where('rustdesk_id', '700000060')->count())->toBe(1);
});

it('admin API delete removes from every book when asked and the token allows it', function () {
    $creator = User::factory()->admin()->create();
    $other = User::factory()->create();
    [, $plain] = ApiToken::issue($creator, 'del-'.uniqid(), ['device' => 'rw', 'address_book' => 'rw']);
    $device = abDelDevice('700000070');
    abDelBook('Team', $creator)->entries()->create(['rustdesk_id' => '700000070']);
    abDelBook('Theirs', $other, true)->entries()->create(['rustdesk_id' => '700000070']);

    $this->deleteJson("/api/v1/devices/{$device->id}", ['remove_from_address_books' => true], ['Authorization' => "Bearer {$plain}"])
        ->assertOk()->assertJsonPath('data.address_book_entries_removed', 2);

    expect(AddressBookEntry::where('rustdesk_id', '700000070')->count())->toBe(0);
});

it('admin API delete with removal needs address_book rw and changes nothing without it', function () {
    $creator = User::factory()->admin()->create();
    [, $plain] = ApiToken::issue($creator, 'del-'.uniqid(), ['device' => 'rw']);
    $device = abDelDevice('700000080');
    abDelBook('Team', $creator)->entries()->create(['rustdesk_id' => '700000080']);

    $this->deleteJson("/api/v1/devices/{$device->id}?remove_from_address_books=1", [], ['Authorization' => "Bearer {$plain}"])
        ->assertForbidden();

    expect(Device::find($device->id))->not->toBeNull()
        ->and(AddressBookEntry::where('rustdesk_id', '700000080')->count())->toBe(1);
});
