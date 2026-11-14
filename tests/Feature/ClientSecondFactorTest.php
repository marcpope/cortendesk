<?php

use App\Mail\LoginVerificationCode;
use App\Models\AlarmLog;
use App\Models\ClientToken;
use App\Models\Setting;
use App\Models\User;
use App\Services\MailSettings;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
 * The RustDesk client signs in with the console's second factor (found with
 * issue #102): TOTP when enrolled, else the emailed code when sign-in
 * verification is on. Step 1 answers `email_check`; step 2 posts the code
 * with the secret as `type: email_code`, the way the stock client does.
 */

function clientTfaUser(array $attributes = []): User
{
    return User::create(array_merge([
        'username' => 'operator',
        'email' => 'operator@example.com',
        'password' => 'secret-password',
        'is_admin' => false,
    ], $attributes));
}

function clientEnrol(User $user): string
{
    $secret = TwoFactor::generateSecret();
    $user->forceFill(['totp_secret' => $secret, 'totp_enabled' => true, 'totp_confirmed_at' => now()])->save();

    return $secret;
}

function clientTotpNow(string $secret): string
{
    return TwoFactor::totp($secret, 'x')->at(now()->timestamp);
}

function clientPasswordStep(string $id = '123456789')
{
    return test()->postJson('/api/login', [
        'username' => 'operator', 'password' => 'secret-password',
        'id' => $id, 'uuid' => 'dXVpZA==', 'autoLogin' => true, 'type' => 'account',
        'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'PC'],
    ]);
}

/** Step 2 as Flutter sends it: the TOTP goes in both fields, an email code in one. */
function clientCodeStep(string $secret, string $code, bool $totp = true, string $id = '123456789')
{
    return test()->postJson('/api/login', array_filter([
        'username' => 'operator', 'id' => $id, 'uuid' => 'dXVpZA==', 'autoLogin' => true,
        'type' => 'email_code', 'secret' => $secret, 'verificationCode' => $code,
        'tfaCode' => $totp ? $code : null,
        'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'PC'],
    ], fn ($v) => $v !== null));
}

function clientEmailVerification(): void
{
    foreach ([
        'smtp_enabled' => '1',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_from_address' => 'console@example.com',
        'smtp_password' => Crypt::encryptString('relay-pass'),
        'email_login_verification' => '1',
    ] as $key => $value) {
        Setting::put($key, $value);
    }
}

it('asks a TOTP user for the code before issuing a token', function () {
    $secret = clientEnrol(clientTfaUser());

    $step1 = clientPasswordStep()
        ->assertOk()
        ->assertJsonPath('type', 'email_check')
        ->assertJsonPath('tfa_type', 'tfa_check')
        ->assertJsonPath('user.name', 'operator')
        ->assertJsonMissingPath('access_token');

    expect(ClientToken::count())->toBe(0);

    clientCodeStep($step1->json('secret'), clientTotpNow($secret))
        ->assertOk()
        ->assertJsonPath('type', 'access_token')
        ->assertJsonStructure(['access_token', 'user' => ['name']]);

    expect(ClientToken::count())->toBe(1);
});

it('refuses a wrong code, then a replayed one', function () {
    $secret = clientEnrol(clientTfaUser());

    $prompt = clientPasswordStep()->json('secret');
    clientCodeStep($prompt, '000000')->assertJsonPath('error', 'That code is incorrect or has expired.');

    $code = clientTotpNow($secret);
    clientCodeStep($prompt, $code)->assertJsonStructure(['access_token']);

    clientCodeStep(clientPasswordStep()->json('secret'), $code)
        ->assertJsonPath('error', 'That code has already been used. Wait for the next one.');

    expect(ClientToken::count())->toBe(1);
});

it('binds the prompt to the device that gave the password', function () {
    $secret = clientEnrol(clientTfaUser());

    $prompt = clientPasswordStep('111111111')->json('secret');

    clientCodeStep($prompt, clientTotpNow($secret), id: '222222222')
        ->assertJsonPath('error', 'Your sign-in expired. Sign in again.');
    clientCodeStep('made-up-secret', clientTotpNow($secret))
        ->assertJsonPath('error', 'Your sign-in expired. Sign in again.');

    expect(ClientToken::count())->toBe(0);
});

it('drops a prompt after five wrong codes', function () {
    $secret = clientEnrol($user = clientTfaUser());
    $prompt = clientPasswordStep()->json('secret');

    foreach (range(1, 5) as $_) {
        clientCodeStep($prompt, '000000');
    }
    RateLimiter::clear('client-code:'.$user->id.'|127.0.0.1');

    clientCodeStep($prompt, clientTotpNow($secret))
        ->assertJsonPath('error', 'Your sign-in expired. Sign in again.');
});

it('throttles code guessing and raises one alarm', function () {
    $secret = clientEnrol(clientTfaUser());

    // Five wrong codes over two prompts fill the limiter; the second prompt
    // then refuses even the right code.
    $first = clientPasswordStep()->json('secret');
    foreach (range(1, 4) as $_) {
        clientCodeStep($first, '000000');
    }
    $second = clientPasswordStep()->json('secret');
    clientCodeStep($second, '000000');

    clientCodeStep($second, clientTotpNow($secret))
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'Too many attempts.'));

    expect(AlarmLog::where('typ', AlarmLog::TYP_BRUTE_FORCE)->count())->toBe(1)
        ->and(ClientToken::count())->toBe(0);
});

it('turns away an un-enrolled user while 2FA is required', function () {
    Setting::put('two_factor_required', '1');
    clientTfaUser();

    clientPasswordStep()
        ->assertJsonPath('error', 'Two-factor authentication is required. Set it up in the web console, then sign in again.');

    expect(ClientToken::count())->toBe(0);
});

it('emails a code when sign-in verification is on', function () {
    Mail::fake();
    clientEmailVerification();
    clientTfaUser();

    $step1 = clientPasswordStep()
        ->assertJsonPath('type', 'email_check')
        ->assertJsonPath('tfa_type', 'email_check')
        ->assertJsonPath('user.email', 'o'.str_repeat('•', 7).'@example.com');

    $code = null;
    Mail::assertSent(LoginVerificationCode::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    clientCodeStep($step1->json('secret'), '999999' === $code ? '000000' : '999999', totp: false)
        ->assertJsonPath('error', 'That code is incorrect or has expired.');
    clientCodeStep($step1->json('secret'), $code, totp: false)
        ->assertJsonStructure(['access_token']);
});

it('prefers the authenticator over the emailed code', function () {
    Mail::fake();
    clientEmailVerification();
    clientEnrol(clientTfaUser());

    clientPasswordStep()->assertJsonPath('tfa_type', 'tfa_check');

    Mail::assertNothingSent();
});

it('fails closed when the code cannot be sent', function () {
    clientEmailVerification();
    clientTfaUser(['is_admin' => true]);
    $this->mock(MailSettings::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturnTrue();
        $mock->shouldReceive('send')->andReturnFalse();
    });

    clientPasswordStep()
        ->assertJsonPath('error', 'Sign-in is temporarily unavailable: this console cannot send verification codes. Contact an administrator.');

    expect(ClientToken::count())->toBe(0);
});

it('keeps a linked account awaiting approval out', function () {
    clientTfaUser()->forceFill(['oidc_status' => 'pending'])->save();

    clientPasswordStep()->assertJsonPath('error', 'Your account is waiting for administrator approval.');

    expect(ClientToken::count())->toBe(0);
});

it('signs in at once without a second factor', function () {
    clientTfaUser();

    clientPasswordStep()->assertJsonPath('type', 'access_token')->assertJsonStructure(['access_token']);
});

it('shares the per-account code budget across addresses', function () {
    $secret = clientEnrol(clientTfaUser());

    foreach ([1, 2] as $n) {
        $prompt = $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.$n])->postJson('/api/login', [
            'username' => 'operator', 'password' => 'secret-password',
            'id' => '123456789', 'uuid' => 'dXVpZA==', 'autoLogin' => true, 'type' => 'account',
            'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'PC'],
        ])->json('secret');
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.'.($n * 10 + $i)]);
            clientCodeStep($prompt, '000000');
        }
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1']);
    $prompt = clientPasswordStep()->json('secret');
    clientCodeStep($prompt, clientTotpNow($secret))
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'Too many attempts'));

    expect(ClientToken::count())->toBe(0);
});
