<?php

namespace App\Livewire;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\ConsoleAudit;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Account → Two-Factor Authentication (PLAN B6).
 *
 * Enrollment wizard: the candidate secret lives in the SESSION (never the DB
 * or persisted Livewire state) until a valid TOTP confirms it; only then is it
 * encrypted-at-rest, 2FA enabled, and one set of 10 recovery codes shown once.
 * Disabling requires re-entering the account password.
 */
class TwoFactorSettings extends Component
{
    private const SESSION_SECRET = '2fa_setup_secret';

    /** Wizard visible (secret generated, awaiting confirmation). */
    public bool $settingUp = false;

    /** 6-digit code entered to confirm enrollment. */
    public string $confirmCode = '';

    /** Password required to turn 2FA off. */
    public string $disablePassword = '';

    /**
     * Plaintext recovery codes surfaced exactly once after enable/regenerate.
     *
     * @var array<int,string>
     */
    #[Locked]
    public array $recoveryCodes = [];

    public function startSetup(): void
    {
        $this->reset('confirmCode', 'recoveryCodes');
        session()->put(self::SESSION_SECRET, TwoFactor::generateSecret());
        $this->settingUp = true;
    }

    public function cancelSetup(): void
    {
        session()->forget(self::SESSION_SECRET);
        $this->reset('confirmCode');
        $this->settingUp = false;
    }

    public function confirmSetup(): void
    {
        $user = $this->user();
        if ($user->hasTwoFactorEnabled()) {
            return;
        }

        $this->validate([
            'confirmCode' => ['required', 'string'],
        ]);

        $secret = (string) session(self::SESSION_SECRET);
        $timestep = $secret !== '' ? TwoFactor::verify($secret, $this->confirmCode) : null;

        if ($timestep === null) {
            $this->addError('confirmCode', __('messages.totp_wrong_try_current'));

            return;
        }

        // Persist: encrypt the secret at rest, mark enabled, seed the replay
        // pointer with the timestep just accepted.
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'totp_confirmed_at' => now(),
            'totp_last_timestep' => $timestep,
        ])->save();

        session()->forget(self::SESSION_SECRET);
        $this->recoveryCodes = $this->issueRecoveryCodes($user);
        $this->settingUp = false;
        $this->reset('confirmCode');

        ConsoleAudit::record('user.2fa-enable', 'Enabled two-factor authentication', 'user', $user->username);
    }

    public function regenerateRecoveryCodes(): void
    {
        $user = $this->user();
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        $this->validate([
            'disablePassword' => ['required', 'string'],
        ], [], ['disablePassword' => 'password']);

        if (! Hash::check($this->disablePassword, $user->password)) {
            $this->addError('disablePassword', __('messages.password_incorrect'));

            return;
        }

        $this->reset('disablePassword');
        $this->recoveryCodes = $this->issueRecoveryCodes($user);

        ConsoleAudit::record('user.2fa-recovery-regenerate', 'Regenerated 2FA recovery codes', 'user', $user->username);
    }

    public function disable(): void
    {
        $user = $this->user();
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        // Never let an enforced user disable their way out of the requirement.
        if (RequireTwoFactor::isRequiredFor($user)) {
            $this->addError('disablePassword', __('messages.twofactor_cannot_disable'));

            return;
        }

        $this->validate([
            'disablePassword' => ['required', 'string'],
        ], [], ['disablePassword' => 'password']);

        if (! Hash::check($this->disablePassword, $user->password)) {
            $this->addError('disablePassword', __('messages.password_incorrect'));

            return;
        }

        $user->clearTwoFactor();
        $this->reset('disablePassword', 'recoveryCodes');

        ConsoleAudit::record('user.2fa-disable', 'Disabled two-factor authentication', 'user', $user->username);
    }

    public function dismissRecoveryCodes(): void
    {
        $this->reset('recoveryCodes');
    }

    /** Hash + store a fresh set of recovery codes, returning the plaintext. */
    private function issueRecoveryCodes(User $user): array
    {
        $user->recoveryCodes()->delete();

        $codes = TwoFactor::generateRecoveryCodes();
        foreach ($codes as $code) {
            $user->recoveryCodes()->create([
                'code_hash' => Hash::make($code),
            ]);
        }

        return $codes;
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render()
    {
        $user = $this->user();
        $secret = $this->settingUp ? (string) session(self::SESSION_SECRET) : '';

        return view('livewire.two-factor-settings', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'confirmedAt' => $user->totp_confirmed_at,
            'remaining' => $user->hasTwoFactorEnabled() ? $user->unusedRecoveryCodesCount() : 0,
            'required' => RequireTwoFactor::isRequiredFor($user),
            'secret' => $secret,
            'qrSvg' => $secret !== '' ? TwoFactor::qrSvg($secret, $user->username) : null,
        ]);
    }
}
