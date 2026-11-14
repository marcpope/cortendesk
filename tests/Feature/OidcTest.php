<?php

use App\Livewire\UserList;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\OidcException;
use App\Services\OidcService;
use App\Services\OidcUserResolver;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
| A throwaway RSA keypair stands in for the provider's signing key, so the
| tests exercise real signature verification rather than a stubbed verifier.
*/

const OIDC_ISSUER = 'https://idp.test/realms/cortendesk';
const OIDC_CLIENT = 'cortendesk-console';
const OIDC_KID = 'test-key-1';
// php-jwt refuses HMAC keys shorter than the hash output.
const OIDC_HS_SECRET = 'a-shared-client-secret-long-enough-for-hs256';

/** Generate (once per test) an RSA keypair and the matching JWKS document. */
function oidcKeys(): array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);

    $base64url = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return $cached = [
        'private' => $privateKey,
        'jwks' => ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => OIDC_KID,
            'n' => $base64url($details['rsa']['n']),
            'e' => $base64url($details['rsa']['e']),
        ]]],
    ];
}

/** Mint a signed ID token, with sensible defaults that individual tests override. */
function oidcIdToken(array $overrides = []): string
{
    $claims = array_merge([
        'iss' => OIDC_ISSUER,
        'aud' => OIDC_CLIENT,
        'sub' => 'subject-abc-123',
        'exp' => time() + 300,
        'iat' => time(),
        'nonce' => 'test-nonce',
        'email' => 'ssouser@example.com',
        'email_verified' => true,
        'preferred_username' => 'ssouser',
        'name' => 'Sso User',
    ], $overrides);

    return JWT::encode($claims, oidcKeys()['private'], 'RS256', OIDC_KID);
}

/** Write a complete, working SSO configuration into settings. */
function configureOidc(array $overrides = []): void
{
    $settings = array_merge([
        'oidc_enabled' => '1',
        'oidc_discovery_url' => OIDC_ISSUER,
        'oidc_client_id' => OIDC_CLIENT,
        'oidc_client_secret' => Crypt::encryptString('test-client-secret-123'),
        'oidc_new_user_policy' => 'deny',
    ], $overrides);

    foreach ($settings as $key => $value) {
        Setting::put($key, $value);
    }
}

/** Fake the provider's discovery, JWKS and token endpoints. */
function fakeProvider(array $tokenResponse = [], array $userInfo = []): void
{
    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'authorization_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
            'userinfo_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/userinfo',
            'end_session_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/logout',
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
        OIDC_ISSUER.'/protocol/openid-connect/token' => Http::response(array_merge([
            'access_token' => 'access-token-value',
            'id_token' => oidcIdToken(),
            'token_type' => 'Bearer',
        ], $tokenResponse)),
        OIDC_ISSUER.'/protocol/openid-connect/userinfo' => Http::response($userInfo),
    ]);
}

/** Drive the callback with a session that matches a real authorize request. */
function callbackWith(array $query = [], array $session = []): TestResponse
{
    return test()->withSession(array_merge([
        OidcService::SESSION_STATE => 'state-value',
        OidcService::SESSION_NONCE => 'test-nonce',
        OidcService::SESSION_VERIFIER => 'verifier-value',
    ], $session))->get(route('login.oidc.callback', array_merge([
        'code' => 'auth-code',
        'state' => 'state-value',
    ], $query)));
}

// --- Login page surface ----------------------------------------------------

it('shows no SSO button when single sign-on is off', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign in with SSO')
        ->assertSee('name="password"', false);
});

it('shows the SSO button and keeps the password form when SSO is on', function () {
    configureOidc();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in with SSO')
        ->assertSee('name="password"', false);
});

it('uses the configured button label', function () {
    configureOidc(['oidc_button_label' => 'Log in with Acme ID']);

    $this->get(route('login'))->assertSee('Log in with Acme ID');
});

it('hides the password form when local login is disabled', function () {
    configureOidc(['oidc_disable_local_login' => '1']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in with SSO')
        ->assertDontSee('name="password"', false);
});

it('refuses password sign-in while local login is disabled', function () {
    configureOidc(['oidc_disable_local_login' => '1']);
    $user = User::factory()->create(['username' => 'admin']);

    $this->post(route('login.attempt'), ['username' => 'admin', 'password' => 'password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('refuses client API password sign-in while local login is disabled', function () {
    // Issue #102: the RustDesk client posted a password and got a token.
    configureOidc(['oidc_disable_local_login' => '1']);
    User::factory()->create(['username' => 'admin']);

    $this->postJson('/api/login', ['username' => 'admin', 'password' => 'password', 'id' => '123', 'uuid' => 'u'])
        ->assertOk()
        ->assertJsonMissingPath('access_token')
        ->assertJsonPath('error', 'Password sign-in is disabled. Use single sign-on.');

    expect(\App\Models\ClientToken::count())->toBe(0);
});

it('keeps client API password sign-in when the switch is on but SSO is unconfigured', function () {
    Setting::put('oidc_disable_local_login', '1');
    Setting::put('oidc_enabled', '1');
    User::factory()->create(['username' => 'admin']);

    $this->postJson('/api/login', ['username' => 'admin', 'password' => 'password', 'id' => '123', 'uuid' => 'u'])
        ->assertOk()
        ->assertJsonStructure(['access_token']);
});

// --- Break-glass -----------------------------------------------------------

it('ignores disable-local-login when SSO is not configured', function () {
    // The switch is on but there is no provider — password login must survive,
    // otherwise a half-finished configuration locks everyone out.
    Setting::put('oidc_disable_local_login', '1');
    Setting::put('oidc_enabled', '1');

    $user = User::factory()->create(['username' => 'admin']);

    $this->post(route('login.attempt'), ['username' => 'admin', 'password' => 'password'])
        ->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user);
});

it('lets the env kill switch turn SSO off entirely', function () {
    configureOidc(['oidc_disable_local_login' => '1']);
    config()->set('cortendesk.oidc_disabled', true);

    expect(app(OidcService::class)->isEnabled())->toBeFalse()
        ->and(app(OidcService::class)->localLoginDisabled())->toBeFalse();

    $this->get(route('login'))->assertDontSee('Sign in with SSO');
});

it('turns the SSO endpoints away when SSO is disabled', function () {
    $this->get(route('login.oidc'))->assertRedirect(route('login'));
    $this->get(route('login.oidc.callback'))->assertRedirect(route('login'));
});

// --- Authorization request -------------------------------------------------

it('redirects to the provider with PKCE, state and nonce', function () {
    configureOidc();
    fakeProvider();

    $response = $this->get(route('login.oidc'));

    $response->assertRedirectContains(OIDC_ISSUER.'/protocol/openid-connect/auth');

    $query = [];
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

    expect($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe(OIDC_CLIENT)
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['nonce'])->not->toBeEmpty()
        ->and($query['redirect_uri'])->toBe(route('login.oidc.callback'));

    // The verifier must be held server-side, and must not be the challenge.
    expect(session(OidcService::SESSION_VERIFIER))->not->toBeEmpty()
        ->and(session(OidcService::SESSION_STATE))->toBe($query['state']);
});

// --- Callback security -----------------------------------------------------

it('rejects a callback whose state does not match the session', function () {
    configureOidc();
    fakeProvider();

    callbackWith(['state' => 'forged-state'])->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects a callback with no state in the session at all', function () {
    configureOidc();
    fakeProvider();

    $this->get(route('login.oidc.callback', ['code' => 'auth-code', 'state' => 'anything']))
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects an ID token signed by the wrong key', function () {
    configureOidc();

    $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($foreign, $foreignKey);

    fakeProvider(['id_token' => JWT::encode([
        'iss' => OIDC_ISSUER,
        'aud' => OIDC_CLIENT,
        'sub' => 'subject-abc-123',
        'exp' => time() + 300,
        'iat' => time(),
        'nonce' => 'test-nonce',
    ], $foreignKey, 'RS256', OIDC_KID)]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects an ID token from a different issuer', function () {
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['iss' => 'https://evil.test/realms/other'])]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects an ID token minted for another client', function () {
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['aud' => 'some-other-client'])]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects an ID token whose nonce does not match', function () {
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['nonce' => 'replayed-nonce'])]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('rejects an expired ID token', function () {
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['exp' => time() - 3600, 'iat' => time() - 7200])]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('surfaces a provider error parameter instead of continuing', function () {
    configureOidc();
    fakeProvider();

    $this->withSession([OidcService::SESSION_STATE => 'state-value'])
        ->get(route('login.oidc.callback', ['error' => 'access_denied', 'error_description' => 'User said no']))
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('tolerates the RFC 9207 iss parameter Keycloak adds to the callback', function () {
    configureOidc();
    fakeProvider();

    User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith(['iss' => OIDC_ISSUER])->assertRedirect(route('overview'));

    $this->assertAuthenticated();
});

// --- Identity matching -----------------------------------------------------

it('signs in a user already linked by issuer and subject', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['email' => 'someone-else@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('links an existing local account by verified email', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['username' => 'existing', 'email' => 'ssouser@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());

    $user->refresh();
    expect($user->oidc_sub)->toBe('subject-abc-123')
        ->and($user->oidc_iss)->toBe(OIDC_ISSUER)
        // Linking must NOT strip the local password — that is the way back in
        // when the provider is unreachable.
        ->and($user->auth_provider)->toBe('local');
});

it('refuses to link an account when the provider has not verified the email', function () {
    // Only when the operator has opted into requiring the claim — see the
    // block at the end of this file for the default behaviour.
    configureOidc(['oidc_require_verified_email' => '1']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);

    $user = User::factory()->create(['username' => 'victim', 'email' => 'ssouser@example.com']);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
    expect($user->fresh()->oidc_sub)->toBeNull();
});

it('refuses an identity that collides with another account link', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'a-different-subject'])->save();

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('accepts email_verified sent as the string "true"', function () {
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => 'true'])]);

    $user = User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

// --- Provisioning policy ---------------------------------------------------

it('denies an unknown identity under the deny policy', function () {
    configureOidc(['oidc_new_user_policy' => 'deny']);
    fakeProvider();

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('provisions an active account under the active policy', function () {
    configureOidc(['oidc_new_user_policy' => 'active']);
    fakeProvider();

    callbackWith()->assertRedirect(route('overview'));

    $user = User::query()->first();

    expect($user->username)->toBe('ssouser')
        ->and($user->email)->toBe('ssouser@example.com')
        ->and($user->auth_provider)->toBe('oidc')
        ->and($user->oidc_status)->toBe('active')
        ->and($user->is_admin)->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('holds a provisioned account under the pending policy', function () {
    configureOidc(['oidc_new_user_policy' => 'pending']);
    fakeProvider();

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::query()->first()->oidc_status)->toBe('pending');
});

it('does not let a pending account in on a second attempt', function () {
    configureOidc(['oidc_new_user_policy' => 'pending']);
    fakeProvider();

    callbackWith();
    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

it('lets a pending account in once an administrator approves it (#123)', function () {
    configureOidc(['oidc_new_user_policy' => 'pending']);
    fakeProvider();
    callbackWith();
    $pending = User::query()->where('auth_provider', 'oidc')->first();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Livewire::test(UserList::class)
        ->set('status', 'pending')
        ->assertSee($pending->username)
        ->assertSee('Awaiting approval')
        ->call('approveSso', $pending->id);

    expect($pending->fresh()->oidc_status)->toBe('active')
        ->and(ConsoleAudit::query()->where('action', 'user.approve')->exists())->toBeTrue();

    auth()->logout();
    callbackWith()->assertRedirect(route('overview'));
    $this->assertAuthenticatedAs($pending->fresh());
});

it('puts provisioned users in the configured default group', function () {
    $group = UserGroup::query()->create(['name' => 'SSO Users']);
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_default_group_id' => (string) $group->id]);
    fakeProvider();

    callbackWith()->assertRedirect(route('overview'));

    expect(User::query()->first()->groups()->pluck('user_groups.id')->all())->toBe([$group->id]);
});

it('can provision administrators when the operator opts in', function () {
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_default_admin' => '1']);
    fakeProvider();

    callbackWith()->assertRedirect(route('overview'));

    expect(User::query()->first()->is_admin)->toBeTrue();
});

it('avoids username collisions when provisioning', function () {
    configureOidc(['oidc_new_user_policy' => 'active']);
    fakeProvider();
    User::factory()->create(['username' => 'ssouser', 'email' => 'other@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    expect(User::query()->where('oidc_sub', 'subject-abc-123')->first()->username)->toBe('ssouser_1');
});

it('refuses to provision from an unverified email', function () {
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_require_verified_email' => '1']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);

    callbackWith()->assertSessionHasErrors('username');

    expect(User::query()->count())->toBe(0);
});

// --- Domain allowlist ------------------------------------------------------

it('allows a permitted email domain', function () {
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_allowed_domains' => 'example.com, other.test']);
    fakeProvider();

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticated();
});

it('blocks a domain outside the allowlist', function () {
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_allowed_domains' => 'corp.example']);
    fakeProvider();

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('blocks an identity with no email when an allowlist is set', function () {
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_allowed_domains' => 'corp.example']);
    fakeProvider(['id_token' => oidcIdToken(['email' => null, 'email_verified' => false])]);

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

// --- Account state ---------------------------------------------------------

it('keeps a disabled account out even with a valid identity', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com', 'is_active' => false]);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith()->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('blocks password sign-in for a provisioned SSO account', function () {
    $user = User::factory()->create(['username' => 'ssouser', 'password' => 'known-password']);
    $user->forceFill(['auth_provider' => 'oidc'])->save();

    $this->post(route('login.attempt'), ['username' => 'ssouser', 'password' => 'known-password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('blocks password sign-in for an account pending approval', function () {
    $user = User::factory()->create(['username' => 'waiting', 'password' => 'known-password']);
    $user->forceFill(['oidc_status' => 'pending'])->save();

    $this->post(route('login.attempt'), ['username' => 'waiting', 'password' => 'known-password'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('records SSO sign-ins in the login log', function () {
    configureOidc();
    fakeProvider();
    User::factory()->create(['username' => 'existing', 'email' => 'ssouser@example.com']);

    callbackWith();

    $log = LoginLog::query()->latest('id')->first();

    expect($log->client)->toBe('sso')
        ->and((bool) $log->successful)->toBeTrue()
        ->and($log->username)->toBe('existing');
});

it('records failed SSO attempts too', function () {
    configureOidc(['oidc_new_user_policy' => 'deny']);
    fakeProvider();

    callbackWith();

    $log = LoginLog::query()->latest('id')->first();

    expect($log->client)->toBe('sso')->and((bool) $log->successful)->toBeFalse();
});

// --- Interaction with 2FA enforcement --------------------------------------

it('exempts SSO sessions from enforced two-factor enrollment', function () {
    configureOidc();
    fakeProvider();
    Setting::put('two_factor_required', '1');

    $user = User::factory()->create(['email' => 'ssouser@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith()->assertRedirect(route('overview'));

    // A password session would be bounced to the enrollment wizard here.
    $this->get(route('overview'))->assertOk();
});

it('still enforces two-factor for password sessions', function () {
    Setting::put('two_factor_required', '1');
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('overview'))
        ->assertRedirect(route('account.two-factor'));
});

// --- Logout ----------------------------------------------------------------

it('ends the provider session on logout when RP logout is on', function () {
    configureOidc(['oidc_logout_enabled' => '1']);
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith();

    $response = $this->post(route('logout'));

    $location = $response->headers->get('Location');
    expect($location)->toContain(OIDC_ISSUER.'/protocol/openid-connect/logout')
        ->and($location)->toContain('id_token_hint=')
        ->and($location)->toContain('post_logout_redirect_uri=');

    $this->assertGuest();
});

it('logs out locally when RP logout is off', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('logs out locally even when the provider is unreachable', function () {
    configureOidc(['oidc_logout_enabled' => '1']);
    Http::fake([OIDC_ISSUER.'/.well-known/*' => Http::response(null, 500)]);

    $user = User::factory()->create();
    $this->actingAs($user)->withSession([OidcService::SESSION_PROVIDER => true]);

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});

// --- Service-level behaviour ----------------------------------------------

it('accepts a full well-known URL as the provider URL', function () {
    configureOidc(['oidc_discovery_url' => OIDC_ISSUER.'/.well-known/openid-configuration']);
    fakeProvider();

    expect(app(OidcService::class)->discovery()['issuer'])->toBe(OIDC_ISSUER);
});

it('reports a helpful failure when the provider cannot be reached', function () {
    configureOidc();
    Http::fake([OIDC_ISSUER.'/.well-known/*' => Http::response(null, 502)]);

    $result = app(OidcService::class)->test();

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Could not read');
});

it('reports success and key count from the test button', function () {
    configureOidc();
    fakeProvider();

    $result = app(OidcService::class)->test();

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('1 signing key');
});

it('does not treat the discovery base as the issuer', function () {
    // Split-host: the console reaches the provider internally, but the issuer
    // it declares is the browser-facing URL. Verified tokens carry the
    // declared issuer, so validation must follow the document, not the URL.
    Setting::put('oidc_enabled', '1');
    Setting::put('oidc_discovery_url', 'http://internal-idp:8080/realms/cortendesk');
    Setting::put('oidc_client_id', OIDC_CLIENT);
    Setting::put('oidc_client_secret', Crypt::encryptString('secret'));

    Http::fake([
        'http://internal-idp:8080/realms/cortendesk/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'authorization_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => 'http://internal-idp:8080/realms/cortendesk/protocol/openid-connect/token',
            'jwks_uri' => 'http://internal-idp:8080/realms/cortendesk/protocol/openid-connect/certs',
        ]),
        'http://internal-idp:8080/realms/cortendesk/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
    ]);

    // A token carrying the DECLARED issuer must verify.
    $claims = app(OidcService::class)->verifyIdToken(oidcIdToken(), 'test-nonce');

    expect($claims['iss'])->toBe(OIDC_ISSUER);
});

it('rewrites only browser-facing URLs when a public base URL is set', function () {
    configureOidc(['oidc_public_base_url' => 'https://sso.example.com']);
    fakeProvider();

    $response = $this->get(route('login.oidc'));

    // Only the origin is swapped — the provider's path (realm included) is kept.
    expect($response->headers->get('Location'))
        ->toStartWith('https://sso.example.com/realms/cortendesk/protocol/openid-connect/auth');
});

it('keeps the client secret encrypted at rest', function () {
    configureOidc();

    $stored = Setting::get('oidc_client_secret');

    expect($stored)->not->toContain('test-client-secret-123')
        ->and(Crypt::decryptString($stored))->toBe('test-client-secret-123');
});

it('keeps working when the cache is unwritable', function () {
    // A read-only or wrongly-owned storage/ must not 500 the login path — the
    // file cache store throws there, and sign-in has to degrade to uncached.
    configureOidc();
    fakeProvider();

    Cache::shouldReceive('get')->andThrow(new RuntimeException('Permission denied'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Permission denied'));

    $this->get(route('login.oidc'))
        ->assertRedirectContains(OIDC_ISSUER.'/protocol/openid-connect/auth');
});

it('does not cache a provider failure', function () {
    configureOidc();

    // First call fails, second succeeds: a provider that recovers must be
    // picked up immediately rather than remembered as broken.
    Http::fakeSequence()
        ->push(null, 500)
        ->push([
            'issuer' => OIDC_ISSUER,
            'authorization_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
        ]);

    $service = app(OidcService::class);

    expect($service->test()['ok'])->toBeFalse()
        ->and($service->discovery()['issuer'])->toBe(OIDC_ISSUER);
});

it('refuses to start sign-in without a configured provider', function () {
    Setting::put('oidc_enabled', '1');

    expect(fn () => app(OidcService::class)->discovery())
        ->toThrow(OidcException::class);
});

// --- Resolver unit checks --------------------------------------------------

it('rejects claims with no subject', function () {
    $result = app(OidcUserResolver::class)->resolve(['iss' => OIDC_ISSUER]);

    expect($result['status'])->toBe('denied');
});

it('updates a linked profile from directory claims', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    app(OidcUserResolver::class)->resolve([
        'iss' => OIDC_ISSUER,
        'sub' => 'subject-abc-123',
        'name' => 'New Name',
        'email' => 'new@example.com',
        'email_verified' => true,
    ]);

    $user->refresh();

    expect($user->name)->toBe('New Name')->and($user->email)->toBe('new@example.com');
});

// --- email_verified: ignored unless the operator opts in -------------------

it('admits a provider that never sends the claim', function () {
    // Microsoft Entra ID does not emit email_verified at all (issue #6).
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => null])]);

    $user = User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('admits a provider that sends false', function () {
    // Authentik sends email_verified: false for every user by design, because
    // it cannot confirm anyone owns their address. Refusing on that locked out
    // the whole provider.
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);

    $user = User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('provisions from a provider that sends false', function () {
    configureOidc(['oidc_new_user_policy' => 'active']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);

    callbackWith()->assertRedirect(route('overview'));

    expect(User::query()->first()->email)->toBe('ssouser@example.com');
});

it('refuses an unverified address once the operator requires verification', function () {
    configureOidc(['oidc_require_verified_email' => '1']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);

    User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertSessionHasErrors('username');

    expect(session('errors')->first('username'))->toContain('reports this email address as unverified');

    $this->assertGuest();
});

it('refuses a silent provider once the operator requires verification', function () {
    configureOidc(['oidc_require_verified_email' => '1']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => null])]);

    User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertSessionHasErrors('username');

    expect(session('errors')->first('username'))->toContain('did not say whether');

    $this->assertGuest();
});

it('still admits a verified address when verification is required', function () {
    configureOidc(['oidc_require_verified_email' => '1']);
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com']);

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('keys identity on issuer and subject, not the address', function () {
    // The protection that actually matters, and the reason the email claim can
    // be ignored: once linked, the address decides nothing.
    configureOidc();
    fakeProvider(['id_token' => oidcIdToken(['email' => 'moved@example.com', 'email_verified' => false])]);

    $user = User::factory()->create(['email' => 'old@example.com']);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    callbackWith()->assertRedirect(route('overview'));

    $this->assertAuthenticatedAs($user->fresh());
});

/*
|--------------------------------------------------------------------------
| ID token signing algorithm (issue #36)
|--------------------------------------------------------------------------
| The token header is the unverified half of the token, so it must not get to
| pick how the token is verified. What the provider advertises decides.
*/

it('accepts a provider whose JWKS omits alg, as Microsoft Entra does', function () {
    // RFC 7517 makes "alg" optional on a JWK and Entra leaves it off every key.
    $jwks = oidcKeys()['jwks'];
    unset($jwks['keys'][0]['alg']);

    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response($jwks),
    ]);
    configureOidc();

    $claims = app(OidcService::class)->verifyIdToken(oidcIdToken(), 'test-nonce');

    expect($claims['sub'])->not->toBeEmpty();
});

it('refuses an algorithm the provider does not advertise', function () {
    // A provider that only signs with RS256 must not accept an HS256 token,
    // even from someone who has the client secret.
    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
    ]);
    configureOidc(['oidc_client_secret' => Crypt::encryptString(OIDC_HS_SECRET)]);

    $forged = JWT::encode([
        'iss' => OIDC_ISSUER,
        'aud' => OIDC_CLIENT,
        'sub' => 'intruder',
        'nonce' => 'test-nonce',
        'exp' => time() + 300,
        'iat' => time(),
    ], OIDC_HS_SECRET, 'HS256');

    expect(fn () => app(OidcService::class)->verifyIdToken($forged, 'test-nonce'))
        ->toThrow(OidcException::class, 'does not advertise');
});

it('still allows symmetric signing when the provider advertises it', function () {
    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
            'id_token_signing_alg_values_supported' => ['RS256', 'HS256'],
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
    ]);
    configureOidc(['oidc_client_secret' => Crypt::encryptString(OIDC_HS_SECRET)]);

    $token = JWT::encode([
        'iss' => OIDC_ISSUER,
        'aud' => OIDC_CLIENT,
        'sub' => 'hs-user',
        'nonce' => 'test-nonce',
        'exp' => time() + 300,
        'iat' => time(),
    ], OIDC_HS_SECRET, 'HS256');

    expect(app(OidcService::class)->verifyIdToken($token, 'test-nonce')['sub'])->toBe('hs-user');
});

it('falls back to RS256 when discovery does not say what it signs with', function () {
    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => OIDC_ISSUER,
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
    ]);
    configureOidc();

    expect(app(OidcService::class)->verifyIdToken(oidcIdToken(), 'test-nonce')['sub'])->not->toBeEmpty();
});

it('shows both redirect URIs the provider must allow (#124)', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/settings?tab=sso')->assertOk()
        ->assertSee(route('login.oidc.callback'))
        ->assertSee(route('login.oidc.client-callback'));
});

it('flags a multi-tenant placeholder issuer in Test connection and at sign-in (#124)', function () {
    configureOidc(['oidc_new_user_policy' => 'active']);
    $placeholder = 'https://login.microsoftonline.com/{tenantid}/v2.0';
    Http::fake([
        OIDC_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => $placeholder,
            'authorization_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => OIDC_ISSUER.'/protocol/openid-connect/token',
            'jwks_uri' => OIDC_ISSUER.'/protocol/openid-connect/certs',
        ]),
        OIDC_ISSUER.'/protocol/openid-connect/certs' => Http::response(oidcKeys()['jwks']),
        OIDC_ISSUER.'/protocol/openid-connect/token' => Http::response([
            'access_token' => 'a', 'id_token' => oidcIdToken(), 'token_type' => 'Bearer',
        ]),
    ]);

    $result = app(OidcService::class)->test();
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('{tenantid}')
        ->and(OidcService::isPlaceholderIssuer(OIDC_ISSUER))->toBeFalse();

    callbackWith()->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))->toContain('multi-tenant');
    $this->assertGuest();
});
