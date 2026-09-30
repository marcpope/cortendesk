<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SessionCookieFollowsScheme;
use App\Mail\LoginVerificationCode;
use App\Models\AlarmLog;
use App\Models\LoginLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\AppriseNotifications;
use App\Services\MailSettings;
use App\Services\OidcService;
use App\Support\LoginEmailVerification;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    /** How long a pending-2FA marker stays valid, in seconds. */
    private const PENDING_TTL = 300;

    /**
     * How long a pending email-code marker stays valid, in seconds.
     *
     * Deliberately longer than PENDING_TTL: an authenticator app shows a code
     * instantly, but an emailed one has to survive a mail queue and a spam
     * filter before the user can even read it.
     */
    private const EMAIL_PENDING_TTL = 600;

    /** Failed sign-ins allowed per account, per address, per window. */
    private const MAX_PER_ACCOUNT = 5;

    /**
     * Failed sign-ins allowed per address across ALL accounts, per window.
     *
     * The per-account limit alone does not stop password spraying: keyed on
     * the username, one address may try five passwords against each of a
     * hundred accounts and never trip anything. This second limiter bounds the
     * address itself. It is set well above the per-account limit so a shared
     * office address with several people mistyping passwords is unaffected.
     */
    private const MAX_PER_ADDRESS = 20;

    /** Throttle window, in seconds. */
    private const DECAY = 60;

    /** Don't re-raise the same alarm more often than this, in seconds. */
    private const ALARM_COOLDOWN = 300;

    public function __construct(private readonly OidcService $oidc) {}

    public function showLogin(Request $request): View
    {
        return view('auth.login', [
            'httpsOnly' => SessionCookieFollowsScheme::blocksSignIn($request),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        // Force-SSO. Only ever true while the IdP is reachable and configured
        // (see OidcService::localLoginDisabled), so a broken provider can't
        // lock the console — password login comes back on its own.
        if ($this->oidc->localLoginDisabled()) {
            return back()->withErrors([
                'username' => 'Password sign-in is disabled on this console. Use single sign-on.',
            ]);
        }

        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $ip = (string) $request->ip();
        $accountKey = 'login:'.mb_strtolower($credentials['username']).'|'.$ip;
        $addressKey = 'login-ip:'.$ip;

        // Address-wide limit first — it is the one that catches spraying, and
        // checking it before the per-account limit means a spraying attacker
        // is stopped even on the first attempt against each new username.
        $this->ensureNotRateLimited($addressKey, 'username', self::MAX_PER_ADDRESS, fn () => AlarmLog::console(
            AlarmLog::TYP_SPRAYING,
            ['ip' => $ip, 'attempts' => self::MAX_PER_ADDRESS, 'window' => self::DECAY.'s', 'last_username' => $credentials['username']],
        ));

        $this->ensureNotRateLimited($accountKey, 'username', self::MAX_PER_ACCOUNT, fn () => AlarmLog::console(
            AlarmLog::TYP_BRUTE_FORCE,
            ['ip' => $ip, 'username' => $credentials['username'], 'attempts' => self::MAX_PER_ACCOUNT, 'window' => self::DECAY.'s'],
        ));

        $remember = $request->boolean('remember');

        // Validate WITHOUT logging in — a 2FA-enabled user must clear the
        // challenge before a real session is written.
        $ok = Auth::validate($credentials + ['is_active' => true]);
        $user = $ok ? Auth::getLastAttempted() : null;

        LoginLog::create([
            'user_id' => $user?->id,
            'username' => $credentials['username'],
            'client' => 'web',
            'ip' => $request->ip(),
            'successful' => $ok,
        ]);

        if (! $ok || ! $user) {
            RateLimiter::hit($accountKey, self::DECAY);
            RateLimiter::hit($addressKey, self::DECAY);

            // Rate limiter first, then notify after the response: this path is
            // unauthenticated and repeatable, and delivery is a synchronous
            // HTTP call. The cooldown keys on the address alone — keyed on the
            // username too, spraying names would mint a fresh key, and every
            // attempt would pay for its own delivery.
            app(AppriseNotifications::class)->sendAfterResponse(
                'console.login_failed',
                'Failed console login',
                'A web-console sign-in attempt failed.',
                'web-login:'.sha1($ip),
            );

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Invalid username or password.']);
        }

        // Clear only the account counter. The address counter deliberately
        // survives a success: an attacker who holds one valid credential must
        // not be able to reset the spraying budget by signing into it.
        RateLimiter::clear($accountKey);

        // Accounts created by the IdP hold a random unusable password, so this
        // branch is normally unreachable — it exists so that if one ever did
        // match, the account still cannot be entered by password.
        if ($user->isSsoProvisioned()) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'This account signs in through single sign-on.']);
        }

        // A linked account still awaiting approval must not get in by password
        // either, or the approval gate would be trivially bypassed.
        if ($user->isSsoPending()) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Your account is waiting for administrator approval.']);
        }

        // 2FA enrolled → stash a pending marker and hand off to the challenge.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('2fa', [
                'user_id' => $user->id,
                'at' => now()->timestamp,
                'remember' => $remember,
            ]);

            return redirect()->route('login.2fa');
        }

        // Emailed new-device code (PLAN D1). An `elseif` on purpose: it only
        // ever runs for users WITHOUT TOTP, so a sign-in raises at most one
        // challenge and the authenticator app always wins. SSO logins never
        // reach this method (OidcController has its own completeLogin), so
        // they are exempt without needing a guard here.
        if (LoginEmailVerification::required($user, $request)) {
            if ($this->startEmailVerification($user, $request, $remember)) {
                return redirect()->route('login.email');
            }

            // The code could not be sent. Sign-in verification is on, so letting
            // an ordinary user straight through would quietly disable the very
            // control the operator switched on. Instead the console closes to
            // everyone EXCEPT someone who can fix it — an administrator is let
            // in and RequireMailHealthy walks them to the mail settings.
            if (! $this->mayRepairMail($user)) {
                return back()
                    ->withInput($request->only('username'))
                    ->withErrors(['username' => 'Sign-in is temporarily unavailable: this console cannot send verification codes. Contact an administrator.']);
            }

            $request->session()->put('mail_repair', true);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    /** The emailed-code challenge screen (PLAN D1). */
    public function showEmailChallenge(Request $request): View|RedirectResponse
    {
        $user = $this->emailPendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.email-challenge', [
            'sentTo' => $this->maskEmail((string) $request->session()->get('email_verify.sent_to')),
        ]);
    }

    public function emailChallenge(Request $request): RedirectResponse
    {
        $user = $this->emailPendingUser($request);

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['code' => 'Your sign-in session expired. Please sign in again.']);
        }

        $request->validate(['code' => ['required', 'string']]);
        $code = preg_replace('/\D/', '', (string) $request->input('code')) ?? '';

        // Same budget as the TOTP step. Someone grinding codes here already
        // holds a valid password, so it alarms too.
        $throttleKey = 'email-verify:'.$user->id.'|'.$request->ip();
        $this->ensureNotRateLimited($throttleKey, 'code', self::MAX_PER_ACCOUNT, fn () => AlarmLog::console(
            AlarmLog::TYP_BRUTE_FORCE,
            ['ip' => $request->ip(), 'username' => $user->username, 'step' => 'email-verification', 'attempts' => self::MAX_PER_ACCOUNT],
        ));

        $pending = $request->session()->get('email_verify');
        $hash = (string) ($pending['code_hash'] ?? '');

        if ($hash === '' || ! Hash::check($code, $hash)) {
            RateLimiter::hit($throttleKey, self::DECAY);

            return back()->withErrors(['code' => 'That code is incorrect or has expired.']);
        }

        RateLimiter::clear($throttleKey);

        // Trust this browser so the next sign-in from it is uninterrupted.
        TrustedDevice::remember($user, $request);

        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->forget('email_verify');
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    /** Mint and send a fresh code, invalidating the previous one. */
    public function resendEmailCode(Request $request): RedirectResponse
    {
        $user = $this->emailPendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $resendKey = 'email-verify-resend:'.$user->id.'|'.$request->ip();
        $this->ensureNotRateLimited($resendKey, 'code', 3);
        RateLimiter::hit($resendKey, self::DECAY);

        $remember = (bool) $request->session()->get('email_verify.remember', false);

        if (! $this->startEmailVerification($user, $request, $remember)) {
            // The relay failed mid-challenge. Same rule as the login path: only
            // someone who can repair the relay gets in on a broken control.
            if (! $this->mayRepairMail($user)) {
                $request->session()->forget('email_verify');

                return redirect()->route('login')->withErrors([
                    'username' => 'Sign-in is temporarily unavailable: this console cannot send verification codes. Contact an administrator.',
                ]);
            }

            Auth::login($user, $remember);
            $request->session()->forget('email_verify');
            $request->session()->regenerate();
            $request->session()->put('mail_repair', true);

            return redirect()->route('settings', ['tab' => 'email']);
        }

        return back()->with('status', 'A new code is on its way.');
    }

    /**
     * Send a 6-digit code and stash the pending marker.
     *
     * Returns false when the relay refused the message: the caller then lets
     * the sign-in through rather than stranding the user at a challenge that
     * can never be answered.
     */
    private function startEmailVerification(User $user, Request $request, bool $remember): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $sent = app(MailSettings::class)->send(
            new LoginVerificationCode($code, $user->username, (string) $request->ip(), (int) (self::EMAIL_PENDING_TTL / 60)),
            (string) $user->email,
        );

        if (! $sent) {
            Log::warning('Sign-in verification email could not be sent; letting '.$user->username.' through.');

            LoginLog::create([
                'user_id' => $user->id,
                'username' => $user->username,
                'client' => 'web',
                'ip' => $request->ip(),
                'successful' => true,
                'note' => 'email-verification send failed',
            ]);

            return false;
        }

        $request->session()->put('email_verify', [
            'user_id' => $user->id,
            'at' => now()->timestamp,
            'remember' => $remember,
            // bcrypt, not sha256: a 6-digit sha256 is trivially brute-forced
            // offline if the session store (database in production) is read.
            'code_hash' => Hash::make($code),
            'sent_to' => (string) $user->email,
        ]);

        return true;
    }

    /** Resolve the pending email-verification user, or null if there is none. */
    private function emailPendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('email_verify');

        if (! is_array($pending) || empty($pending['user_id'])) {
            return null;
        }

        if (($pending['at'] ?? 0) + self::EMAIL_PENDING_TTL < now()->timestamp) {
            $request->session()->forget('email_verify');

            return null;
        }

        $user = User::find($pending['user_id']);

        return ($user && $user->is_active) ? $user : null;
    }

    /** j••••@example.com — enough to recognise, not enough to harvest. */
    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('•', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    /** The TOTP / recovery-code challenge screen. */
    public function showTwoFactorChallenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function twoFactorChallenge(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['code' => 'Your sign-in session expired. Please sign in again.']);
        }

        $request->validate(['code' => ['required', 'string']]);
        $code = trim((string) $request->input('code'));

        // Throttle the 2FA step: 5 tries per user+IP per minute. Someone
        // grinding second factors already holds a valid password, so this
        // alarms too — arguably the more urgent of the two.
        $throttleKey = '2fa:'.$user->id.'|'.$request->ip();
        $this->ensureNotRateLimited($throttleKey, 'code', self::MAX_PER_ACCOUNT, fn () => AlarmLog::console(
            AlarmLog::TYP_BRUTE_FORCE,
            ['ip' => $request->ip(), 'username' => $user->username, 'step' => 'two-factor', 'attempts' => self::MAX_PER_ACCOUNT],
        ));

        $pending = $request->session()->get('2fa');
        $remember = (bool) ($pending['remember'] ?? false);

        // A XXXXX-XXXXX shaped input is a recovery code; anything else a TOTP.
        if (TwoFactor::looksLikeRecoveryCode($code)) {
            $result = $this->consumeRecoveryCode($user, $code);
        } else {
            $result = $this->consumeTotp($user, $code);
        }

        if (! $result['ok']) {
            RateLimiter::hit($throttleKey, self::DECAY);

            return back()->withErrors(['code' => $result['message']]);
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, $remember);
        $request->session()->forget('2fa');
        $request->session()->regenerate();

        $redirect = redirect()->intended(route('overview'));
        if (! empty($result['warning'])) {
            $redirect->with('twofactor_warning', $result['warning']);
        }

        return $redirect;
    }

    public function logout(Request $request): RedirectResponse
    {
        // Read the SSO markers before the session is destroyed: ending the
        // session at the provider needs the ID token as a hint.
        $wasSso = (bool) $request->session()->get(OidcService::SESSION_PROVIDER);
        $idToken = $request->session()->get(OidcService::SESSION_ID_TOKEN);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($wasSso) {
            $target = $this->oidc->logoutUrl(
                is_string($idToken) ? $idToken : null,
                route('login'),
            );

            if ($target) {
                return redirect()->away($target);
            }
        }

        return redirect()->route('login');
    }

    /**
     * Resolve the pending-2FA user from the session, or null if there is no
     * valid, unexpired marker.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('2fa');
        if (! is_array($pending) || empty($pending['user_id'])) {
            return null;
        }

        if (($pending['at'] ?? 0) + self::PENDING_TTL < now()->timestamp) {
            $request->session()->forget('2fa');

            return null;
        }

        $user = User::find($pending['user_id']);

        return ($user && $user->is_active && $user->hasTwoFactorEnabled()) ? $user : null;
    }

    /**
     * Verify a TOTP code with the ±1 window AND the replay guard.
     *
     * @return array{ok:bool,message?:string}
     */
    private function consumeTotp(User $user, string $code): array
    {
        $secret = $user->totp_secret;
        $timestep = $secret ? TwoFactor::verify($secret, $code) : null;

        if ($timestep === null) {
            return ['ok' => false, 'message' => 'That code is incorrect or has expired.'];
        }

        // Replay guard: reject a code from a timestep already used (or older).
        if ($user->totp_last_timestep !== null && $timestep <= $user->totp_last_timestep) {
            return ['ok' => false, 'message' => 'That code has already been used. Wait for the next one.'];
        }

        $user->forceFill(['totp_last_timestep' => $timestep])->save();

        return ['ok' => true];
    }

    /**
     * Verify + burn a single-use recovery code. Warns when few remain.
     *
     * @return array{ok:bool,message?:string,warning?:string}
     */
    private function consumeRecoveryCode(User $user, string $code): array
    {
        $normalized = TwoFactor::normalizeRecoveryCode($code);

        $match = $user->recoveryCodes()->whereNull('used_at')->get()
            ->first(fn ($rc) => Hash::check($normalized, $rc->code_hash));

        if (! $match) {
            return ['ok' => false, 'message' => 'That recovery code is invalid or already used.'];
        }

        // Burn the code atomically: the conditional UPDATE only affects the row
        // if it is still unused, so two concurrent requests presenting the same
        // code cannot both succeed (closes the check-then-write race).
        $claimed = $user->recoveryCodes()
            ->whereKey($match->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($claimed === 0) {
            return ['ok' => false, 'message' => 'That recovery code is invalid or already used.'];
        }

        $remaining = $user->recoveryCodes()->whereNull('used_at')->count();
        $warning = $remaining <= TwoFactor::RECOVERY_LOW_THRESHOLD
            ? "You have {$remaining} recovery code(s) left. Regenerate them from Account → Two-Factor Authentication."
            : null;

        return ['ok' => true, 'warning' => $warning];
    }

    /**
     * May this user be let in while sign-in verification is unusable?
     *
     * Only someone who can actually change the mail settings — otherwise the
     * break-glass is just a way around the control. is_admin is checked
     * explicitly because a super-admin outranks the role matrix.
     */
    private function mayRepairMail(User $user): bool
    {
        return $user->is_admin || $user->consoleAllows('setting', 'rw');
    }

    /**
     * Reject and message a rate-limited step, raising an alarm the first time
     * a limiter trips.
     *
     * The alarm is written at most once per key per cooldown, so a sustained
     * attack produces one reviewable row rather than one per request — a log
     * nobody can read is the same as no log.
     *
     * @param  callable|null  $alarm  Raises the alarm for this limiter.
     */
    private function ensureNotRateLimited(string $key, string $field, int $max = self::MAX_PER_ACCOUNT, ?callable $alarm = null): void
    {
        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        // Cache::add is atomic, so concurrent blocked requests raise one alarm.
        if ($alarm !== null && Cache::add('alarm-raised:'.sha1($key), true, self::ALARM_COOLDOWN)) {
            $alarm();
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            $field => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }
}
