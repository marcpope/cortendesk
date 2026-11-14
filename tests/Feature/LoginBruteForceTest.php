<?php

use App\Models\AlarmLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Console sign-in brute-force protections
|--------------------------------------------------------------------------
| Two limiters: per account (5/min) and per address across all accounts
| (20/min). The second is what stops password spraying, which the per-account
| limiter alone cannot see. Tripping either raises an alarm.
*/

beforeEach(function () {
    RateLimiter::clear('login-ip:127.0.0.1');
});

/** Submit a wrong password for a username. */
function badLogin(string $username = 'victim'): \Illuminate\Testing\TestResponse
{
    return test()->post(route('login.attempt'), [
        'username' => $username,
        'password' => 'definitely-not-the-password',
    ]);
}

// --- Per-account limit -----------------------------------------------------

it('blocks a sixth failed attempt against one account', function () {
    User::factory()->create(['username' => 'victim', 'password' => 'correct-horse-battery']);

    for ($i = 0; $i < 5; $i++) {
        badLogin()->assertSessionHasErrors('username');
    }

    badLogin()->assertInvalid(['username' => 'Too many attempts']);
});

it('refuses the right password while the account is throttled', function () {
    $user = User::factory()->create(['username' => 'victim', 'password' => 'correct-horse-battery']);

    for ($i = 0; $i < 6; $i++) {
        badLogin();
    }

    // Even a correct password must wait out the window.
    $this->post(route('login.attempt'), ['username' => 'victim', 'password' => 'correct-horse-battery'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('clears the account counter after a successful sign-in', function () {
    $user = User::factory()->create(['username' => 'victim', 'password' => 'correct-horse-battery']);

    badLogin();
    badLogin();

    $this->post(route('login.attempt'), ['username' => 'victim', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user);
    expect(RateLimiter::attempts('login:victim|127.0.0.1'))->toBe(0);
});

// --- Per-address limit (the spraying defence) ------------------------------

it('stops password spraying across many accounts from one address', function () {
    // Five accounts × four attempts each = 20 failures, none of which trips the
    // per-account limit. Without the address limiter this would run forever.
    for ($account = 0; $account < 5; $account++) {
        for ($try = 0; $try < 4; $try++) {
            badLogin('user'.$account);
        }
    }

    // The 21st attempt, against a brand-new username, must be refused.
    badLogin('someone-completely-different')->assertInvalid(['username' => 'Too many attempts']);
});

it('does not let a valid sign-in reset the address budget', function () {
    User::factory()->create(['username' => 'insider', 'password' => 'correct-horse-battery']);

    for ($i = 0; $i < 19; $i++) {
        badLogin('user'.$i);
    }

    // An attacker holding one good credential must not be able to wipe the
    // address counter by signing into it.
    $this->post(route('login.attempt'), ['username' => 'insider', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('overview'));

    expect(RateLimiter::attempts('login-ip:127.0.0.1'))->toBe(19);
});

it('leaves a normal user with a few typos alone', function () {
    $user = User::factory()->create(['username' => 'normal', 'password' => 'correct-horse-battery']);

    badLogin('normal');
    badLogin('normal');

    $this->post(route('login.attempt'), ['username' => 'normal', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user);
});

// --- Alarms ----------------------------------------------------------------

it('raises a brute-force alarm when an account limit trips', function () {
    User::factory()->create(['username' => 'victim', 'password' => 'correct-horse-battery']);

    for ($i = 0; $i < 6; $i++) {
        badLogin();
    }

    $alarm = AlarmLog::query()->where('typ', AlarmLog::TYP_BRUTE_FORCE)->first();

    expect($alarm)->not->toBeNull()
        ->and($alarm->rustdesk_id)->toBe(AlarmLog::CONSOLE_SOURCE)
        ->and($alarm->typeLabel())->toBe('Console brute force')
        ->and($alarm->typeSeverity())->toBe('danger')
        ->and($alarm->infoPairs()['username'])->toBe('victim')
        ->and($alarm->infoPairs()['ip'])->toBe('127.0.0.1');
});

it('raises a spraying alarm when the address limit trips', function () {
    for ($i = 0; $i < 21; $i++) {
        badLogin('user'.$i);
    }

    $alarm = AlarmLog::query()->where('typ', AlarmLog::TYP_SPRAYING)->first();

    expect($alarm)->not->toBeNull()
        ->and($alarm->typeLabel())->toBe('Console password spraying')
        ->and($alarm->infoPairs()['ip'])->toBe('127.0.0.1');
});

it('raises one alarm per attack, not one per request', function () {
    User::factory()->create(['username' => 'victim']);

    // Twenty blocked requests after the trip must not write twenty rows.
    for ($i = 0; $i < 25; $i++) {
        badLogin();
    }

    expect(AlarmLog::query()->where('typ', AlarmLog::TYP_BRUTE_FORCE)->count())->toBe(1);
});

it('raises no alarm for an ordinary failed sign-in', function () {
    User::factory()->create(['username' => 'victim']);

    badLogin();
    badLogin();

    expect(AlarmLog::query()->count())->toBe(0);
});

it('alarms on second-factor grinding too', function () {
    $user = User::factory()->create(['username' => 'victim', 'password' => 'correct-horse-battery']);
    [$user] = enableTwoFactor($user);

    $this->post(route('login.attempt'), ['username' => 'victim', 'password' => 'correct-horse-battery'])
        ->assertRedirect(route('login.2fa'));

    for ($i = 0; $i < 6; $i++) {
        $this->post(route('login.2fa.attempt'), ['code' => '000000']);
    }

    $alarm = AlarmLog::query()->where('typ', AlarmLog::TYP_BRUTE_FORCE)->first();

    expect($alarm)->not->toBeNull()
        ->and($alarm->infoPairs()['step'])->toBe('two-factor');
});

// --- Visibility ------------------------------------------------------------

it('keeps console alarms out of a non-admin alarm list', function () {
    // Console alarms carry no device, and the list scopes non-admins to their
    // own devices — so security alarms stay admin-only without extra plumbing.
    AlarmLog::console(AlarmLog::TYP_SPRAYING, ['ip' => '10.0.0.9']);

    $user = User::factory()->create(['is_admin' => false]);

    // The label itself appears in the filter dropdown, so assert on the row's
    // own data — the alarm must not be listed.
    $this->actingAs($user)->get(route('logs.alarms'))
        ->assertOk()
        ->assertDontSee('10.0.0.9');
});

it('shows console alarms to an administrator', function () {
    AlarmLog::console(AlarmLog::TYP_SPRAYING, ['ip' => '10.0.0.9']);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('logs.alarms'))
        ->assertOk()
        ->assertSee('10.0.0.9');
});
