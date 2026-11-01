<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns verified OIDC claims into a console user (PLAN D3).
 *
 * Matching order is deliberate and is the security core of SSO:
 *
 *  1. `(iss, sub)` — the only pair an IdP promises is stable and unique. An
 *     account already linked to an identity is found this way and nothing about
 *     the email matters.
 *  2. The email, and only for first-time linking of a pre-existing local
 *     account. After that the account is pinned to (iss, sub) and the address
 *     stops deciding anything. The provider's `email_verified` claim is NOT
 *     consulted unless the operator opts in — see emailVerification().
 *  3. Just-in-time provisioning, subject to the operator's new-user policy.
 *
 * Linking never disables password login for an account that already had one:
 * `auth_provider` records where the account came from, so an administrator who
 * links their existing login keeps a way in if the IdP goes down. Only accounts
 * created BY the IdP are password-less.
 */
class OidcUserResolver
{
    /**
     * @param  array<string, mixed>  $claims
     * @return array{status: 'ok'|'pending'|'denied', user: ?User, message: string}
     */
    public function resolve(array $claims): array
    {
        $sub = trim((string) ($claims['sub'] ?? ''));
        $iss = trim((string) ($claims['iss'] ?? ''));

        if ($sub === '' || $iss === '') {
            return $this->deny(__('messages.idp_no_user'));
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verification = $this->emailVerification($claims);
        $emailVerified = $verification === 'verified';

        if (! $this->domainAllowed($email)) {
            return $this->deny(__('messages.email_domain_denied'));
        }

        // 1. Known identity.
        $user = User::query()
            ->where('oidc_iss', $iss)
            ->where('oidc_sub', $sub)
            ->first();

        if ($user) {
            return $this->admit($user, $email, $claims);
        }

        // 2. First-time linking against an existing local account.
        if ($email !== '') {
            $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($existing) {
                if (! $emailVerified) {
                    return $this->deny($this->verificationRefusal($verification));
                }

                // Someone else already owns this account's SSO identity.
                if ($existing->oidc_sub && $existing->oidc_sub !== $sub) {
                    return $this->deny(__('messages.sso_linked_other'));
                }

                $existing->forceFill([
                    'oidc_iss' => $iss,
                    'oidc_sub' => $sub,
                    'oidc_status' => $existing->oidc_status ?: 'active',
                ])->save();

                Log::info('OIDC: linked existing account', ['user' => $existing->username, 'iss' => $iss]);

                return $this->admit($existing, $email, $claims);
            }
        }

        // 3. Just-in-time provisioning.
        return $this->provision($iss, $sub, $email, $verification, $claims);
    }

    /**
     * Gate an existing user: disabled accounts and unapproved provisioned
     * accounts never reach a session.
     *
     * @param  array<string, mixed>  $claims
     * @return array{status: 'ok'|'pending'|'denied', user: ?User, message: string}
     */
    private function admit(User $user, string $email, array $claims): array
    {
        if ($user->oidc_status === 'pending') {
            return ['status' => 'pending', 'user' => null, 'message' =>
                __('messages.awaiting_approval')];
        }

        if (! $user->is_active) {
            return $this->deny(__('messages.account_disabled_contact'));
        }

        // Keep the profile roughly in sync with the directory, but never
        // overwrite a name an administrator set with an empty claim.
        $updates = [];
        $name = trim((string) ($claims['name'] ?? ''));

        if ($name !== '' && $name !== $user->name) {
            $updates['name'] = $name;
        }

        if ($email !== '' && strtolower((string) $user->email) !== $email) {
            $updates['email'] = $email;
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return ['status' => 'ok', 'user' => $user, 'message' => ''];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{status: 'ok'|'pending'|'denied', user: ?User, message: string}
     */
    private function provision(string $iss, string $sub, string $email, string $verification, array $claims): array
    {
        $policy = Setting::get('oidc_new_user_policy', 'deny');

        if ($policy !== 'pending' && $policy !== 'active') {
            return $this->deny(__('messages.no_matching_account'));
        }

        // Provisioning writes an email onto a brand-new account, which becomes
        // the linking key for any future identity. Requiring verification here
        // stops an unverified address from being planted for later takeover.
        if ($email !== '' && $verification !== 'verified') {
            return $this->deny($this->verificationRefusal($verification));
        }

        $status = $policy === 'pending' ? 'pending' : 'active';

        $user = new User;
        $user->forceFill([
            'username' => $this->uniqueUsername($claims, $email),
            'name' => trim((string) ($claims['name'] ?? '')) ?: null,
            'email' => $email ?: null,
            // Unusable by design: provisioned accounts have no password to type.
            'password' => Str::random(64),
            'is_admin' => Setting::get('oidc_default_admin', '0') === '1',
            'is_active' => true,
            'auth_provider' => 'oidc',
            'oidc_iss' => $iss,
            'oidc_sub' => $sub,
            'oidc_status' => $status,
        ])->save();

        $groupId = (int) Setting::get('oidc_default_group_id', '0');

        if ($groupId > 0) {
            $user->groups()->syncWithoutDetaching([$groupId]);
        }

        Log::info('OIDC: provisioned account', [
            'user' => $user->username,
            'status' => $status,
            'admin' => $user->is_admin,
        ]);

        if ($status === 'pending') {
            return ['status' => 'pending', 'user' => null, 'message' =>
                __('messages.account_created_pending')];
        }

        return ['status' => 'ok', 'user' => $user, 'message' => ''];
    }

    /**
     * Derive a console username from the claims, sanitised to the same shape as
     * a hand-created one and suffixed until it is free.
     *
     * @param  array<string, mixed>  $claims
     */
    private function uniqueUsername(array $claims, string $email): string
    {
        $candidate = trim((string) ($claims['preferred_username'] ?? ''))
            ?: trim((string) ($claims['name'] ?? ''))
            ?: Str::before($email, '@')
            ?: 'user';

        $base = trim(preg_replace('/_+/', '_', strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $candidate))), '_');
        $base = Str::limit($base !== '' ? $base : 'user', 45, '');

        $username = $base;
        $suffix = 1;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.'_'.$suffix;
            $suffix++;
        }

        return $username;
    }

    /** Is this email inside the operator's domain allowlist (empty = any)? */
    private function domainAllowed(string $email): bool
    {
        $configured = trim((string) Setting::get('oidc_allowed_domains', ''));

        if ($configured === '') {
            return true;
        }

        $domains = array_filter(array_map(
            fn ($d) => ltrim(strtolower(trim($d)), '@'),
            preg_split('/[,\s]+/', $configured) ?: [],
        ));

        if ($domains === []) {
            return true;
        }

        // An allowlist is configured, so an identity with no email cannot pass.
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        return in_array(Str::after($email, '@'), $domains, true);
    }

    /**
     * Should this sign-in be refused over the provider's email verification?
     *
     * Normally no. `email_verified` is optional in OpenID Connect and the two
     * most common self-hosted providers make it useless as a signal: Microsoft
     * Entra ID never sends it, and Authentik deliberately sends `false` for
     * everyone because it cannot confirm anyone owns their address. Refusing on
     * it locked both out entirely.
     *
     * What actually protects an account here is the identity keying, not this
     * claim. A console account is matched on `(iss, sub)` first and an email is
     * consulted only for the ONE-TIME link; after that the account is pinned to
     * the provider identity and the address no longer decides anything. The
     * domain allowlist and the new-user policy cover the rest.
     *
     * So the claim is ignored unless an operator opts into strictness, which is
     * worth having only when federating with a provider whose users can choose
     * their own address.
     *
     * @param  array<string, mixed>  $claims
     * @return 'verified'|'unverified'|'unknown'
     */
    private function emailVerification(array $claims): string
    {
        // Not enforcing: nothing the provider says can refuse the sign-in.
        if (Setting::get('oidc_require_verified_email', '0') !== '1') {
            return 'verified';
        }

        if (! array_key_exists('email_verified', $claims) || $claims['email_verified'] === null) {
            return 'unknown';
        }

        $value = $claims['email_verified'];

        // Providers send booleans as true, "true" or 1 — accept all three.
        $isTrue = $value === true
            || $value === 1
            || (is_string($value) && strtolower($value) === 'true')
            || $value === '1';

        return $isTrue ? 'verified' : 'unverified';
    }

    /** Tell the operator what actually went wrong, and what to do about it. */
    private function verificationRefusal(string $verification): string
    {
        if ($verification === 'unknown') {
            return __('messages.email_verification_unknown');
        }

        return __('messages.email_unverified');
    }

    /** @return array{status: 'denied', user: null, message: string} */
    private function deny(string $message): array
    {
        return ['status' => 'denied', 'user' => null, 'message' => $message];
    }
}
