<?php

use App\Livewire\AccountProfile;
use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| My Account (PLAN A6)
|--------------------------------------------------------------------------
| The screen every user needs and nobody could reach: before this, the topbar
| link was a dead theme placeholder and 2FA enrollment was linked from nowhere.
*/

// --- Reachability ----------------------------------------------------------

it('is reachable by a regular non-admin user', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('account'))
        ->assertOk()
        ->assertSee('My Account');
});

it('is reachable by an administrator too', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)->get(route('account'))->assertOk();
});

it('requires signing in', function () {
    $this->get(route('account'))->assertRedirect(route('login'));
});

it('links to the account screen from the topbar', function () {
    $user = User::factory()->create(['is_admin' => false]);

    // The regression that started this: href="javascript:void(0);".
    $this->actingAs($user)->get(route('overview'))
        ->assertSee(route('account'), false)
        ->assertDontSee('javascript:void(0);', false);
});

it('offers two-factor enrollment on the account screen', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('account'))
        ->assertSee('Two-Factor');
});

it('keeps the dedicated two-factor page working', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('account.two-factor'))->assertOk();
});

// --- Profile ---------------------------------------------------------------

it('saves the display name and email', function () {
    $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('profileSaved', true);

    $user->refresh();

    expect($user->name)->toBe('New Name')->and($user->email)->toBe('new@example.com');
});

it('rejects an email already used by someone else', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('email', 'taken@example.com')
        ->call('saveProfile')
        ->assertHasErrors('email');

    expect($user->fresh()->email)->toBe('mine@example.com');
});

it('accepts the user keeping their own email', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('name', 'Renamed')
        ->call('saveProfile')
        ->assertHasNoErrors();
});

it('rejects a malformed email', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('email', 'not-an-email')
        ->call('saveProfile')
        ->assertHasErrors('email');
});

it('lets the user clear their email', function () {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('email', '')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBeNull();
});

it('cannot be used to grant itself admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    // The component holds no privilege fields at all — setting one must not
    // silently bind onto the model.
    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('name', 'Escalate')
        ->call('saveProfile');

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('audits a profile change', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('name', 'Audited')
        ->call('saveProfile');

    expect(ConsoleAudit::query()->where('action', 'account.update')->exists())->toBeTrue();
});

// --- Password --------------------------------------------------------------

it('changes the password when the current one is given', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'old-password-123')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'brand-new-password')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('passwordSaved', true);

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('refuses a password change without the current password', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'wrong-password')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'brand-new-password')
        ->call('updatePassword')
        ->assertHasErrors('currentPassword');

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('requires the confirmation to match', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'old-password-123')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'something-else')
        ->call('updatePassword')
        ->assertHasErrors('password');

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('enforces a minimum password length', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'old-password-123')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('updatePassword')
        ->assertHasErrors('password');
});

it('clears the password fields after a change', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'old-password-123')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'brand-new-password')
        ->call('updatePassword')
        ->assertSet('currentPassword', '')
        ->assertSet('password', '');
});

it('audits a password change', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'old-password-123')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'brand-new-password')
        ->call('updatePassword');

    expect(ConsoleAudit::query()->where('action', 'account.password')->exists())->toBeTrue();
});

// --- SSO accounts ----------------------------------------------------------

it('offers no password form to an SSO-provisioned account', function () {
    $user = User::factory()->create();
    $user->forceFill(['auth_provider' => 'oidc'])->save();

    $this->actingAs($user)->get(route('account'))
        ->assertSee('signs in through single sign-on')
        ->assertDontSee('Change Password');
});

it('refuses a password change on an SSO-provisioned account', function () {
    $user = User::factory()->create(['password' => 'known-password-1']);
    $user->forceFill(['auth_provider' => 'oidc'])->save();

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'known-password-1')
        ->set('password', 'brand-new-password')
        ->set('passwordConfirmation', 'brand-new-password')
        ->call('updatePassword')
        ->assertHasErrors('currentPassword');

    expect(Hash::check('known-password-1', $user->fresh()->password))->toBeTrue();
});

it('keeps the password form for a linked local account', function () {
    // Linked, not provisioned: the local password still works and is still
    // the way back in if the provider is down.
    $user = User::factory()->create();
    $user->forceFill(['oidc_iss' => 'https://idp.test', 'oidc_sub' => 'abc'])->save();

    $this->actingAs($user)->get(route('account'))->assertSee('Change Password');
});

it('warns a linked account that directory claims may overwrite edits', function () {
    $user = User::factory()->create();
    $user->forceFill(['oidc_iss' => 'https://idp.test', 'oidc_sub' => 'abc'])->save();

    $this->actingAs($user)->get(route('account'))
        ->assertSee('may be overwritten');
});

// --- Interaction with enforced 2FA -----------------------------------------

it('sends an un-enrolled user to enrollment when 2FA is required', function () {
    Setting::put('two_factor_required', '1');
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('account'))
        ->assertRedirect(route('account.two-factor'));
});

// --- Email address enforcement (PLAN D1, BBS force-2FA pattern) -------------

it('sends a user with no email to the account screen when verification is on', function () {
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');
    Setting::put('email_login_verification', '1');

    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)->get(route('devices'))
        ->assertRedirect(route('account'));
});

it('lets that user reach the account screen and logout', function () {
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');
    Setting::put('email_login_verification', '1');

    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)->get(route('account'))->assertOk();
});

it('stops redirecting once an address is set', function () {
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');
    Setting::put('email_login_verification', '1');

    $user = User::factory()->create(['email' => 'someone@example.com']);

    $this->actingAs($user)->get(route('devices'))->assertOk();
});

it('does not redirect when verification is off', function () {
    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)->get(route('devices'))->assertOk();
});

it('exempts SSO sessions from the email requirement', function () {
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');
    Setting::put('email_login_verification', '1');

    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)
        ->withSession([\App\Services\OidcService::SESSION_PROVIDER => true])
        ->get(route('devices'))->assertOk();
});

it('nudges a user with no address even when verification is off', function () {
    $user = User::factory()->create(['email' => null]);

    $this->actingAs($user)->get(route('account'))
        ->assertSee('no email address');
});
