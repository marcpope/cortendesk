<?php

use App\Livewire\AddressBookManager;
use App\Livewire\DeviceList;
use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function addressBookUserWithConsoleLevel(string $level): User
{
    $role = Role::create([
        'name' => 'Address book '.$level.' '.fake()->unique()->word(),
        'permissions' => Role::normalizePermissions([
            'address_book' => $level,
            'device' => 'rw',
        ]),
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('a delegated address book viewer cannot add entries to an owned personal book', function () {
    $user = addressBookUserWithConsoleLevel('r');
    $book = AddressBook::personalFor($user);

    Livewire::actingAs($user)
        ->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->set('entryRustdeskId', 'readonly-denied-peer')
        ->set('entryAlias', 'Must not be created')
        ->call('saveEntry');

    expect(AddressBookEntry::where('address_book_id', $book->id)->count())->toBe(0);
});

test('an owned shared book renders read only for a delegated address book viewer', function () {
    $user = addressBookUserWithConsoleLevel('r');
    $book = AddressBook::create([
        'name' => 'Viewer-owned shared book',
        'owner_user_id' => $user->id,
        'is_personal' => false,
    ]);

    $this->actingAs($user);
    $component = app(AddressBookManager::class);
    $component->mount();
    $component->selectedBookId = $book->id;
    $view = $component->render();
    $data = $view->getData();

    expect($data['permission'])->toBe(AddressBookRule::PERM_READ)
        ->and($data['canWriteEntries'])->toBeFalse()
        ->and($data['canManage'])->toBeFalse();

    Livewire::actingAs($user)
        ->test(AddressBookManager::class)
        ->set('selectedBookId', $book->id)
        ->set('ruleSubjectType', 'everyone')
        ->set('rulePermission', AddressBookRule::PERM_FULL)
        ->call('addRule');

    expect($book->rules()->count())->toBe(0);
});

test('a delegated address book editor retains the owned book permissions', function () {
    $user = addressBookUserWithConsoleLevel('rw');
    $book = AddressBook::create([
        'name' => 'Editor-owned shared book',
        'owner_user_id' => $user->id,
        'is_personal' => false,
    ]);

    $this->actingAs($user);
    $component = app(AddressBookManager::class);
    $component->mount();
    $component->selectedBookId = $book->id;
    $view = $component->render();
    $data = $view->getData();

    expect($data['permission'])->toBe(AddressBookRule::PERM_FULL)
        ->and($data['canWriteEntries'])->toBeTrue()
        ->and($data['canManage'])->toBeTrue();

    $component->entryRustdeskId = 'editor-allowed-peer';
    $component->saveEntry();
    $component->ruleSubjectType = 'everyone';
    $component->rulePermission = AddressBookRule::PERM_READ;
    $component->addRule();

    expect($book->entries()->where('rustdesk_id', 'editor-allowed-peer')->exists())->toBeTrue()
        ->and($book->rules()->where('subject_type', 'everyone')->exists())->toBeTrue();
});

test('a delegated address book viewer cannot add devices to a book from the device list', function () {
    $user = addressBookUserWithConsoleLevel('r');
    $book = AddressBook::personalFor($user);
    $device = Device::create([
        'rustdesk_id' => 'readonly-device-list-peer',
        'uuid' => 'readonly-device-list-peer-uuid',
        'status' => Device::STATUS_ACTIVE,
        'user_id' => $user->id,
    ]);

    expect($book->permissionFor($user))->toBe(AddressBookRule::PERM_FULL)
        ->and(Device::visibleTo($user)->whereKey($device->id)->exists())->toBeTrue();

    $this->actingAs($user);
    $component = app(DeviceList::class);
    $component->selected = [(string) $device->id];
    $component->abBookId = $book->id;

    expect(fn () => $component->openAbPicker())
        ->toThrow(HttpException::class);
    expect(fn () => $component->addSelectedToBook())
        ->toThrow(HttpException::class);

    expect($book->entries()->where('rustdesk_id', $device->rustdesk_id)->exists())->toBeFalse();
});

test('a delegated address book editor can add devices to a book from the device list', function () {
    $user = addressBookUserWithConsoleLevel('rw');
    $book = AddressBook::personalFor($user);
    $device = Device::create([
        'rustdesk_id' => 'editor-device-list-peer',
        'uuid' => 'editor-device-list-peer-uuid',
        'status' => Device::STATUS_ACTIVE,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);
    $component = app(DeviceList::class);
    $component->selected = [(string) $device->id];
    $component->abBookId = $book->id;
    $component->addSelectedToBook();

    expect($book->entries()->where('rustdesk_id', $device->rustdesk_id)->exists())->toBeTrue();
});
