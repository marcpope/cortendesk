<?php

use App\Livewire\AddressBookManager;
use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Livewire\InvitationManager;
use App\Livewire\RoleList;
use App\Livewire\SettingsPage;
use App\Livewire\UserList;
use App\Models\AddressBook;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\Permissions;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| PLAN D4 — delegated admin roles
|--------------------------------------------------------------------------
|
| Two things matter here and they pull in opposite directions:
|
|  1. an install with no roles must behave EXACTLY as it did before, and
|  2. a role must never become a way to see more rows, or to hand out more
|     authority than its holder has.
|
| The first is covered by the back-compat block, the second by the scoping and
| escalation blocks. Everything else is plumbing.
*/

function roleAdmin(): User
{
    return User::factory()->admin()->create();
}

/** A non-admin with no role — the "legacy standard user" baseline. */
function rolelessUser(): User
{
    return User::factory()->create();
}

/**
 * @param  array<string,string>  $permissions
 */
function makeRole(array $permissions, string $name = 'Helpdesk', bool $requireTwoFactor = false): Role
{
    return Role::create([
        'name' => $name,
        'description' => 'test role',
        'permissions' => Role::normalizePermissions($permissions),
        'require_two_factor' => $requireTwoFactor,
    ]);
}

/**
 * @param  array<string,string>  $permissions
 */
function userWithRole(array $permissions, string $roleName = 'Helpdesk'): User
{
    return User::factory()->create(['role_id' => makeRole($permissions, $roleName)->id]);
}

function roleDevice(?DeviceGroup $group = null, ?User $owner = null): Device
{
    static $n = 0;
    $n++;

    return Device::create([
        'rustdesk_id' => "77000000$n",
        'uuid' => "role-u$n",
        'hostname' => "role-host$n",
        'device_group_id' => $group?->id,
        'user_id' => $owner?->id,
    ]);
}

// ----------------------------------------------------------- back-compat ----

it('leaves a user with no role exactly where they were before roles existed', function () {
    $user = rolelessUser();

    // Always reachable for a plain user.
    foreach (['devices', 'address-books', 'logs.connections', 'logs.file-transfers', 'logs.alarms'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }

    // Never reachable for a plain user.
    foreach (['users', 'groups', 'strategies', 'settings', 'logs.logins', 'logs.console', 'roles'] as $route) {
        $this->actingAs($user)->get(route($route))->assertRedirect(route('overview'));
    }
});

it('bounces a denied section to the overview with a flash, not a raw 403', function () {
    $this->actingAs(rolelessUser())
        ->get(route('users'))
        ->assertRedirect(route('overview'))
        ->assertSessionHas('denied');
});

it('lets an administrator reach every section regardless of role', function () {
    $admin = roleAdmin();
    // Even an admin who somehow carries a locked-down role is still a super-admin.
    $admin->update(['role_id' => makeRole([], 'Nothing')->id]);

    foreach ([
        'devices', 'address-books', 'users', 'groups', 'strategies', 'settings',
        'logs.connections', 'logs.file-transfers', 'logs.alarms', 'logs.logins', 'logs.console', 'roles',
    ] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});

it('reverts a user to standard access when their role is deleted', function () {
    $role = makeRole(['user' => 'rw', 'setting' => 'rw']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('users'))->assertOk();

    $role->delete();
    $user->refresh();

    expect($user->role_id)->toBeNull()
        ->and($user->consoleAllows('user'))->toBeFalse()
        ->and($user->consoleAllows('device', 'rw'))->toBeTrue(); // back to the legacy baseline

    $this->actingAs($user)->get(route('users'))->assertRedirect(route('overview'));
});

// -------------------------------------------------------- the role matrix ---

it('grants exactly the areas the matrix names', function () {
    $user = userWithRole(['user' => 'r', 'audit' => 'r']);

    $this->actingAs($user)->get(route('users'))->assertOk();
    $this->actingAs($user)->get(route('logs.connections'))->assertOk();
    // Not granted: devices and address books are NOT implied by a role.
    $this->actingAs($user)->get(route('devices'))->assertRedirect(route('overview'));
    $this->actingAs($user)->get(route('address-books'))->assertRedirect(route('overview'));
    // audit:r stops short of the login history and the console audit trail.
    $this->actingAs($user)->get(route('logs.logins'))->assertRedirect(route('overview'));
    $this->actingAs($user)->get(route('logs.console'))->assertRedirect(route('overview'));
});

it('opens the login history and console audit trail only at audit manage level', function () {
    $user = userWithRole(['audit' => 'rw']);

    $this->actingAs($user)->get(route('logs.logins'))->assertOk();
    $this->actingAs($user)->get(route('logs.console'))->assertOk();
});

it('fails closed on a resource the stored matrix never mentioned', function () {
    $role = Role::create([
        'name' => 'Legacy blob',
        'permissions' => ['device' => 'rw'], // written before the other areas existed
        'require_two_factor' => false,
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    expect($user->consoleAllows('device', 'rw'))->toBeTrue()
        ->and($user->consoleAllows('strategy'))->toBeFalse()
        ->and($user->consoleAllows('setting'))->toBeFalse()
        ->and($user->consoleAllows('token'))->toBeFalse();
});

it('separates view from manage inside a screen', function () {
    $viewer = userWithRole(['user' => 'r']);
    $target = rolelessUser();

    // The list loads…
    $this->actingAs($viewer)->get(route('users'))->assertOk();

    // …but every verb on it is refused.
    Livewire::actingAs($viewer)
        ->test(UserList::class)
        ->call('deleteUser', $target->id)
        ->assertForbidden();

    expect(User::find($target->id))->not->toBeNull();
});

it('refuses a view-only settings role any mutating settings action', function () {
    $viewer = userWithRole(['setting' => 'r']);

    $this->actingAs($viewer)->get(route('settings'))->assertOk();

    Livewire::actingAs($viewer)
        ->test(SettingsPage::class)
        ->set('onlineWindow', 111)
        ->call('save')
        ->assertForbidden();

    expect(Setting::get('online_window'))->not->toBe('111');
});

it('lets a manage-level settings role save', function () {
    $manager = userWithRole(['setting' => 'rw']);

    Livewire::actingAs($manager)
        ->test(SettingsPage::class)
        ->set('onlineWindow', 90)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('online_window'))->toBe('90');
});

// --------------------------------------------- roles never widen row scope ---

it('shows zero devices to a device-manage role with no device-group grants', function () {
    $group = DeviceGroup::create(['name' => 'Ops']);
    roleDevice($group);
    roleDevice(null);

    $user = userWithRole(['device' => 'rw']);

    Livewire::actingAs($user)
        ->test(DeviceList::class)
        ->assertViewHas('totalCount', 0);
});

it('still scopes a device-manage role to the groups it was granted', function () {
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $other = DeviceGroup::create(['name' => 'Other']);
    roleDevice($granted);
    roleDevice($other);

    $user = userWithRole(['device' => 'rw']);
    $user->deviceGroups()->sync([$granted->id]);

    Livewire::actingAs($user)
        ->test(DeviceList::class)
        ->assertViewHas('totalCount', 1);
});

it('does not let an address-book role reach books it was never shared', function () {
    $owner = rolelessUser();
    $shared = AddressBook::create([
        'name' => 'Ops book',
        'owner_user_id' => $owner->id,
        'is_personal' => false,
    ]);

    $user = userWithRole(['address_book' => 'rw']);

    $books = AddressBook::visibleTo($user)->pluck('id');

    expect($books)->not->toContain($shared->id);
});

it('clamps a view-only address-book role to read on a book it otherwise owns', function () {
    $user = userWithRole(['address_book' => 'r']);

    $component = Livewire::actingAs($user)->test(AddressBookManager::class);

    // The personal book is created on mount and is normally FULL for its owner;
    // the role narrows that to read, and the mutators refuse.
    expect($component->instance()->canManage())->toBeFalse()
        ->and($component->instance()->canWriteEntries())->toBeFalse();

    $component->call('openNewBook')->assertForbidden();

    expect(AddressBook::where('is_personal', false)->count())->toBe(0);
});

// ----------------------------------------------------- escalation guards ----

it('never lets a delegated user-manager mint an administrator', function () {
    $manager = userWithRole(['user' => 'rw']);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'sneaky')
        ->set('password', 'secret-password')
        ->set('is_admin', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(User::where('username', 'sneaky')->value('is_admin'))->toBeFalsy();
});

it('never lets a delegated user-manager hand out a role', function () {
    $manager = userWithRole(['user' => 'rw']);
    $powerful = makeRole(['setting' => 'rw', 'user' => 'rw'], 'Superuser');

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'sneaky2')
        ->set('password', 'secret-password')
        ->set('role_id', $powerful->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(User::where('username', 'sneaky2')->value('role_id'))->toBeNull();
});

it('never lets a delegated user-manager edit their own account', function () {
    $manager = userWithRole(['user' => 'rw']);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('edit', $manager->id)
        ->assertForbidden();
});

it('never lets a delegated user-manager touch an administrator', function () {
    $manager = userWithRole(['user' => 'rw']);
    $admin = roleAdmin();

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('deleteUser', $admin->id)
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

it('never lets a delegated user-manager touch another role holder', function () {
    $manager = userWithRole(['user' => 'rw'], 'Helpdesk');
    $peer = userWithRole(['user' => 'r'], 'Auditor');

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('edit', $peer->id)
        ->assertForbidden();
});

it('clamps device-group grants to what the delegated user-manager can see', function () {
    $mine = DeviceGroup::create(['name' => 'Mine']);
    $theirs = DeviceGroup::create(['name' => 'Theirs']);

    $manager = userWithRole(['user' => 'rw', 'device' => 'rw']);
    $manager->deviceGroups()->sync([$mine->id]);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'newbie')
        ->set('password', 'secret-password')
        ->set('device_group_ids', [$mine->id, $theirs->id])
        ->call('save')
        ->assertHasNoErrors();

    $newbie = User::where('username', 'newbie')->first();

    expect($newbie->deviceGroups()->pluck('device_groups.id')->all())->toBe([$mine->id]);
});

it('never lets a delegated user-manager invite an administrator', function () {
    $manager = userWithRole(['user' => 'rw']);
    $offLimits = DeviceGroup::create(['name' => 'Off limits']);

    Livewire::actingAs($manager)
        ->test(InvitationManager::class)
        ->call('create')
        ->set('email', 'sneaky@example.test')
        ->set('username', 'sneaky-invite')
        ->set('is_admin', true)
        ->set('device_group_ids', [$offLimits->id])
        ->call('save')
        ->assertHasNoErrors();

    $invitation = Invitation::where('username', 'sneaky-invite')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->is_admin)->toBeFalsy()
        ->and($invitation->device_group_ids)->toBe([]);
});

it('never lets a delegated user-manager grant a user group that carries folders they cannot see', function () {
    // The device-group clamp above is worthless on its own: user groups carry
    // folder grants too, so "add them to Finance staff" reaches the same place.
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $staff = UserGroup::create(['name' => 'Finance staff']);
    $staff->deviceGroups()->attach($finance);
    $harmless = UserGroup::create(['name' => 'Everyone']);

    $manager = userWithRole(['user' => 'rw']);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'puppet')
        ->set('password', 'secret-password')
        ->set('user_group_ids', [$staff->id, $harmless->id])
        ->call('save')
        ->assertHasNoErrors();

    $puppet = User::where('username', 'puppet')->first();

    expect($puppet->userGroupIds())->toBe([$harmless->id])
        ->and($puppet->accessibleDeviceGroupIds())->toBe([]);
});

it('leaves a grant it cannot see in place when a delegated user-manager saves', function () {
    // The out-of-reach rows are not in the editor, so saving the editor must
    // neither hand them out nor silently strip them.
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $ours = DeviceGroup::create(['name' => 'Ours']);
    $staff = UserGroup::create(['name' => 'Finance staff']);
    $staff->deviceGroups()->attach($finance);

    $target = rolelessUser();
    $target->deviceGroups()->attach($finance);
    $target->groups()->attach($staff);

    $manager = userWithRole(['user' => 'rw']);
    $manager->deviceGroups()->sync([$ours->id]);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('edit', $target->id)
        ->set('device_group_ids', [$ours->id])
        ->set('user_group_ids', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->deviceGroups()->pluck('device_groups.id')->all())
        ->toEqualCanonicalizing([$finance->id, $ours->id])
        ->and($target->fresh()->userGroupIds())->toBe([$staff->id]);
});

// --------------------------------------------- assign devices (ownership) ---

it('never shows a delegated user-manager a device outside their scope in the assign modal', function () {
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $hidden = roleDevice($finance);

    $manager = userWithRole(['user' => 'rw']);
    $victim = rolelessUser();

    expect(Device::visibleTo($manager)->count())->toBe(0);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('openAssign', $victim->id)
        ->assertDontSee($hidden->rustdesk_id)
        ->assertDontSee($hidden->hostname);
});

it('never lets a delegated user-manager re-own a device they cannot see', function () {
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $hidden = roleDevice($finance);

    $manager = userWithRole(['user' => 'rw']);
    $victim = rolelessUser(); // an account whose password the manager can set

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('openAssign', $victim->id)
        ->set('assignDeviceIds', [$hidden->id])
        ->call('saveAssign');

    expect($hidden->fresh()->user_id)->toBeNull()
        ->and(Device::visibleTo($victim->fresh())->count())->toBe(0);
});

it('never orphans a device the delegated user-manager cannot see', function () {
    // saveAssign releases everything the target owns that was not ticked. Scoped
    // wrongly, opening and saving the modal would detach the whole fleet.
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $victim = rolelessUser();
    $hidden = roleDevice($finance, $victim);
    $ours = DeviceGroup::create(['name' => 'Ours']);
    $mine = roleDevice($ours, $victim);

    $manager = userWithRole(['user' => 'rw']);
    $manager->deviceGroups()->sync([$ours->id]);

    Livewire::actingAs($manager)
        ->test(UserList::class)
        ->call('openAssign', $victim->id)
        ->set('assignDeviceIds', [])
        ->call('saveAssign');

    expect($hidden->fresh()->user_id)->toBe($victim->id)  // untouched
        ->and($mine->fresh()->user_id)->toBeNull();       // released, as asked
});

it('still lets an administrator assign any device', function () {
    $device = roleDevice(DeviceGroup::create(['name' => 'Anywhere']));
    $target = rolelessUser();

    Livewire::actingAs(roleAdmin())
        ->test(UserList::class)
        ->call('openAssign', $target->id)
        ->set('assignDeviceIds', [$device->id])
        ->call('saveAssign');

    expect($device->fresh()->user_id)->toBe($target->id);
});

// ---------------------------------------------------- invitations (D1+D4) ---

it('never lets a delegated user-manager resend or revoke an administrator invitation', function () {
    // resend() re-mints the token and prints the accept URL on the actor's own
    // screen, so an unguarded resend IS a promotion to administrator.
    $admin = roleAdmin();
    [$invitation] = Invitation::issue([
        'email' => 'newadmin@example.test',
        'username' => 'newadmin',
        'is_admin' => true,
    ], $admin);
    $hashBefore = $invitation->token_hash;

    $manager = userWithRole(['user' => 'rw']);

    Livewire::actingAs($manager)
        ->test(InvitationManager::class)
        ->call('resend', $invitation->id)
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test(InvitationManager::class)
        ->call('revoke', $invitation->id)
        ->assertForbidden();

    expect($invitation->fresh()->token_hash)->toBe($hashBefore)
        ->and(Invitation::whereKey($invitation->id)->exists())->toBeTrue();

    // …and the row offers no action links to them either.
    Livewire::actingAs($manager)
        ->test(InvitationManager::class)
        ->assertSee('newadmin@example.test')
        ->assertDontSee('Resend');
});

it('never lets a delegated user-manager resend an invitation that pre-grants a folder they cannot see', function () {
    $admin = roleAdmin();
    $finance = DeviceGroup::create(['name' => 'Finance']);
    [$invitation] = Invitation::issue([
        'email' => 'contractor@example.test',
        'username' => 'contractor',
        'device_group_ids' => [$finance->id],
    ], $admin);

    Livewire::actingAs(userWithRole(['user' => 'rw']))
        ->test(InvitationManager::class)
        ->call('resend', $invitation->id)
        ->assertForbidden();
});

it('lets a delegated user-manager resend an ordinary invitation', function () {
    $admin = roleAdmin();
    [$invitation] = Invitation::issue([
        'email' => 'ordinary@example.test',
        'username' => 'ordinary',
    ], $admin);
    $hashBefore = $invitation->token_hash;

    Livewire::actingAs(userWithRole(['user' => 'rw']))
        ->test(InvitationManager::class)
        ->call('resend', $invitation->id)
        ->assertHasNoErrors();

    expect($invitation->fresh()->token_hash)->not->toBe($hashBefore);
});

it('never lets a delegated user-manager invite into a user group that carries folders they cannot see', function () {
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $staff = UserGroup::create(['name' => 'Finance staff']);
    $staff->deviceGroups()->attach($finance);

    Livewire::actingAs(userWithRole(['user' => 'rw']))
        ->test(InvitationManager::class)
        ->call('create')
        ->set('email', 'sneaky-groups@example.test')
        ->set('username', 'sneaky-groups')
        ->set('user_group_ids', [$staff->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Invitation::where('username', 'sneaky-groups')->value('user_group_ids'))->toBe([]);
});

// ---------------------------------------------------------- groups (D4) -----

it('never lets a delegated group-manager grant a folder they cannot see to a user group', function () {
    // Membership of the group being edited turns this straight into fleet-wide
    // visibility for the actor themselves.
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $device = roleDevice($finance);
    $support = UserGroup::create(['name' => 'Support']);

    $manager = userWithRole(['group' => 'rw', 'device' => 'rw']);
    $support->users()->attach($manager);

    expect(Device::visibleTo($manager)->count())->toBe(0);

    Livewire::actingAs($manager)
        ->test(GroupList::class)
        ->call('edit', 'users', $support->id)
        ->set('device_group_ids', [$finance->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($support->fresh()->deviceGroups()->count())->toBe(0)
        ->and(Device::visibleTo($manager->fresh())->count())->toBe(0);
});

it('never lets a delegated group-manager add an accessor to a folder they cannot see', function () {
    // The same grant from the other side: group_accesses rows are unioned into
    // User::accessibleDeviceGroupIds exactly like the folder picker.
    $finance = DeviceGroup::create(['name' => 'Finance']);
    roleDevice($finance);
    $support = UserGroup::create(['name' => 'Support']);

    $manager = userWithRole(['group' => 'rw', 'device' => 'rw']);
    $support->users()->attach($manager);

    Livewire::actingAs($manager)
        ->test(GroupList::class)
        ->call('edit', 'devices', $finance->id)
        ->set('accessor_group_ids', [$support->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($finance->fresh()->accessorUserGroupIds())->toBe([])
        ->and(Device::visibleTo($manager->fresh())->count())->toBe(0);
});

it('lets a delegated group-manager pass on a folder they do hold', function () {
    $ours = DeviceGroup::create(['name' => 'Ours']);
    $support = UserGroup::create(['name' => 'Support']);

    $manager = userWithRole(['group' => 'rw']);
    $manager->deviceGroups()->sync([$ours->id]);

    Livewire::actingAs($manager)
        ->test(GroupList::class)
        ->call('edit', 'users', $support->id)
        ->set('device_group_ids', [$ours->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($support->fresh()->deviceGroups()->pluck('device_groups.id')->all())->toBe([$ours->id]);
});

it('leaves a folder grant it cannot see alone when a delegated group-manager saves', function () {
    $finance = DeviceGroup::create(['name' => 'Finance']);
    $ours = DeviceGroup::create(['name' => 'Ours']);
    $support = UserGroup::create(['name' => 'Support']);
    $support->deviceGroups()->attach($finance);

    $manager = userWithRole(['group' => 'rw']);
    $manager->deviceGroups()->sync([$ours->id]);

    Livewire::actingAs($manager)
        ->test(GroupList::class)
        ->call('edit', 'users', $support->id)
        ->set('device_group_ids', [$ours->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($support->fresh()->deviceGroups()->pluck('device_groups.id')->all())
        ->toEqualCanonicalizing([$finance->id, $ours->id]);
});

it('still lets an administrator grant admin, roles and any device group', function () {
    $admin = roleAdmin();
    $group = DeviceGroup::create(['name' => 'Anything']);
    $role = makeRole(['user' => 'rw'], 'Delegate');

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'delegate')
        ->set('password', 'secret-password')
        ->set('role_id', $role->id)
        ->set('device_group_ids', [$group->id])
        ->call('save')
        ->assertHasNoErrors();

    $created = User::where('username', 'delegate')->first();

    expect($created->role_id)->toBe($role->id)
        ->and($created->deviceGroups()->pluck('device_groups.id')->all())->toBe([$group->id]);

    // The role grant is traceable in the console audit trail.
    expect(ConsoleAudit::where('action', 'user.create')
        ->where('summary', 'like', '%Delegate%')->exists())->toBeTrue();
});

it('drops a stale role when a user is promoted to administrator', function () {
    $admin = roleAdmin();
    $role = makeRole(['user' => 'rw'], 'Delegate');
    $user = User::factory()->create(['role_id' => $role->id]);

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('edit', $user->id)
        ->set('is_admin', true)
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->is_admin)->toBeTrue()->and($user->role_id)->toBeNull();
});

// ---------------------------------------------------------- roles screen ----

it('keeps the roles screen to super-admins even for a user-manage role', function () {
    $manager = userWithRole(['user' => 'rw', 'setting' => 'rw']);

    $this->actingAs($manager)->get(route('roles'))->assertRedirect(route('overview'));

    // The route only guards the page; /livewire/update is reachable on its own.
    Livewire::actingAs($manager)->test(RoleList::class)->assertForbidden();
});

it('creates, edits and deletes a role with an audit entry each time', function () {
    $admin = roleAdmin();

    Livewire::actingAs($admin)
        ->test(RoleList::class)
        ->call('create')
        ->set('name', 'Helpdesk')
        ->set('description', 'Front line')
        ->set('permissions.device', 'rw')
        ->set('permissions.user', 'r')
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::where('name', 'Helpdesk')->first();

    expect($role)->not->toBeNull()
        ->and($role->levelFor('device'))->toBe('rw')
        ->and($role->levelFor('user'))->toBe('r')
        // Every known area is materialised, so nothing is left ambiguous.
        ->and(array_keys($role->permissions))->toEqualCanonicalizing(Permissions::CONSOLE_RESOURCES); // MySQL JSON reorders keys

    Livewire::actingAs($admin)
        ->test(RoleList::class)
        ->call('edit', $role->id)
        ->set('permissions.setting', 'rw')
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->levelFor('setting'))->toBe('rw');

    Livewire::actingAs($admin)
        ->test(RoleList::class)
        ->call('deleteRole', $role->id);

    expect(Role::find($role->id))->toBeNull();

    foreach (['role.create', 'role.update', 'role.delete'] as $action) {
        expect(ConsoleAudit::where('action', $action)->exists())->toBeTrue();
    }
});

it('rejects a duplicate role name', function () {
    $admin = roleAdmin();
    makeRole(['device' => 'r'], 'Helpdesk');

    Livewire::actingAs($admin)
        ->test(RoleList::class)
        ->call('create')
        ->set('name', 'Helpdesk')
        ->call('save')
        ->assertHasErrors('name');
});

// ------------------------------------------------------------- 2FA + API ----

it('enforces two-factor enrollment for a role that requires it', function () {
    $user = User::factory()->create([
        'role_id' => makeRole(['device' => 'rw'], 'Secured', requireTwoFactor: true)->id,
    ]);

    $this->actingAs($user)
        ->get(route('devices'))
        ->assertRedirect(route('account.two-factor'));

    // A roleless user on the same install is untouched.
    $this->actingAs(rolelessUser())->get(route('devices'))->assertOk();
});

it('clamps a new API token to the levels its creator actually holds', function () {
    $manager = userWithRole(['token' => 'rw', 'device' => 'r', 'user' => 'none']);

    [$token] = ApiToken::issue($manager, 'Scripted', [
        'device' => 'rw',   // above the creator's own level
        'user' => 'rw',     // creator has none at all
        'audit' => 'r',     // legacy baseline is none for a role holder
    ]);

    expect($token->levelFor('device'))->toBe('r')
        ->and($token->levelFor('user'))->toBe('none')
        ->and($token->levelFor('audit'))->toBe('none');
});

it('leaves an administrator-issued token unclamped', function () {
    $admin = roleAdmin();

    [$token] = ApiToken::issue($admin, 'CI', ['device' => 'rw', 'user' => 'rw']);

    expect($token->levelFor('device'))->toBe('rw')
        ->and($token->levelFor('user'))->toBe('rw');
});

// ------------------------------------------------------------- sidebar ------

it('hides unpermitted sections from the sidebar', function () {
    $user = userWithRole(['user' => 'r', 'audit' => 'r']);

    $html = $this->actingAs($user)->get(route('users'))->assertOk()->getContent();

    expect($html)->toContain(route('users'))
        ->toContain(route('logs.connections'))
        // audit:r stops short of the sensitive log screens
        ->not->toContain(route('logs.logins'))
        ->not->toContain(route('logs.console'))
        // never granted at all
        ->not->toContain(route('settings'))
        ->not->toContain(route('roles'))
        ->not->toContain(route('groups'));
});

it('shows the roles entry only to a super-admin', function () {
    $html = $this->actingAs(roleAdmin())->get(route('overview'))->assertOk()->getContent();
    expect($html)->toContain(route('roles'));

    $html = $this->actingAs(rolelessUser())->get(route('overview'))->assertOk()->getContent();
    expect($html)->not->toContain(route('roles'));
});

it('labels a role holder with their role name in the topbar', function () {
    $user = userWithRole(['device' => 'r'], 'Helpdesk');

    $this->actingAs($user)->get(route('overview'))->assertOk()->assertSee('Helpdesk');
});
