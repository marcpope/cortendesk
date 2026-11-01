<?php

namespace App\Services;

use App\Models\Setting;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenID Connect authorization-code flow with PKCE (PLAN D3).
 *
 * Deliberately dependency-light: Laravel's HTTP client does the transport and
 * firebase/php-jwt verifies the ID token against the provider's JWKS. Everything
 * else (discovery, PKCE, state, nonce) is explicit here so the security-relevant
 * steps are auditable in one file.
 *
 * Split-host deployments are a first-class case. The console often reaches the
 * IdP over an internal address (container network, private DNS) while the user's
 * browser reaches it over a public one. `oidc_public_base_url` rewrites only the
 * two URLs the browser is sent to (authorize, end-session); token, JWKS and
 * userinfo calls stay on the back channel. The `iss` claim is always validated
 * against the issuer the discovery document declares, so rewriting the browser
 * origin never weakens issuer checking.
 */
class OidcService
{
    /** Discovery + JWKS cache lifetime. Short enough to pick up key rotation. */
    private const DISCOVERY_TTL = 300;

    /** Clock skew allowed when validating exp/iat/nbf, in seconds. */
    private const LEEWAY = 60;

    /** Session keys holding the in-flight authorization request. */
    public const SESSION_STATE = 'oidc.state';

    public const SESSION_NONCE = 'oidc.nonce';

    public const SESSION_VERIFIER = 'oidc.verifier';

    /** Session keys kept for the lifetime of an SSO session. */
    public const SESSION_PROVIDER = 'oidc.authenticated';

    public const SESSION_ID_TOKEN = 'oidc.id_token';

    /**
     * Is SSO switched on and configured well enough to attempt?
     *
     * The env kill switch is checked first and cannot be overridden from the
     * database: it is the break-glass path when a misconfigured IdP has locked
     * everyone out of the console.
     */
    public function isEnabled(): bool
    {
        if (config('cortendesk.oidc_disabled')) {
            return false;
        }

        return $this->setting('oidc_enabled') === '1' && $this->isConfigured();
    }

    /** Are the three fields required to talk to a provider all present? */
    public function isConfigured(): bool
    {
        return $this->setting('oidc_discovery_url') !== ''
            && $this->setting('oidc_client_id') !== ''
            && $this->clientSecret() !== '';
    }

    public function buttonLabel(): string
    {
        $label = $this->setting('oidc_button_label');

        return $label !== '' ? $label : 'Sign in with SSO';
    }

    /**
     * Provider name advertised to the RustDesk client (spec §2).
     *
     * The client renders its button as "Continue with {name}" and capitalises
     * the first letter, so the whole label must be a bare provider name — send
     * "sso" and the user reads "Continue with Sso". The console's button label
     * is the operator's own wording, so strip the leading verb from it:
     * "Sign in with Keycloak" → "Continue with Keycloak".
     */
    public function clientProviderName(): string
    {
        $name = trim(preg_replace(
            '/^\s*(sign[- ]?in|log[- ]?in|login|continue|authenticate)\s+with\s*/i',
            '',
            $this->buttonLabel(),
        ) ?? '');

        return $name !== '' ? $name : 'SSO';
    }

    /**
     * Should the username/password form be hidden?
     *
     * Only ever true while SSO is actually usable — a broken or disabled IdP
     * silently restores password login rather than locking the console. This is
     * the single most important safety property in this file.
     */
    public function localLoginDisabled(): bool
    {
        return $this->isEnabled() && $this->setting('oidc_disable_local_login') === '1';
    }

    /**
     * Build the authorize URL and stash state / nonce / PKCE verifier in the
     * session. The caller redirects the browser to the returned URL.
     */
    public function authorizationUrl(Request $request, string $redirectUri): string
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_NONCE, $nonce);
        $request->session()->put(self::SESSION_VERIFIER, $verifier);

        return $this->buildAuthorizationUrl($redirectUri, $state, $nonce, $verifier);
    }

    /**
     * Build an authorize URL from caller-supplied state/nonce/verifier.
     *
     * The console flow keeps those in the session; the RustDesk client flow
     * can't (the request that starts it comes from the app, not a browser), so
     * it persists them on the pending-authorization row instead. Both go
     * through here, so PKCE and nonce handling are identical either way.
     */
    public function buildAuthorizationUrl(string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $discovery = $this->discovery();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting('oidc_client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        $endpoint = $this->browserFacing($discovery['authorization_endpoint'] ?? '');

        if ($endpoint === '') {
            throw new OidcException(__('messages.oidc_no_auth_endpoint'));
        }

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchange the callback code for tokens and return the verified claims.
     *
     * @return array{claims: array<string, mixed>, id_token: string}
     */
    public function exchange(Request $request, string $redirectUri): array
    {
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $nonce = $request->session()->pull(self::SESSION_NONCE);
        $verifier = $request->session()->pull(self::SESSION_VERIFIER);

        if ($request->query('error')) {
            throw new OidcException(__('messages.idp_rejected_detail', ['detail' => (string) $request->query('error_description', $request->query('error'))]));
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '') {
            throw new OidcException(__('messages.oidc_no_code'));
        }

        // Constant-time compare, and treat a missing session value as a failure
        // rather than a match — this is the CSRF guard for the callback.
        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $state)) {
            throw new OidcException(__('messages.oidc_state_invalid'));
        }

        return $this->exchangeCode($code, $redirectUri, (string) $verifier, is_string($nonce) ? $nonce : null);
    }

    /**
     * Redeem an authorization code and return the verified claims.
     *
     * Session-free so both the console and the RustDesk client flow can use it.
     * The caller is responsible for having validated `state` first.
     *
     * @return array{claims: array<string, mixed>, id_token: string}
     */
    public function exchangeCode(string $code, string $redirectUri, string $verifier, ?string $nonce): array
    {
        $discovery = $this->discovery();

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($discovery['token_endpoint'] ?? '', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $this->setting('oidc_client_id'),
                    'client_secret' => $this->clientSecret(),
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException) {
            throw new OidcException(__('messages.oidc_unreachable'));
        }

        if (! $response->successful()) {
            throw new OidcException(__('messages.oidc_token_exchange_failed', ['status' => $response->status()]));
        }

        $idToken = (string) ($response->json('id_token') ?? '');

        if ($idToken === '') {
            throw new OidcException(__('messages.oidc_no_id_token'));
        }

        $claims = $this->verifyIdToken($idToken, $nonce);

        // Claims can live in either the ID token or userinfo depending on the
        // provider's mappers — Keycloak, Authentik and Entra all put
        // preferred_username in the ID token but not always in userinfo, and
        // some providers do the reverse. Merge, letting the signed ID token win.
        $claims = array_merge(
            $this->userInfo((string) ($response->json('access_token') ?? '')),
            $claims,
        );

        return ['claims' => $claims, 'id_token' => $idToken];
    }

    /**
     * Verify the ID token's signature, issuer, audience, expiry and nonce.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, ?string $expectedNonce): array
    {
        $discovery = $this->discovery();

        JWT::$leeway = self::LEEWAY;

        $header = $this->decodeSegment($idToken, 0);
        $alg = (string) ($header['alg'] ?? '');

        // The header is attacker-controlled — it is the unverified half of the
        // token — so it decides nothing on its own. The provider publishes what
        // it signs with, and anything else is refused before we touch a key.
        $accepted = $this->acceptedSigningAlgorithms($discovery);

        if (! in_array($alg, $accepted, true)) {
            throw new OidcException(
                $alg === '' ? __('messages.oidc_bad_alg') : __('messages.oidc_bad_alg_named', ['alg' => $alg])
            );
        }

        try {
            if (str_starts_with($alg, 'HS')) {
                // Symmetric signing: the shared client secret is the key.
                $claims = JWT::decode($idToken, new Key($this->clientSecret(), $alg));
            } else {
                // The second argument is the algorithm to assume for keys that
                // do not name one. "alg" is optional in a JWK (RFC 7517 §4.4)
                // and Microsoft Entra omits it on every key, which without this
                // fails as 'JWK must contain an "alg" parameter' (#36). It is
                // safe to pass the header value here only because $accepted has
                // already constrained it to something the provider advertises.
                $claims = JWT::decode($idToken, JWK::parseKeySet($this->jwks(), $alg));
            }
        } catch (\Throwable $e) {
            throw new OidcException(__('messages.oidc_verification_failed', ['error' => $e->getMessage()]));
        }

        $claims = json_decode(json_encode($claims), true);

        $issuer = (string) ($discovery['issuer'] ?? '');

        if ($issuer === '' || ! hash_equals($issuer, (string) ($claims['iss'] ?? ''))) {
            throw new OidcException(__('messages.oidc_wrong_issuer'));
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (! in_array($this->setting('oidc_client_id'), array_map('strval', $audiences), true)) {
            throw new OidcException(__('messages.oidc_wrong_client'));
        }

        // Replay guard: the nonce ties this token to the authorize request we
        // started. Providers echo it back for the code flow; if we sent one it
        // must come back identical.
        if ($expectedNonce !== null && $expectedNonce !== '') {
            if (! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
                throw new OidcException(__('messages.oidc_nonce_mismatch'));
            }
        }

        if (empty($claims['sub'])) {
            throw new OidcException(__('messages.oidc_no_subject'));
        }

        return $claims;
    }

    /** Build the RP-initiated logout URL, or null when not enabled/available. */
    public function logoutUrl(?string $idToken, string $postLogoutUri): ?string
    {
        if (! $this->isEnabled() || $this->setting('oidc_logout_enabled') !== '1') {
            return null;
        }

        try {
            $discovery = $this->discovery();
        } catch (OidcException) {
            // A dead IdP must never block signing out locally.
            return null;
        }

        $endpoint = $this->browserFacing((string) ($discovery['end_session_endpoint'] ?? ''));

        if ($endpoint === '') {
            return null;
        }

        $params = ['post_logout_redirect_uri' => $postLogoutUri];

        // id_token_hint lets the IdP end the right session without prompting.
        if ($idToken) {
            $params['id_token_hint'] = $idToken;
        } else {
            $params['client_id'] = $this->setting('oidc_client_id');
        }

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query($params);
    }

    /**
     * Fetch (and cache) the provider's discovery document.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $configured = $this->setting('oidc_discovery_url');

        if ($configured === '') {
            throw new OidcException(__('messages.oidc_no_url'));
        }

        $url = $this->discoveryUrl($configured);

        $document = $this->cached('oidc:discovery:'.sha1($url), function () use ($url) {
            // Unreachable reads as unreadable: a message, not a 500 on the
            // sign-in path.
            try {
                $response = Http::timeout(10)->get($url);
            } catch (ConnectionException $e) {
                Log::warning('OIDC: provider unreachable', ['url' => $url, 'error' => $e->getMessage()]);

                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) && ! empty($json['issuer']) ? $json : null;
        });

        if (! is_array($document)) {
            throw new OidcException(__('messages.oidc_config_unreadable', ['url' => $url]));
        }

        return $document;
    }

    /** Clear cached discovery/JWKS — called when settings change. */
    public function forgetCaches(): void
    {
        $configured = $this->setting('oidc_discovery_url');

        if ($configured === '') {
            return;
        }

        $url = $this->discoveryUrl($configured);

        try {
            Cache::forget('oidc:discovery:'.sha1($url));
            Cache::forget('oidc:jwks:'.sha1($url));
        } catch (\Throwable) {
            // Nothing cached is still a correct state to be in.
        }
    }

    /**
     * Read through the cache, but never let the cache itself break sign-in.
     *
     * The file cache store throws when its directory is unwritable (a
     * read-only volume, a deploy that left storage/ owned by the wrong user,
     * a container mid-start). That must degrade to an uncached fetch on every
     * request — slower, but working — rather than a 500 on the login path.
     * Failures are never cached, so a provider that recovers is picked up at
     * once instead of being remembered as broken.
     */
    private function cached(string $key, callable $fetch): mixed
    {
        try {
            $hit = Cache::get($key);

            if ($hit !== null) {
                return $hit;
            }
        } catch (\Throwable $e) {
            Log::warning('OIDC: cache unreadable, fetching live', ['error' => $e->getMessage()]);
        }

        $value = $fetch();

        if ($value !== null) {
            try {
                Cache::put($key, $value, self::DISCOVERY_TTL);
            } catch (\Throwable $e) {
                Log::warning('OIDC: cache unwritable, continuing uncached', ['error' => $e->getMessage()]);
            }
        }

        return $value;
    }

    /**
     * Probe the provider for the settings screen's "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        try {
            $this->forgetCaches();
            $discovery = $this->discovery();
            $jwks = $this->jwks();
        } catch (OidcException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $keys = count($jwks['keys'] ?? []);

        return [
            'ok' => true,
            'message' => __('messages.oidc_connected', ['issuer' => $discovery['issuer'], 'keys' => $keys]),
        ];
    }

    /**
     * Algorithms this provider is allowed to have signed the ID token with.
     *
     * OpenID Connect requires providers to publish
     * id_token_signing_alg_values_supported, and RS256 is the algorithm every
     * provider must implement — so that is the fallback when a discovery
     * document leaves it out.
     *
     * Pinning to this list is what keeps the token header from choosing its own
     * verification: without it, a token claiming HS256 would be checked against
     * the client secret even on a provider that only ever signs with RS256.
     *
     * @param  array<string, mixed>  $discovery
     * @return list<string>
     */
    private function acceptedSigningAlgorithms(array $discovery): array
    {
        $advertised = $discovery['id_token_signing_alg_values_supported'] ?? null;

        if (! is_array($advertised) || $advertised === []) {
            return ['RS256'];
        }

        $algorithms = array_values(array_filter(
            array_map('strval', $advertised),
            fn (string $alg) => $alg !== '' && $alg !== 'none',
        ));

        return $algorithms === [] ? ['RS256'] : $algorithms;
    }

    /**
     * The provider's signing keys.
     *
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        $discovery = $this->discovery();
        $url = $this->discoveryUrl($this->setting('oidc_discovery_url'));

        $jwks = $this->cached('oidc:jwks:'.sha1($url), function () use ($discovery) {
            try {
                $response = Http::timeout(10)->get((string) ($discovery['jwks_uri'] ?? ''));
            } catch (ConnectionException) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) && ! empty($json['keys']) ? $json : null;
        });

        if (! is_array($jwks)) {
            throw new OidcException(__('messages.oidc_no_keys'));
        }

        return $jwks;
    }

    /**
     * Optional userinfo lookup. Best-effort: a provider that returns nothing
     * here is fine as long as the ID token carried the claims we need.
     *
     * @return array<string, mixed>
     */
    private function userInfo(string $accessToken): array
    {
        if ($accessToken === '') {
            return [];
        }

        $endpoint = (string) ($this->discovery()['userinfo_endpoint'] ?? '');

        if ($endpoint === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)->withToken($accessToken)->get($endpoint);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** Accept either a bare issuer URL or a full .well-known URL. */
    private function discoveryUrl(string $configured): string
    {
        $configured = rtrim(trim($configured), '/');

        if (str_contains($configured, '/.well-known/')) {
            return $configured;
        }

        return $configured.'/.well-known/openid-configuration';
    }

    /**
     * Rewrite a provider URL onto the browser-facing origin when the operator
     * configured one (split-host deployments). Path and query are preserved.
     */
    private function browserFacing(string $url): string
    {
        $public = rtrim($this->setting('oidc_public_base_url'), '/');

        if ($public === '' || $url === '') {
            return $url;
        }

        $parts = parse_url($url);
        $publicParts = parse_url($public);

        if (! is_array($parts) || ! is_array($publicParts) || empty($publicParts['host'])) {
            return $url;
        }

        $scheme = $publicParts['scheme'] ?? 'https';
        $host = $publicParts['host'];
        $port = isset($publicParts['port']) ? ':'.$publicParts['port'] : '';
        $prefix = rtrim($publicParts['path'] ?? '', '/');

        return $scheme.'://'.$host.$port.$prefix.($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** RFC 7636 S256 challenge. */
    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Decode one segment of a JWT without verifying — used only to read the
     * header's `alg` so we know which key type to verify with.
     *
     * @return array<string, mixed>
     */
    private function decodeSegment(string $jwt, int $index): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new OidcException(__('messages.oidc_malformed_token'));
        }

        $decoded = json_decode(
            base64_decode(strtr($segments[$index], '-_', '+/')) ?: '',
            true,
        );

        return is_array($decoded) ? $decoded : [];
    }

    public function scopes(): string
    {
        $scopes = $this->setting('oidc_scopes');

        return $scopes !== '' ? $scopes : 'openid email profile';
    }

    /** Decrypt the stored client secret, tolerating a plaintext legacy value. */
    private function clientSecret(): string
    {
        $stored = $this->setting('oidc_client_secret');

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
