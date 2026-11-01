<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;

/**
 * Console 2FA helpers (PLAN B6): TOTP secret/URI/QR generation, code
 * verification with an explicit ±1 timestep window that also returns the
 * accepted timestep for the replay guard, and recovery-code generation.
 *
 * No build step — the QR is rendered to inline SVG server-side (bacon-qr-code)
 * and the TOTP maths run in pure PHP (otphp).
 */
class TwoFactor
{
    public const ISSUER = 'CortenDesk';

    public const PERIOD = 30;

    /** Number of recovery codes issued per set. */
    public const RECOVERY_COUNT = 10;

    /** Warn the user when this many (or fewer) recovery codes remain. */
    public const RECOVERY_LOW_THRESHOLD = 2;

    /** Generate a fresh random Base32 TOTP secret. */
    public static function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    /** Build a configured TOTP instance for a given secret + account label. */
    public static function totp(string $secret, string $label): TOTP
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(self::PERIOD);
        $totp->setLabel($label);
        $totp->setIssuer(self::ISSUER);

        return $totp;
    }

    /** otpauth:// provisioning URI (what the QR encodes). */
    public static function provisioningUri(string $secret, string $label): string
    {
        return self::totp($secret, $label)->getProvisioningUri();
    }

    /** Render the provisioning URI to a self-contained inline SVG string. */
    public static function qrSvg(string $secret, string $label): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200, 1),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString(
            self::provisioningUri($secret, $label)
        );
    }

    /**
     * Verify a 6-digit TOTP against a secret with a ±1 timestep window.
     * Returns the accepted timestep (int) on success, or null on failure.
     * The caller enforces the replay guard against the user's stored
     * last-accepted timestep.
     *
     * @param  int|null  $now  unix timestamp override (tests)
     */
    public static function verify(string $secret, string $code, ?int $now = null): ?int
    {
        $code = trim($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $totp = self::totp($secret, 'x');
        // Use Carbon's clock so Carbon::setTestNow() makes verification
        // deterministic in tests.
        $now ??= now()->timestamp;
        $current = intdiv($now, self::PERIOD);

        // Check the previous, current and next timestep. Return the highest
        // matching timestep so an older-but-still-in-window code can't be
        // used to roll the replay pointer backwards.
        foreach ([1, 0, -1] as $offset) {
            $timestep = $current + $offset;
            if ($timestep < 0) {
                continue;
            }
            if (hash_equals($totp->at($timestep * self::PERIOD), $code)) {
                return $timestep;
            }
        }

        return null;
    }

    /**
     * Check a user's TOTP code and burn its timestep. Used by the console
     * challenge and the RustDesk client sign-in alike.
     *
     * Returns null on success, or the message to show. The pointer moves in
     * one conditional UPDATE, so two requests carrying the same code cannot
     * both pass the replay guard.
     */
    public static function consumeTotp(User $user, string $code): ?string
    {
        $secret = $user->totp_secret;
        $timestep = $secret ? self::verify($secret, $code) : null;

        if ($timestep === null) {
            return __('messages.code_incorrect_or_expired');
        }

        $claimed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('totp_last_timestep')->orWhere('totp_last_timestep', '<', $timestep))
            ->update(['totp_last_timestep' => $timestep]);

        if ($claimed === 0) {
            return __('messages.code_already_used');
        }

        $user->forceFill(['totp_last_timestep' => $timestep])->syncOriginalAttribute('totp_last_timestep');

        return null;
    }

    /**
     * Generate a set of recovery codes in XXXXX-XXXXX format (~50 bits each).
     * Returns the plaintext codes; the caller hashes + persists them.
     *
     * @return array<int,string>
     */
    public static function generateRecoveryCodes(int $count = self::RECOVERY_COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = self::randomBlock().'-'.self::randomBlock();
        }

        return $codes;
    }

    /** Does a string look like a recovery code (vs a 6-digit TOTP)? */
    public static function looksLikeRecoveryCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/i', trim($value));
    }

    /** Normalize a recovery code for comparison (uppercase, trimmed). */
    public static function normalizeRecoveryCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    /** A 5-char block from an unambiguous alphabet (no 0/O/1/I). */
    private static function randomBlock(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $block = '';
        for ($i = 0; $i < 5; $i++) {
            $block .= $alphabet[random_int(0, $max)];
        }

        return $block;
    }
}
