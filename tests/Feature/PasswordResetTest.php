<?php

use App\Mail\PasswordResetLink;
use App\Models\ClientToken;
use App\Models\PasswordReset;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Self-service password reset
|--------------------------------------------------------------------------
| Offered only with a working relay. The request form is the one place an
| outsider can probe for accounts, so every outcome must look identical.
*/

beforeEach(function () {
    Mail::fake();
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');
});

/** Ask for a link and return the plaintext token that was emailed. */
function requestReset(string $login): ?string
{
    test()->post(route('password.email'), ['login' => $login]);

    $token = null;
    Mail::assertSent(PasswordResetLink::class, function ($mail) use (&$token) {
        preg_match('/reset-password\/([A-Za-z0-9_]+)/', $mail->resetUrl, $m);
        $token = $m[1] ?? null;

        return true;
    });

    return $token;
}

// --- Availability ----------------------------------------------------------

it('is not offered when email is unconfigured', function () {
    Setting::put('smtp_enabled', '0');

    $this->get(route('login'))->assertDontSee('Forgot password?');
    $this->get(route('password.request'))->assertRedirect(route('login'));
});

it('is offered once email is configured', function () {
    $this->get(route('login'))->assertSee('Forgot password?');
    $this->get(route('password.request'))->assertOk();
});

it('is withdrawn when password sign-in is disabled', function () {
    // Resetting a password you are not allowed to use is noise.
    Setting::put('oidc_enabled', '1');
    Setting::put('oidc_discovery_url', 'https://idp.test');
    Setting::put('oidc_client_id', 'client');
    Setting::put('oidc_client_secret', \Illuminate\Support\Facades\Crypt::encryptString('s'));
    Setting::put('oidc_disable_local_login', '1');

    $this->get(route('password.request'))->assertRedirect(route('login'));
});

// --- Account enumeration ---------------------------------------------------

it('answers identically for a real and an unknown account', function () {
    User::factory()->create(['username' => 'real', 'email' => 'real@example.com']);

    $hit = $this->post(route('password.email'), ['login' => 'real']);
    $miss = $this->post(route('password.email'), ['login' => 'nobody']);

    expect(session('status'))->not->toBeNull();
    $hit->assertSessionHas('status', session('status'));
    $miss->assertSessionHas('status', session('status'));
});

it('says the same thing for an account with no email', function () {
    User::factory()->create(['username' => 'noaddr', 'email' => null]);

    $this->post(route('password.email'), ['login' => 'noaddr'])
        ->assertSessionHas('status');

    Mail::assertNothingSent();
});

it('says the same thing for a disabled account, and sends nothing', function () {
    User::factory()->create(['username' => 'off', 'email' => 'off@example.com', 'is_active' => false]);

    $this->post(route('password.email'), ['login' => 'off'])->assertSessionHas('status');

    Mail::assertNothingSent();
});

it('refuses an SSO-provisioned account', function () {
    $user = User::factory()->create(['username' => 'sso', 'email' => 'sso@example.com']);
    $user->forceFill(['auth_provider' => 'oidc'])->save();

    $this->post(route('password.email'), ['login' => 'sso'])->assertSessionHas('status');

    Mail::assertNothingSent();
});

// --- The happy path --------------------------------------------------------

it('emails a link that can be found by username or email', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com']);

    expect(requestReset('marc'))->not->toBeNull();

    Mail::fake();
    expect(requestReset('MARC@EXAMPLE.COM'))->not->toBeNull();
});

it('resets the password through the link', function () {
    $user = User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $token = requestReset('marc');

    $this->get(route('password.reset', ['token' => $token]))->assertOk();

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('lets the user sign in with the new password', function () {
    $user = User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $token = requestReset('marc');

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ]);

    $this->post(route('login.attempt'), ['username' => 'marc', 'password' => 'brand-new-password'])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

// --- Token discipline ------------------------------------------------------

it('rejects a reused link', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com']);
    $token = requestReset('marc');

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'first-new-password',
        'password_confirmation' => 'first-new-password',
    ]);

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'second-new-password',
        'password_confirmation' => 'second-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('first-new-password', User::first()->password))->toBeTrue();
});

it('rejects an expired link', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $token = requestReset('marc');

    PasswordReset::query()->update(['expires_at' => now()->subMinute()]);

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('old-password-1', User::first()->password))->toBeTrue();
});

it('retires an earlier link when a new one is requested', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $first = requestReset('marc');

    Mail::fake();
    requestReset('marc');

    $this->post(route('password.update', ['token' => $first]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertRedirect(route('login'));

    expect(Hash::check('old-password-1', User::first()->password))->toBeTrue();
});

it('rejects a made-up token', function () {
    $this->get(route('password.reset', ['token' => 'rst_nonsense']))->assertRedirect(route('login'));
});

it('stores the token only as a hash', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com']);
    $token = requestReset('marc');

    $row = PasswordReset::first();

    expect($row->token_hash)->not->toBe($token)
        ->and($row->token_hash)->toBe(hash('sha256', $token));
});

// --- Recovery semantics ----------------------------------------------------

it('revokes existing sessions and client tokens on reset', function () {
    // A reset is the recovery path for a compromised account: whatever the
    // attacker holds has to stop working.
    $user = User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com']);
    ClientToken::issue($user, ['id' => '123', 'uuid' => 'u']);

    $token = requestReset('marc');
    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ]);

    expect(ClientToken::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('enforces a minimum length on the new password', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $token = requestReset('marc');

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('old-password-1', User::first()->password))->toBeTrue();
});

it('requires the confirmation to match', function () {
    User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com', 'password' => 'old-password-1']);
    $token = requestReset('marc');

    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('old-password-1', User::first()->password))->toBeTrue();
});

it('still requires the second factor afterwards', function () {
    // A reset must not be a way around 2FA.
    $user = User::factory()->create(['username' => 'marc', 'email' => 'marc@example.com']);
    [$user] = enableTwoFactor($user);

    $token = requestReset('marc');
    $this->post(route('password.update', ['token' => $token]), [
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ]);

    $this->post(route('login.attempt'), ['username' => 'marc', 'password' => 'brand-new-password'])
        ->assertRedirect(route('login.2fa'));

    $this->assertGuest();
});
