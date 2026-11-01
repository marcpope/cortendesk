<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetLink;
use App\Models\LoginLog;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\MailSettings;
use App\Services\OidcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Self-service password reset.
 *
 * Only reachable when a relay is configured — without one the link could never
 * arrive, and offering a dead "forgot password" is worse than offering none.
 */
class PasswordResetController extends Controller
{
    public function __construct(
        private readonly MailSettings $mail,
        private readonly OidcService $oidc,
    ) {}

    public function showRequest(): View|RedirectResponse
    {
        if (! $this->available()) {
            return redirect()->route('login');
        }

        return view('auth.password-request');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        if (! $this->available()) {
            return redirect()->route('login');
        }

        $request->validate(['login' => ['required', 'string', 'max:255']]);
        $login = trim((string) $request->input('login'));

        $user = User::query()
            ->where('username', $login)
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($login)])
            ->first();

        // Everything below returns the SAME message. Whether an account exists,
        // is disabled, has no address or signs in through SSO must not be
        // distinguishable from the outside — the form would otherwise be an
        // account oracle for anyone who can reach the login page.
        if ($user && $this->mayReset($user)) {
            $this->dispatchLink($user, $request);
        } elseif ($user) {
            Log::info('Password reset refused', ['user' => $user->username, 'reason' => $this->refusal($user)]);
        }

        return back()->with('status', __('messages.reset_neutral'));
    }

    public function showReset(Request $request, string $token): View|RedirectResponse
    {
        if (! $this->available() || ! PasswordReset::findValid($token)) {
            return redirect()->route('login')
                ->withErrors(['username' => __('messages.reset_invalid')]);
        }

        return view('auth.password-reset', ['token' => $token]);
    }

    public function reset(Request $request, string $token): RedirectResponse
    {
        if (! $this->available()) {
            return redirect()->route('login');
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reset = PasswordReset::findValid($token);

        if (! $reset || ! $reset->user || ! $this->mayReset($reset->user)) {
            return redirect()->route('login')
                ->withErrors(['username' => __('messages.reset_invalid')]);
        }

        // Burn the link BEFORE changing anything: if two submissions race, only
        // the one that claims the row proceeds.
        if (! $reset->claim()) {
            return redirect()->route('login')
                ->withErrors(['username' => __('messages.reset_used')]);
        }

        $user = $reset->user;
        $user->forceFill(['password' => $request->input('password')])->save();

        // A reset is the recovery path for a compromised account, so it must
        // also end every session and client token the attacker may hold. This
        // is the same revocation an admin's "force logout" performs.
        $user->revokeAllAccess();

        LoginLog::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'client' => 'web',
            'ip' => $request->ip(),
            'successful' => true,
            'note' => 'password reset',
        ]);

        return redirect()->route('login')
            ->with('status', __('messages.password_reset_done'));
    }

    /** Send the link. Failures are logged, never surfaced (see NEUTRAL). */
    private function dispatchLink(User $user, Request $request): void
    {
        [$reset, $plain] = PasswordReset::issue($user, $request->ip());

        try {
            $this->mail->apply();
            Mail::to($user->email)->send(new PasswordResetLink(
                $user,
                route('password.reset', ['token' => $plain]),
                PasswordReset::TTL_MINUTES,
                $request->ip(),
            ));
        } catch (\Throwable $e) {
            // Retire the token we just minted — a link nobody received should
            // not sit valid for an hour.
            $reset->claim();
            Log::warning('Password reset email failed', ['user' => $user->username, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Can this account be reset by email at all?
     *
     * A provisioned SSO account has no usable password to reset, and a disabled
     * account must not be recoverable by its holder — that is the point of
     * disabling it.
     */
    private function mayReset(User $user): bool
    {
        return $user->is_active
            && ! $user->isSsoProvisioned()
            && ! $user->isSsoPending()
            && trim((string) $user->email) !== '';
    }

    private function refusal(User $user): string
    {
        return match (true) {
            ! $user->is_active => 'account disabled',
            $user->isSsoProvisioned() => 'SSO-provisioned account has no password',
            $user->isSsoPending() => 'account awaiting approval',
            default => 'no email address on the account',
        };
    }

    /**
     * Reset is offered only with a working relay, and never while password
     * sign-in is switched off — resetting a password you cannot use is noise.
     */
    private function available(): bool
    {
        return $this->mail->isEnabled() && ! $this->oidc->localLoginDisabled();
    }
}
