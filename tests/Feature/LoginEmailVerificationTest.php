<?php

use App\Livewire\AccountProfile;
use App\Livewire\UserList;
use App\Mail\LoginVerificationCode;
use App\Models\AlarmLog;
use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Turn on a working relay plus the emailed-code policy. */
function enableEmailVerification(bool $on = true): void
{
    foreach ([
        'smtp_enabled' => '1',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_from_address' => 'console@example.com',
        'smtp_password' => Crypt::encryptString('relay-pass'),
        'email_login_verification' => $on ? '1' : '0',
    ] as $key => $value) {
        Setting::put($key, $value);
    }
}

function verifiableUser(array $attributes = []): User
{
    return User::create(array_merge([
        'username' => 'operator',
        'email' => 'operator@example.com',
        'password' => 'secret-password',
        'is_admin' => false,
    ], $attributes));
}

/** Sign in with the password and return the response. */
function passwordLogin(string $username = 'operator', string $password = 'secret-password')
{
    return test()->post('/login', ['username' => $username, 'password' => $password]);
}

/** Pull the 6-digit code out of the mailable the fake captured. */
function capturedCode(): string
{
    $code = null;
    Mail::assertSent(LoginVerificationCode::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

// --- Default off ------------------------------------------------------------

it('lets a sign-in through untouched while the setting is off', function () {
    Mail::fake();
    enableEmailVerification(false);
    $user = verifiableUser();

    passwordLogin()->assertRedirect(route('overview'));

    test()->assertAuthenticatedAs($user);
    Mail::assertNothingSent();
});

it('does nothing when the policy is on but email is not configured', function () {
    Mail::fake();
    enableEmailVerification();
    Setting::put('smtp_enabled', '0');
    $user = verifiableUser();

    passwordLogin()->assertRedirect(route('overview'));

    test()->assertAuthenticatedAs($user);
    Mail::assertNothingSent();
});

// --- The challenge ----------------------------------------------------------

it('emails a code and holds the sign-in at the challenge', function () {
    Mail::fake();
    enableEmailVerification();
    verifiableUser();

    passwordLogin()->assertRedirect(route('login.email'));

    test()->assertGuest();
    Mail::assertSent(LoginVerificationCode::class, fn ($mail) => $mail->hasTo('operator@example.com'));
    expect(capturedCode())->toMatch('/^\d{6}$/');
});

it('renders the challenge page with the masked address', function () {
    // Regression: a missing space (`code@if`) left the @if uncompiled but its
    // @endif compiled, so the page 500'd with an orphan `endif` (issue #17).
    // Nothing else rendered this view — POST-only coverage let it ship.
    Mail::fake();
    enableEmailVerification();
    verifiableUser();

    passwordLogin();

    test()->get(route('login.email'))
        ->assertOk()
        ->assertSee('6-digit code')
        ->assertSee('o'.str_repeat('•', 7).'@example.com');
});

it('completes the sign-in with the right code and trusts the browser', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();

    passwordLogin();
    $code = capturedCode();

    $response = test()->post(route('login.email.attempt'), ['code' => $code]);
    $response->assertRedirect(route('overview'))->assertCookie(TrustedDevice::COOKIE);

    test()->assertAuthenticatedAs($user);
    expect($user->trustedDevices()->count())->toBe(1);
    // Only the hash is stored, never the cookie value itself.
    expect($user->trustedDevices()->first()->token_hash)
        ->not->toBe($response->getCookie(TrustedDevice::COOKIE, false)->getValue());
});

it('rejects a wrong code and stays signed out', function () {
    Mail::fake();
    enableEmailVerification();
    verifiableUser();

    passwordLogin();

    test()->from(route('login.email'))
        ->post(route('login.email.attempt'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    test()->assertGuest();
});

it('throttles code guessing and raises a brute-force alarm', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();

    passwordLogin();

    foreach (range(1, 5) as $i) {
        test()->from(route('login.email'))
            ->post(route('login.email.attempt'), ['code' => '000000']);
    }

    test()->from(route('login.email'))
        ->post(route('login.email.attempt'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(session('errors')->first('code'))->toContain('Too many attempts')
        ->and(AlarmLog::where('typ', AlarmLog::TYP_BRUTE_FORCE)->exists())->toBeTrue();

    RateLimiter::clear('email-verify:'.$user->id.'|127.0.0.1');
});

it('expires the pending marker and sends the user back to sign in', function () {
    Mail::fake();
    enableEmailVerification();
    verifiableUser();

    passwordLogin();
    $code = capturedCode();

    // The marker carries its own issue time; age it past the 10-minute TTL.
    $pending = session('email_verify');
    $pending['at'] = now()->subMinutes(11)->timestamp;
    session(['email_verify' => $pending]);

    test()->post(route('login.email.attempt'), ['code' => $code])->assertRedirect(route('login'));
    test()->assertGuest();
});

it('mints a new code on resend and invalidates the old one', function () {
    Mail::fake();
    enableEmailVerification();
    verifiableUser();

    passwordLogin();
    $first = capturedCode();

    test()->post(route('login.email.resend'))->assertRedirect();

    $second = session('email_verify')['code_hash'];
    expect(Hash::check($first, $second))->toBeFalse();

    test()->from(route('login.email'))
        ->post(route('login.email.attempt'), ['code' => $first])
        ->assertSessionHasErrors('code');
});

// --- Trusted devices --------------------------------------------------------

it('skips the challenge for a browser that already carries a trust cookie', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();

    $plain = Str::random(48);
    TrustedDevice::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(30),
    ]);

    test()->withUnencryptedCookie(TrustedDevice::COOKIE, $plain)
        ->post('/login', ['username' => 'operator', 'password' => 'secret-password'])
        ->assertRedirect(route('overview'));

    test()->assertAuthenticatedAs($user);
    Mail::assertNothingSent();
});

it('ignores another user\'s trust cookie', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();
    $other = verifiableUser(['username' => 'other', 'email' => 'other@example.com']);

    $plain = Str::random(48);
    TrustedDevice::create([
        'user_id' => $other->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->addDays(30),
    ]);

    test()->withUnencryptedCookie(TrustedDevice::COOKIE, $plain)
        ->post('/login', ['username' => 'operator', 'password' => 'secret-password'])
        ->assertRedirect(route('login.email'));

    test()->assertGuest();
});

it('ignores an expired trust cookie', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();

    $plain = Str::random(48);
    TrustedDevice::create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->subDay(),
    ]);

    test()->withUnencryptedCookie(TrustedDevice::COOKIE, $plain)
        ->post('/login', ['username' => 'operator', 'password' => 'secret-password'])
        ->assertRedirect(route('login.email'));
});

it('drops trusted browsers when access is revoked or the password changes', function () {
    enableEmailVerification();
    $user = verifiableUser();
    $admin = User::create(['username' => 'boss', 'password' => 'secret-password', 'is_admin' => true]);

    $user->trustedDevices()->create([
        'token_hash' => hash('sha256', 'a'),
        'expires_at' => now()->addDays(30),
    ]);

    Livewire::actingAs($admin)->test(UserList::class)->call('forceLogout', $user->id);
    expect($user->trustedDevices()->count())->toBe(0);

    $user->trustedDevices()->create([
        'token_hash' => hash('sha256', 'b'),
        'expires_at' => now()->addDays(30),
    ]);

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->set('currentPassword', 'secret-password')
        ->set('password', 'a-new-password')
        ->set('passwordConfirmation', 'a-new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect($user->trustedDevices()->count())->toBe(0);
});

// --- Composition with 2FA and SSO ------------------------------------------

it('sends a 2FA user to the authenticator challenge, never the email one', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser();
    $user->forceFill([
        'totp_secret' => TwoFactor::generateSecret(),
        'totp_enabled' => true,
        'totp_confirmed_at' => now(),
    ])->save();

    passwordLogin()->assertRedirect(route('login.2fa'));

    test()->assertGuest();
    Mail::assertNothingSent();
    expect(session('email_verify'))->toBeNull();
});

it('never blocks a user who has no email address', function () {
    Mail::fake();
    enableEmailVerification();
    $user = verifiableUser(['email' => null]);

    passwordLogin()->assertRedirect(route('overview'));

    test()->assertAuthenticatedAs($user);
    Mail::assertNothingSent();
});

it('keeps an ordinary user out when the relay refuses the message', function () {
    // REPLACES an earlier test that asserted the opposite. D1 originally failed
    // OPEN here: a dead relay let everyone in on the password alone, which
    // quietly disabled the control the operator had switched on. The console
    // now closes to everyone who cannot repair it — see the admin case below.
    //
    // No Mail::fake(): the array transport is swapped for a host that cannot
    // resolve, so the send genuinely fails the way a dead relay would.
    enableEmailVerification();
    // Port 1 on loopback refuses instantly — a dead relay without the wait.
    Setting::put('smtp_host', '127.0.0.1');
    Setting::put('smtp_port', '1');
    verifiableUser();

    passwordLogin()->assertSessionHasErrors('username');

    test()->assertGuest();
    expect(LoginLog::where('note', 'email-verification send failed')->exists())->toBeTrue();
});

it('lets someone who can fix the relay in, and marks them for repair', function () {
    enableEmailVerification();
    Setting::put('smtp_host', '127.0.0.1');
    Setting::put('smtp_port', '1');
    $admin = verifiableUser(['is_admin' => true]);

    passwordLogin();

    test()->assertAuthenticatedAs($admin);
    expect(session('mail_repair'))->toBeTrue();
});
