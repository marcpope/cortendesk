<?php

use App\Livewire\DeviceDetail;
use App\Models\AddressBook;
use App\Models\AddressBookRule;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function deviceDetailAddressBookFixture(int $bookCount = 25): array
{
    $role = Role::create([
        'name' => 'Device detail address books '.fake()->unique()->word(),
        'permissions' => Role::normalizePermissions([
            'device' => 'r',
            'audit' => 'none',
        ]),
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $group = UserGroup::create(['name' => 'Shared books '.fake()->unique()->word()]);
    $user->groups()->attach($group);
    $device = Device::create([
        'rustdesk_id' => 'device-detail-peer',
        'uuid' => 'device-detail-address-book-perf',
        'status' => Device::STATUS_ACTIVE,
        'user_id' => $user->id,
    ]);

    for ($index = 1; $index <= $bookCount; $index++) {
        $book = AddressBook::create([
            'name' => sprintf('Shared performance book %02d', $index),
            'owner_user_id' => User::factory()->create()->id,
            'is_personal' => false,
        ]);
        $book->rules()->create([
            'subject_type' => 'group',
            'subject_id' => $group->id,
            'permission' => AddressBookRule::PERM_READ,
        ]);
        $book->entries()->create(['rustdesk_id' => $device->rustdesk_id]);
    }

    $directWriteBook = AddressBook::create([
        'name' => 'Direct write semantic book',
        'owner_user_id' => User::factory()->create()->id,
        'is_personal' => false,
    ]);
    $directWriteBook->rules()->create([
        'subject_type' => 'user',
        'subject_id' => $user->id,
        'permission' => AddressBookRule::PERM_READ_WRITE,
    ]);
    $directWriteBook->entries()->create(['rustdesk_id' => $device->rustdesk_id]);

    $deniedBook = AddressBook::create([
        'name' => 'Denied hidden semantic book',
        'owner_user_id' => User::factory()->create()->id,
        'is_personal' => false,
    ]);
    $deniedBook->entries()->create(['rustdesk_id' => $device->rustdesk_id]);

    return [$user, $device, $directWriteBook, $deniedBook, $group];
}

test('device detail loads only permitted matching address books with bounded queries', function () {
    [$user, $device, $directWriteBook, $deniedBook, $group] = deviceDetailAddressBookFixture();
    expect($directWriteBook->fresh()->load('rules')->permissionFor($user, [$group->id]))
        ->toBe(AddressBookRule::PERM_READ_WRITE)
        ->and($deniedBook->fresh()->load('rules')->permissionFor($user, [$group->id]))
        ->toBe(0);

    // The Share screen card nests here with its own permission checks; this
    // counts the detail component's queries only.
    config(['cortendesk.share_links' => false]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::actingAs($user)
        ->test(DeviceDetail::class, ['deviceId' => $device->id])
        ->assertSee($directWriteBook->name)
        ->assertDontSee($deniedBook->name)
        ->assertSee('Shared performance book 01')
        ->assertSee('Shared performance book 25');

    $ruleQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'address_book_rules'))->count();
    $addressBookMembershipQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "user_groups"')
        && str_contains($sql, 'user_group_user'))->count();
    expect($ruleQueries)->toBeLessThanOrEqual(2)
        ->and($addressBookMembershipQueries)->toBeLessThanOrEqual(3)
        ->and(count($queries))->toBeLessThanOrEqual(20);
});

test('shared address book profiles resolve group permissions with bounded queries', function () {
    [$user, , $directWriteBook, $deniedBook] = deviceDetailAddressBookFixture();
    $token = ClientToken::issue($user);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token->token)
        ->postJson('/api/ab/shared/profiles?current=1&pageSize=100')
        ->assertOk()
        ->assertJsonPath('total', 26)
        ->assertJsonPath('data.0.name', $directWriteBook->name)
        ->assertJsonPath('data.0.rule', AddressBookRule::PERM_READ_WRITE)
        ->assertJsonPath('data.1.name', 'Shared performance book 01')
        ->assertJsonPath('data.1.rule', AddressBookRule::PERM_READ)
        ->assertJsonPath('data.25.name', 'Shared performance book 25')
        ->assertJsonMissing(['name' => $deniedBook->name]);

    $membershipQueries = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "user_groups"')
        && str_contains($sql, 'user_group_user'))->count();
    expect($membershipQueries)->toBeLessThanOrEqual(2)
        ->and(count($queries))->toBeLessThanOrEqual(10);
});
