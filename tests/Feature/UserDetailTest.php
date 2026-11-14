<?php

use App\Livewire\UserDetail;
use App\Models\AddressBook;
use App\Models\AuditConnection;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Livewire\Livewire;

/*
 * User detail page (issue #55).
 */

function udTarget(): User
{
    $user = User::factory()->create([
        'username' => 'jdoe',
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'totp_enabled' => true,
    ]);
    $user->groups()->attach(UserGroup::create(['name' => 'Helpdesk']));

    return $user;
}

it('shows the profile, owned devices and owned books', function () {
    $user = udTarget();
    $device = Device::create(['rustdesk_id' => '811000001', 'uuid' => 'ud1', 'hostname' => 'JANE-LAPTOP', 'user_id' => $user->id]);
    AddressBook::create(['name' => 'Jane shared', 'owner_user_id' => $user->id, 'is_personal' => false])
        ->entries()->create(['rustdesk_id' => '811000001']);
    AddressBook::personalFor($user);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('users.show', $user->id))
        ->assertOk()
        ->assertSee('Jane Doe')
        ->assertSee('jane@example.test')
        ->assertSee('Helpdesk')
        ->assertSee('Enabled')
        ->assertSee('Password')
        ->assertSee(route('devices.show', $device->id), false)
        ->assertSee('JANE-LAPTOP')
        ->assertSee('Jane shared')
        ->assertSee('My address book');
});

it('shows sign-ins, console actions and the last sign-in', function () {
    $user = udTarget();
    LoginLog::create(['user_id' => $user->id, 'username' => 'jdoe', 'client' => 'web', 'ip' => '198.51.100.20', 'successful' => true]);
    LoginLog::create(['user_id' => null, 'username' => 'jdoe', 'client' => 'web', 'ip' => '198.51.100.99', 'successful' => false]);
    LoginLog::create(['user_id' => null, 'username' => 'someone', 'client' => 'web', 'ip' => '198.51.100.77', 'successful' => false]);
    ConsoleAudit::create(['user_id' => $user->id, 'username' => 'jdoe', 'action' => 'device.update', 'summary' => 'Updated device 811000009']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('users.show', $user->id))
        ->assertSee('Last sign-in')
        ->assertSee('198.51.100.20')
        ->assertSee('198.51.100.99')
        ->assertDontSee('198.51.100.77')
        ->assertSee('Updated device 811000009')
        ->assertSee(route('logs.logins').'?search=jdoe', false);
});

it('attributes connections by ownership and by client sign-in device, and says so', function () {
    $user = udTarget();
    Device::create(['rustdesk_id' => '811000010', 'uuid' => 'ud10', 'user_id' => $user->id]);
    Device::create(['rustdesk_id' => '811000011', 'uuid' => 'ud11']);
    LoginLog::create(['user_id' => $user->id, 'username' => 'jdoe', 'client' => 'desktop', 'device_id' => '811000099', 'successful' => true]);

    // Into a device Jane owns.
    AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => '811000010', 'from_peer' => '555000001', 'from_name' => 'helper-pc']);
    // From the device Jane signed in on, into someone else's device.
    AuditConnection::create(['action' => 'new', 'conn_id' => 2, 'rustdesk_id' => '811000011', 'from_peer' => '811000099', 'from_name' => 'x']);
    // Unrelated.
    AuditConnection::create(['action' => 'new', 'conn_id' => 3, 'rustdesk_id' => '811000011', 'from_peer' => '555000002', 'from_name' => 'unrelated-pc']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('users.show', $user->id))
        ->assertSee('Connections to their devices')
        ->assertSee('helper-pc')
        ->assertSee('Connections from devices they signed in on')
        ->assertSee('811000099')
        ->assertSee('anyone else using the same device shows here too')
        ->assertDontSee('unrelated-pc');
});

it('refuses a role without user read: the route bounces, the component 403s', function () {
    $target = udTarget();
    $role = Role::create(['name' => 'no-users', 'permissions' => ['device' => 'rw']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);

    // console-can bounces denied pages to the overview, same as /users.
    $this->actingAs($viewer)->get(route('users.show', $target->id))
        ->assertRedirect(route('overview'))->assertSessionHas('denied');
    // A plain user (no role) has no user access either.
    $this->actingAs(User::factory()->create())->get(route('users.show', $target->id))
        ->assertRedirect(route('overview'));

    Livewire::actingAs($viewer)->test(UserDetail::class, ['userId' => $target->id])->assertForbidden();
});

it('404s an unknown user', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/users/999999')->assertNotFound();
});

it('gives a user-only role the profile but withholds activity and scopes devices', function () {
    $target = udTarget();
    $folder = DeviceGroup::create(['name' => 'Branch']);
    Device::create(['rustdesk_id' => '811000020', 'uuid' => 'ud20', 'hostname' => 'IN-FOLDER', 'user_id' => $target->id, 'device_group_id' => $folder->id]);
    Device::create(['rustdesk_id' => '811000021', 'uuid' => 'ud21', 'hostname' => 'OUTSIDE', 'user_id' => $target->id]);
    LoginLog::create(['user_id' => $target->id, 'username' => 'jdoe', 'client' => 'web', 'ip' => '198.51.100.20', 'successful' => true]);

    $role = Role::create(['name' => 'user-viewer', 'permissions' => ['user' => 'r', 'device' => 'r']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $viewer->deviceGroups()->attach($folder);

    $this->actingAs($viewer)->get(route('users.show', $target->id))
        ->assertOk()
        ->assertSee('Jane Doe')
        ->assertSee('IN-FOLDER')
        ->assertDontSee('OUTSIDE')
        ->assertDontSee('198.51.100.20')
        ->assertDontSee('Last sign-in')
        ->assertSee('Activity history needs the audit permission');
});

it('links usernames in the users list to the detail page', function () {
    $user = udTarget();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('users'))
        ->assertOk()
        ->assertSee('href="'.route('users.show', $user->id).'"', false)
        ->assertSee('title="View details"', false);
});
