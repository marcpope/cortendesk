<?php

use App\Livewire\SettingsPage;
use App\Mail\TestMessage;
use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Models\User;
use App\Services\MailSettings;
use App\Support\LoginEmailVerification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function mailAdmin(): User
{
    $user = User::create([
        'username' => 'mail-admin',
        'email' => 'mail-admin@example.com',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
    test()->actingAs($user);

    return $user;
}

/** Store a working relay configuration straight into settings. */
function configureSmtp(array $overrides = []): void
{
    $values = array_merge([
        'smtp_enabled' => '1',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_encryption' => 'starttls',
        'smtp_username' => 'relay-user',
        'smtp_password' => Crypt::encryptString('relay-pass'),
        'smtp_from_address' => 'console@example.com',
        'smtp_from_name' => 'CortenDesk Console',
    ], $overrides);

    foreach ($values as $key => $value) {
        Setting::put($key, $value);
    }
}

// --- The service ------------------------------------------------------------

it('rewrites the mailer configuration from the stored settings', function () {
    configureSmtp();

    app(MailSettings::class)->apply();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.username'))->toBe('relay-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('relay-pass')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.timeout'))->toBe(10)
        ->and(config('mail.from.address'))->toBe('console@example.com')
        ->and(config('mail.from.name'))->toBe('CortenDesk Console');
});

it('uses the smtps scheme for implicit TLS', function () {
    configureSmtp(['smtp_encryption' => 'ssl', 'smtp_port' => '465']);

    app(MailSettings::class)->apply();

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps');
});

it('checks the relay certificate unless told to skip it (#160)', function () {
    configureSmtp();
    app(MailSettings::class)->apply();
    expect(config('mail.mailers.smtp.verify_peer'))->toBeTrue();

    Setting::put('smtp_skip_cert_check', '1');
    app(MailSettings::class)->apply();
    expect(config('mail.mailers.smtp.verify_peer'))->toBeFalse();

    $stream = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream();
    expect($stream->getStreamOptions()['ssl']['verify_peer'] ?? null)->toBeFalse()
        ->and($stream->getStreamOptions()['ssl']['verify_peer_name'] ?? null)->toBeFalse();
});

it('sends a null username and password rather than empty strings', function () {
    configureSmtp(['smtp_username' => '', 'smtp_password' => '']);

    app(MailSettings::class)->apply();

    expect(config('mail.mailers.smtp.username'))->toBeNull()
        ->and(config('mail.mailers.smtp.password'))->toBeNull();
});

it('leaves the mailer alone when email is switched off', function () {
    configureSmtp(['smtp_enabled' => '0']);
    $before = config('mail.default');

    app(MailSettings::class)->apply();

    expect(config('mail.default'))->toBe($before)
        ->and(config('mail.mailers.smtp.host'))->not->toBe('smtp.example.com');
});

it('treats a host or from address alone as unconfigured', function () {
    configureSmtp(['smtp_from_address' => '']);

    expect(app(MailSettings::class)->isConfigured())->toBeFalse()
        ->and(app(MailSettings::class)->isEnabled())->toBeFalse();
});

it('refuses to send while email is disabled', function () {
    Mail::fake();
    configureSmtp(['smtp_enabled' => '0']);

    expect(app(MailSettings::class)->send(new TestMessage, 'someone@example.com'))->toBeFalse();

    Mail::assertNothingSent();
});

// --- Settings screen --------------------------------------------------------

it('saves the SMTP card and encrypts the password at rest', function () {
    mailAdmin();

    Livewire::test(SettingsPage::class)
        ->set('smtpEnabled', true)
        ->set('smtpHost', ' smtp.example.com ')
        ->set('smtpPort', 2525)
        ->set('smtpEncryption', 'ssl')
        ->set('smtpUsername', 'relay-user')
        ->set('smtpPassword', 'super-secret')
        ->set('smtpFromAddress', 'console@example.com')
        ->set('smtpFromName', 'Console')
        ->set('smtpSkipCertCheck', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('smtpPassword', '')
        ->assertSet('smtpPasswordSet', true);

    expect(Setting::get('smtp_enabled'))->toBe('1')
        ->and(Setting::get('smtp_host'))->toBe('smtp.example.com')
        ->and(Setting::get('smtp_port'))->toBe('2525')
        ->and(Setting::get('smtp_skip_cert_check'))->toBe('1')
        ->and(Setting::get('smtp_password'))->not->toBe('super-secret')
        ->and(Crypt::decryptString(Setting::get('smtp_password')))->toBe('super-secret');
});

it('keeps the stored SMTP password when the field is left blank', function () {
    mailAdmin();
    configureSmtp();

    Livewire::test(SettingsPage::class)
        ->assertSet('smtpPassword', '')
        ->assertSet('smtpPasswordSet', true)
        ->set('smtpFromName', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect(Crypt::decryptString(Setting::get('smtp_password')))->toBe('relay-pass')
        ->and(Setting::get('smtp_from_name'))->toBe('Renamed');
});

it('refuses to enable email without a host and from address', function () {
    mailAdmin();

    Livewire::test(SettingsPage::class)
        ->set('smtpEnabled', true)
        ->set('smtpHost', '')
        ->set('smtpFromAddress', '')
        ->call('save')
        ->assertHasErrors('smtpHost');

    expect(Setting::get('smtp_enabled', '0'))->toBe('0');
});

it('validates the SMTP port and from address', function () {
    mailAdmin();

    Livewire::test(SettingsPage::class)
        ->set('smtpPort', 99999)
        ->set('smtpFromAddress', 'not-an-address')
        ->call('save')
        ->assertHasErrors(['smtpPort', 'smtpFromAddress']);
});

// --- Test-send button -------------------------------------------------------

it('sends a test message through the saved settings and audits it', function () {
    Mail::fake();
    mailAdmin();
    configureSmtp();

    Livewire::test(SettingsPage::class)
        ->set('smtpTestTo', 'someone@example.com')
        ->call('sendTestEmail')
        ->assertSet('smtpTestOk', true);

    Mail::assertSent(TestMessage::class, fn ($mail) => $mail->hasTo('someone@example.com'));
    expect(ConsoleAudit::where('action', 'settings.mail-test')->exists())->toBeTrue();
});

it('tests the relay even before the enabled switch is turned on', function () {
    Mail::fake();
    mailAdmin();
    configureSmtp(['smtp_enabled' => '0']);

    Livewire::test(SettingsPage::class)
        ->set('smtpTestTo', 'someone@example.com')
        ->call('sendTestEmail')
        ->assertSet('smtpTestOk', true);

    Mail::assertSent(TestMessage::class);
});

it('refuses the test send until the settings are saved', function () {
    Mail::fake();
    mailAdmin();

    Livewire::test(SettingsPage::class)
        ->set('smtpTestTo', 'someone@example.com')
        ->call('sendTestEmail')
        ->assertSet('smtpTestOk', false)
        ->assertSet('smtpTestMessage', 'Save the SMTP settings first.');

    Mail::assertNothingSent();
});

it('rejects a malformed test recipient', function () {
    Mail::fake();
    mailAdmin();
    configureSmtp();

    Livewire::test(SettingsPage::class)
        ->set('smtpTestTo', 'nope')
        ->call('sendTestEmail')
        ->assertSet('smtpTestOk', false);

    Mail::assertNothingSent();
});

// --- Unconfigured-email warning --------------------------------------------

it('warns an administrator when email is not configured', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('overview'))
        ->assertOk()
        ->assertSee('Email is not configured');
});

it('drops the warning once SMTP is set up', function () {
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.example.com');
    Setting::put('smtp_from_address', 'console@example.com');

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('overview'))
        ->assertOk()
        ->assertDontSee('Email is not configured');
});

it('does not show it to a user who cannot change settings', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route('overview'))
        ->assertOk()
        ->assertDontSee('Email is not configured');
});

// --- Relay outage: users out, repairers in ---------------------------------

/** Configure verification with a relay that will refuse every message. */
function brokenRelay(): void
{
    Setting::put('smtp_enabled', '1');
    Setting::put('smtp_host', 'smtp.invalid.example');
    Setting::put('smtp_from_address', 'console@example.com');
    Setting::put('email_login_verification', '1');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));
    // Health is only ever set by a real send attempt, so mark the outage the
    // way a failed send would.
    app(MailSettings::class)->recordFailure('Connection refused');
}

it('keeps an ordinary user out when a code cannot be sent', function () {
    brokenRelay();
    User::factory()->create(['username' => 'user', 'email' => 'u@example.com', 'password' => 'pw-123456', 'is_admin' => false]);

    $this->post(route('login.attempt'), ['username' => 'user', 'password' => 'pw-123456'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('lets an administrator in to repair it', function () {
    brokenRelay();
    User::factory()->create(['username' => 'boss', 'email' => 'b@example.com', 'password' => 'pw-123456', 'is_admin' => true]);

    $this->post(route('login.attempt'), ['username' => 'boss', 'password' => 'pw-123456']);

    $this->assertAuthenticated();
    expect(session('mail_repair'))->toBeTrue();
});

it('walks that administrator to the mail settings', function () {
    brokenRelay();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'b@example.com']);

    $this->actingAs($admin)->withSession(['mail_repair' => true])
        ->get(route('devices'))
        ->assertRedirect(route('settings', ['tab' => 'email']));
});

it('releases them once the relay reports healthy', function () {
    brokenRelay();
    app(MailSettings::class)->recordSuccess();

    $admin = User::factory()->create(['is_admin' => true, 'email' => 'b@example.com']);

    $this->actingAs($admin)->withSession(['mail_repair' => true])
        ->get(route('devices'))->assertOk();
});

it('records relay health from send outcomes', function () {
    $mail = app(MailSettings::class);

    expect($mail->isHealthy())->toBeTrue(); // never sent anything yet

    $mail->recordFailure('Connection refused');
    expect($mail->isHealthy())->toBeFalse()
        ->and($mail->lastError())->toBe('Connection refused');

    $mail->recordSuccess();
    expect($mail->isHealthy())->toBeTrue()
        ->and($mail->lastError())->toBe('');
});

it('reopens the console from the command line', function () {
    brokenRelay();

    $this->artisan('cortendesk:email-verification off')->assertSuccessful();

    expect(Setting::get('email_login_verification'))->toBe('0')
        ->and(LoginEmailVerification::isActive())->toBeFalse();
});

it('uses the custom email footer, or the console address when blank (#153)', function () {
    mailAdmin();
    config(['app.url' => 'http://203.0.113.5:8080']);

    $html = (new TestMessage)->render();
    expect($html)->toContain('203.0.113.5:8080');

    Livewire::test(SettingsPage::class)
        ->set('smtpFooter', " Acme IT\nhelp@acme.example <b> ")
        ->call('save')
        ->assertHasNoErrors();

    $html = (new TestMessage)->render();
    expect(Setting::get('smtp_footer'))->toBe("Acme IT\nhelp@acme.example <b>")
        ->and($html)->toContain('Acme IT<br />')
        ->and($html)->toContain('help@acme.example &lt;b&gt;')
        ->and($html)->not->toContain('203.0.113.5');
});
