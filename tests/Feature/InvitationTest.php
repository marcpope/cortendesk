<?php

use App\Livewire\InvitationManager;
use App\Mail\UserInvitation;
use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\Invitation;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** An admin who can invite, with a working relay configured. */
function invitingAdmin(): User
{
    foreach ([
        'smtp_enabled' => '1',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_from_address' => 'console@example.com',
        'smtp_password' => Crypt::encryptString('relay-pass'),
    ] as $key => $value) {
        Setting::put($key, $value);
    }

    $admin = User::create([
        'username' => 'inviter',
        'email' => 'inviter@example.com',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($admin);

    return $admin;
}

/** Create an invitation directly and return [model, plaintext token]. */
function seedInvitation(User $inviter, array $attributes = []): array
{
    return Invitation::issue(array_merge([
        'email' => 'newbie@example.com',
        'username' => 'newbie',
        'name' => 'New Bie',
        'is_admin' => false,
        'user_group_ids' => [],
        'device_group_ids' => [],
    ], $attributes), $inviter);
}

// --- Creating ---------------------------------------------------------------

it('emails an invitation and stores only the token hash', function () {
    Mail::fake();
    invitingAdmin();

    Livewire::test(InvitationManager::class)
        ->call('create')
        ->set('email', 'newbie@example.com')
        ->set('username', 'newbie')
        ->set('name', 'New Bie')
        ->call('save')
        ->assertHasNoErrors();

    $invitation = Invitation::first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->email)->toBe('newbie@example.com')
        ->and($invitation->is_admin)->toBeFalse()
        ->and(strlen($invitation->token_hash))->toBe(64)
        ->and($invitation->expires_at->diffInHours(now()->addHours(48)))->toBeLessThan(1);

    Mail::assertSent(UserInvitation::class, fn ($mail) => $mail->hasTo('newbie@example.com'));
    expect(ConsoleAudit::where('action', 'user.invite')->exists())->toBeTrue();
});

it('surfaces the accept link even when no email could be sent', function () {
    Mail::fake();
    $admin = invitingAdmin();
    Setting::put('smtp_enabled', '0'); // no relay at all

    $component = Livewire::test(InvitationManager::class)
        ->set('email', 'nomail@example.com')
        ->set('username', 'nomail')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('mailSent', false);

    expect($component->get('inviteUrl'))->toContain('/invite/inv_');
    Mail::assertNothingSent();
});

it('rejects an address that already has an account or a live invitation', function () {
    Mail::fake();
    $admin = invitingAdmin();
    seedInvitation($admin);

    Livewire::test(InvitationManager::class)
        ->set('email', 'newbie@example.com')
        ->set('username', 'someone-else')
        ->call('save')
        ->assertHasErrors('email');

    Livewire::test(InvitationManager::class)
        ->set('email', 'inviter@example.com')
        ->set('username', 'another')
        ->call('save')
        ->assertHasErrors('email');
});

it('rejects a username already taken by a user or a live invitation', function () {
    Mail::fake();
    $admin = invitingAdmin();
    seedInvitation($admin);

    Livewire::test(InvitationManager::class)
        ->set('email', 'other@example.com')
        ->set('username', 'newbie')
        ->call('save')
        ->assertHasErrors('username');

    Livewire::test(InvitationManager::class)
        ->set('email', 'other@example.com')
        ->set('username', 'inviter')
        ->call('save')
        ->assertHasErrors('username');
});

it('drops device-group grants from an administrator invitation', function () {
    Mail::fake();
    $admin = invitingAdmin();
    $group = DeviceGroup::create(['name' => 'Servers']);

    Livewire::test(InvitationManager::class)
        ->set('email', 'boss@example.com')
        ->set('username', 'boss')
        ->set('is_admin', true)
        ->set('device_group_ids', [$group->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Invitation::first()->device_group_ids)->toBe([]);
});

it('keeps the invitation screen away from non-admins', function () {
    Livewire::actingAs(User::factory()->create(['is_admin' => false]))
        ->test(InvitationManager::class)
        ->assertForbidden();
});

// --- Resend / revoke --------------------------------------------------------

it('rotates the token on resend so the old link stops working', function () {
    Mail::fake();
    $admin = invitingAdmin();
    [$invitation, $plain] = seedInvitation($admin);

    Livewire::test(InvitationManager::class)->call('resend', $invitation->id);

    expect(Invitation::findValid($plain))->toBeNull();
    Mail::assertSent(UserInvitation::class);
    expect(ConsoleAudit::where('action', 'user.invite-resend')->exists())->toBeTrue();
});

it('revokes an invitation', function () {
    Mail::fake();
    $admin = invitingAdmin();
    [$invitation, $plain] = seedInvitation($admin);

    Livewire::test(InvitationManager::class)->call('revoke', $invitation->id);

    expect(Invitation::count())->toBe(0);

    auth()->logout(); // the accept routes are guest-only
    test()->get(route('invite.show', $plain))->assertNotFound();
    expect(ConsoleAudit::where('action', 'user.invite-revoke')->exists())->toBeTrue();
});

// --- Accepting --------------------------------------------------------------

it('shows the acceptance form for a live token', function () {
    $admin = invitingAdmin();
    [$invitation, $plain] = seedInvitation($admin);
    auth()->logout();

    test()->get(route('invite.show', $plain))
        ->assertOk()
        ->assertSee('newbie')
        ->assertSee('Set your password');
});

it('creates the account, signs the invitee in and logs it', function () {
    $admin = invitingAdmin();
    $userGroup = UserGroup::create(['name' => 'Support']);
    $deviceGroup = DeviceGroup::create(['name' => 'Servers']);
    [$invitation, $plain] = seedInvitation($admin, [
        'user_group_ids' => [$userGroup->id],
        'device_group_ids' => [$deviceGroup->id],
    ]);
    auth()->logout();

    test()->post(route('invite.accept', $plain), [
        'name' => 'New Bie',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect(route('overview'));

    $user = User::where('username', 'newbie')->first();

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('newbie@example.com')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->groups()->pluck('user_groups.id')->all())->toBe([$userGroup->id])
        ->and($user->deviceGroups()->pluck('device_groups.id')->all())->toBe([$deviceGroup->id]);

    test()->assertAuthenticatedAs($user);

    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_user_id)->toBe($user->id)
        ->and(LoginLog::where('note', 'invitation')->exists())->toBeTrue()
        ->and(ConsoleAudit::where('action', 'user.invite-accept')->exists())->toBeTrue();
});

it('carries the administrator flag the inviter chose', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin, ['username' => 'boss', 'email' => 'boss@example.com', 'is_admin' => true]);
    auth()->logout();

    test()->post(route('invite.accept', $plain), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ]);

    expect(User::where('username', 'boss')->first()->is_admin)->toBeTrue();
});

it('ignores privileges injected into the acceptance request', function () {
    $admin = invitingAdmin();
    $invited = UserGroup::create(['name' => 'Support']);
    $sneaky = UserGroup::create(['name' => 'Executives']);
    $devices = DeviceGroup::create(['name' => 'Servers']);
    [, $plain] = seedInvitation($admin, ['user_group_ids' => [$invited->id]]);
    auth()->logout();

    test()->post(route('invite.accept', $plain), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        // Everything below is attacker-controlled and must be ignored.
        'is_admin' => 1,
        'is_active' => 1,
        'username' => 'root',
        'email' => 'attacker@example.com',
        'user_group_ids' => [$sneaky->id],
        'device_group_ids' => [$devices->id],
    ])->assertRedirect(route('overview'));

    $user = User::where('username', 'newbie')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->email)->toBe('newbie@example.com')
        ->and($user->groups()->pluck('user_groups.id')->all())->toBe([$invited->id])
        ->and($user->deviceGroups()->count())->toBe(0)
        ->and(User::where('username', 'root')->exists())->toBeFalse();
});

it('refuses a reused invitation', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin);
    auth()->logout();

    test()->post(route('invite.accept', $plain), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect(route('overview'));

    auth()->logout();

    test()->post(route('invite.accept', $plain), [
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertNotFound();

    expect(User::where('username', 'newbie')->count())->toBe(1);
});

it('refuses an expired invitation', function () {
    $admin = invitingAdmin();
    [$invitation, $plain] = seedInvitation($admin);
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    auth()->logout();

    test()->get(route('invite.show', $plain))->assertNotFound();
    test()->post(route('invite.accept', $plain), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertNotFound();

    expect(User::where('username', 'newbie')->exists())->toBeFalse();
});

it('refuses an unknown token', function () {
    test()->get(route('invite.show', 'inv_nonsense'))->assertNotFound();
});

it('refuses an invitation whose inviter is no longer an active admin', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin);
    auth()->logout();

    $admin->update(['is_admin' => false]);
    test()->get(route('invite.show', $plain))->assertForbidden();

    $admin->update(['is_admin' => true, 'is_active' => false]);
    test()->get(route('invite.show', $plain))->assertForbidden();

    $admin->update(['is_active' => true]);
    test()->get(route('invite.show', $plain))->assertOk();
});

/*
 * D1 + D4 interaction: inviting is gated on `user: rw`, not on is_admin, so a
 * delegated user-manager can onboard people. Acceptance has to test the same
 * authority the invitation needs, or every invitation they send is dead on
 * arrival with no sign of it on the console side.
 */
it('honours an invitation issued by a delegated user-manager', function () {
    $role = Role::create([
        'name' => 'Helpdesk',
        'permissions' => Role::normalizePermissions(['user' => 'rw']),
        'require_two_factor' => false,
    ]);
    $manager = User::factory()->create(['role_id' => $role->id]);

    [, $plain] = seedInvitation($manager);

    test()->get(route('invite.show', $plain))->assertOk();

    test()->post(route('invite.accept', $plain), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect(route('overview'));

    $created = User::where('username', 'newbie')->first();

    expect($created)->not->toBeNull()
        ->and($created->is_admin)->toBeFalse();
});

it('voids an invitation when its issuer loses the authority behind it', function () {
    $role = Role::create([
        'name' => 'Helpdesk',
        'permissions' => Role::normalizePermissions(['user' => 'rw']),
        'require_two_factor' => false,
    ]);
    $manager = User::factory()->create(['role_id' => $role->id]);
    [, $plain] = seedInvitation($manager);

    // Demoted to a role that can no longer manage users: the pending invitation
    // dies with the authority that issued it.
    $manager->update(['role_id' => Role::create([
        'name' => 'Viewer',
        'permissions' => Role::normalizePermissions(['user' => 'r']),
        'require_two_factor' => false,
    ])->id]);

    test()->get(route('invite.show', $plain))->assertForbidden();
});

it('still requires a super-admin behind an administrator invitation', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin, ['is_admin' => true]);
    auth()->logout();

    // Only the admin flag goes away; the account keeps full user management.
    $admin->update([
        'is_admin' => false,
        'role_id' => Role::create([
            'name' => 'User manager',
            'permissions' => Role::normalizePermissions(['user' => 'rw']),
            'require_two_factor' => false,
        ])->id,
    ]);

    test()->get(route('invite.show', $plain))->assertForbidden();

    expect(User::where('username', 'newbie')->exists())->toBeFalse();
});

it('rejects a short or mismatched password', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin);
    auth()->logout();

    test()->from(route('invite.show', $plain))
        ->post(route('invite.accept', $plain), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

    test()->from(route('invite.show', $plain))
        ->post(route('invite.accept', $plain), [
            'password' => 'a-good-password',
            'password_confirmation' => 'a-different-password',
        ])->assertSessionHasErrors('password');

    expect(User::where('username', 'newbie')->exists())->toBeFalse();
});

it('refuses when the username was claimed while the invite was pending', function () {
    $admin = invitingAdmin();
    [, $plain] = seedInvitation($admin);
    User::create(['username' => 'newbie', 'password' => 'secret-password']);
    auth()->logout();

    test()->from(route('invite.show', $plain))
        ->post(route('invite.accept', $plain), [
            'password' => 'a-good-password',
            'password_confirmation' => 'a-good-password',
        ])->assertSessionHasErrors('password');

    expect(User::where('username', 'newbie')->count())->toBe(1);
});

// --- Housekeeping -----------------------------------------------------------

it('prunes expired invitations without touching live ones', function () {
    $admin = invitingAdmin();
    [$live] = seedInvitation($admin);
    [$dead] = seedInvitation($admin, ['email' => 'old@example.com', 'username' => 'old']);
    $dead->forceFill(['expires_at' => now()->subDay()])->save();

    test()->artisan('cortendesk:prune-invitations')->assertSuccessful();

    expect(Invitation::whereKey($live->id)->exists())->toBeTrue()
        ->and(Invitation::whereKey($dead->id)->exists())->toBeFalse();
});

it('shows the invitations card on the Users screen', function () {
    invitingAdmin();

    test()->get(route('users'))
        ->assertOk()
        ->assertSeeLivewire(InvitationManager::class)
        ->assertSee('Invite User');
});
