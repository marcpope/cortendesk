<?php

namespace App\Services;

use App\Mail\TestMessage;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Runtime SMTP configuration held in the settings table (PLAN D1).
 *
 * Shaped deliberately like OidcService: the operator configures a relay from
 * Settings → Email, the password is encrypted at rest, and everything that
 * needs to send calls through here.
 *
 * WHY a lazily-applied config override and NOT `Mail::build($config)`:
 * Illuminate's MailFake defines no build() of its own, and its __call
 * forwards to the real MailManager. A Mail::build() send would therefore
 * construct a live Symfony transport even under Mail::fake() — invisible to
 * the test assertions and opening a real socket from the test suite. Writing
 * `config('mail.*')` and then sending on the default mailer keeps every send
 * on the facade path that Mail::fake() intercepts.
 *
 * WHY not a ServiceProvider::boot() override: it would put a settings-table
 * read on every request (including the client-API hot path) and would run on
 * a fresh install before the table exists (artisan migrate). Applying lazily,
 * only when something is actually about to send, has neither problem.
 */
class MailSettings
{
    /** Seconds to wait on the SMTP socket. Bounds a synchronous send. */
    private const TIMEOUT = 10;

    /** Is outbound email switched on AND configured well enough to try? */
    public function isEnabled(): bool
    {
        return $this->setting('smtp_enabled') === '1' && $this->isConfigured();
    }

    /** Are the two fields required to hand a message to a relay present? */
    public function isConfigured(): bool
    {
        return $this->setting('smtp_host') !== '' && $this->setting('smtp_from_address') !== '';
    }

    public function fromAddress(): string
    {
        return $this->setting('smtp_from_address');
    }

    /**
     * Point the default mailer at the configured relay for this process.
     *
     * A no-op when email is off, so the .env mailer (log in dev, array under
     * phpunit) keeps governing and nothing unexpected leaves the box.
     */
    public function apply(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->writeConfig();
    }

    /** Write the transport configuration without consulting the on/off switch. */
    private function writeConfig(): void
    {
        $username = $this->setting('smtp_username');
        $password = $this->password();
        $fromName = $this->setting('smtp_from_name');
        $port = (int) ($this->setting('smtp_port') ?: '587');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $this->setting('smtp_host'),
            'mail.mailers.smtp.port' => $port > 0 ? $port : 587,
            // Empty string is NOT the same as null here: Symfony attempts an
            // AUTH exchange with an empty username, which most relays reject.
            'mail.mailers.smtp.username' => $username !== '' ? $username : null,
            'mail.mailers.smtp.password' => $password !== '' ? $password : null,
            // Implicit TLS gets its own scheme; STARTTLS is negotiated on the
            // plain scheme. See encryption() for why "none" is not plaintext.
            'mail.mailers.smtp.scheme' => $this->encryption() === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.timeout' => self::TIMEOUT,
            'mail.mailers.smtp.local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'mail.from.address' => $this->fromAddress(),
            'mail.from.name' => $fromName !== '' ? $fromName : config('app.name'),
        ]);

        // Drop any mailer already resolved with the previous configuration.
        Mail::forgetMailers();
    }

    /** starttls | ssl | none (defaults to starttls). */
    public function encryption(): string
    {
        $value = $this->setting('smtp_encryption');

        return in_array($value, ['starttls', 'ssl', 'none'], true) ? $value : 'starttls';
    }

    /**
     * Send a mailable to one address, swallowing transport failures.
     *
     * Callers on the login path depend on the false return rather than an
     * exception: a dead relay must not strand a user mid-sign-in.
     */
    public function send(Mailable $mailable, string $to): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $this->apply();
            Mail::to($to)->send($mailable);
            $this->recordSuccess();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Email send failed: '.$e->getMessage());
            $this->recordFailure($e->getMessage());

            return false;
        }
    }

    /**
     * Relay health, as last observed.
     *
     * Only ever set by a real send attempt — there is no probe. A console that
     * has never sent anything is "healthy" by default, because assuming a relay
     * is broken before trying it would gate sign-in on nothing.
     */
    public function isHealthy(): bool
    {
        $failedAt = Setting::get('smtp_failed_at', '');

        if ($failedAt === '' || $failedAt === null) {
            return true;
        }

        $okAt = Setting::get('smtp_ok_at', '') ?: '';

        return $okAt !== '' && $okAt >= $failedAt;
    }

    /** The relay's own words from the last failure, for the operator to read. */
    public function lastError(): string
    {
        return $this->isHealthy() ? '' : trim((string) (Setting::get('smtp_last_error', '') ?? ''));
    }

    public function recordSuccess(): void
    {
        Setting::put('smtp_ok_at', now()->toDateTimeString());
    }

    public function recordFailure(string $error): void
    {
        Setting::put('smtp_failed_at', now()->toDateTimeString());
        // Truncated: this is displayed, and some relays return a wall of text.
        Setting::put('smtp_last_error', mb_substr($error, 0, 300));
    }

    /**
     * Deliver a test message and report what actually happened, so an operator
     * sees the relay's own error rather than a generic failure.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(string $to): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => __('messages.smtp_host_first')];
        }

        try {
            // Deliberately bypasses the enabled switch: the test button exists
            // so an operator can prove the relay works BEFORE turning email on.
            $this->writeConfig();
            Mail::to($to)->send(new TestMessage);
        } catch (\Throwable $e) {
            $this->recordFailure($e->getMessage());

            return ['ok' => false, 'message' => __('messages.send_failed', ['error' => $e->getMessage()])];
        }

        // A successful test is the intended way to clear a relay outage: it is
        // what releases the administrator from RequireMailHealthy.
        $this->recordSuccess();

        return ['ok' => true, 'message' => __('messages.test_sent', ['to' => $to])];
    }

    /** Decrypt the stored SMTP password, tolerating a plaintext legacy value. */
    private function password(): string
    {
        $stored = $this->setting('smtp_password');

        if ($stored === '') {
            return '';
        }

        try {
            return (string) Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    private function setting(string $key): string
    {
        return trim((string) (Setting::get($key, '') ?? ''));
    }
}
