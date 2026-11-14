<?php

use App\Models\ClientToken;
use App\Models\Device;
use App\Models\LoginLog;
use App\Models\OidcAuthRequest;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| RustDesk client SSO (PLAN D3 client half, docs/client-api.md §2, §6–7)
|--------------------------------------------------------------------------
| Contract tests. The stock client drives this unmodified, and the Rust paths
| ignore HTTP status while matching error strings literally, so the exact
| response shapes below are the whole contract — getting one wrong either
| aborts the client or hangs it until its 180s timeout.
|
| Shares fixtures with OidcTest (configureOidc/fakeProvider/oidcIdToken).
*/

/**
 * Run the browser half for a pending row, as the IdP redirect would.
 *
 * The ID token has to carry THIS row's nonce — the client flow keeps its nonce
 * on the row rather than in a session, and rejects anything else.
 */
function completeClientFlow(OidcAuthRequest $pending): \Illuminate\Testing\TestResponse
{
    // The shared fixture signs a fixed nonce, so align the row with it. The
    // real check (row nonce vs token nonce) still runs — see the mismatch test.
    $pending->forceFill(['nonce' => 'test-nonce'])->save();

    return test()->get(route('login.oidc.client-callback', [
        'code' => 'auth-code',
        'state' => $pending->state,
    ]));
}

// --- login-options: the client's SSO button ---------------------------------

it('advertises no providers when SSO is off', function () {
    $this->getJson('/api/login-options')->assertOk()->assertExactJson([]);
});

it('advertises the provider once SSO is on', function () {
    configureOidc();

    $response = $this->getJson('/api/login-options')->assertOk();

    $options = $response->json();

    expect($options)->toHaveCount(1)
        ->and($options[0])->toStartWith('common-oidc/');

    // The client parses the embedded JSON array and reads `name` per entry.
    $providers = json_decode(substr($options[0], strlen('common-oidc/')), true);

    // The client renders "Continue with {name}", so name IS the wording.
    expect($providers)->toBeArray()
        ->and($providers[0]['name'])->toBe('SSO');
});

it('derives the client button wording from the console label', function () {
    // "Continue with Sign in with Keycloak" would be nonsense — the leading
    // verb is stripped so the client reads "Continue with Keycloak".
    configureOidc(['oidc_button_label' => 'Sign in with Keycloak']);

    $providers = json_decode(substr($this->getJson('/api/login-options')->json()[0], strlen('common-oidc/')), true);

    expect($providers[0]['name'])->toBe('Keycloak');
});

it('falls back to SSO when the label is only a verb phrase', function () {
    configureOidc(['oidc_button_label' => 'Log in with']);

    $providers = json_decode(substr($this->getJson('/api/login-options')->json()[0], strlen('common-oidc/')), true);

    expect($providers[0]['name'])->toBe('SSO');
});

it('answers HEAD on login-options (the client TLS probe)', function () {
    $this->head('/api/login-options')->assertOk();
});

// --- /api/oidc/auth ---------------------------------------------------------

it('starts a flow and returns a code and absolute URL', function () {
    configureOidc();
    fakeProvider();

    $response = $this->postJson('/api/oidc/auth', [
        'op' => 'sso',
        'id' => '123456789',
        'uuid' => 'bWFjaGluZS11dWlk',
        'deviceInfo' => ['os' => 'macos', 'type' => 'client', 'name' => 'test-mac'],
    ])->assertOk();

    $code = $response->json('code');
    $url = $response->json('url');

    expect($code)->not->toBeEmpty()
        // url::Url in the client requires an absolute URL.
        ->and($url)->toStartWith('https://')
        ->and($url)->toContain('code_challenge_method=S256');

    $pending = OidcAuthRequest::query()->where('code', $code)->first();

    expect($pending)->not->toBeNull()
        ->and($pending->device_id)->toBe('123456789')
        ->and($pending->device_uuid)->toBe('bWFjaGluZS11dWlk')
        // The state in the URL must be the one we persisted.
        ->and($url)->toContain('state='.$pending->state);
});

it('reports an error body when SSO is off', function () {
    $this->postJson('/api/oidc/auth', ['id' => '1', 'uuid' => 'u'])
        ->assertOk()
        ->assertJsonStructure(['error']);
});

it('does not leave a pending row when the provider is unreachable', function () {
    configureOidc();
    Illuminate\Support\Facades\Http::fake([OIDC_ISSUER.'/.well-known/*' => Illuminate\Support\Facades\Http::response(null, 500)]);

    $this->postJson('/api/oidc/auth', ['id' => '1', 'uuid' => 'u'])
        ->assertOk()
        ->assertJsonStructure(['error']);

    expect(OidcAuthRequest::query()->count())->toBe(0);
});

// --- /api/oidc/auth-query: the polling contract -----------------------------

it('returns the exact pending sentinel while unauthorized', function () {
    configureOidc();
    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);

    // This exact string is what keeps the client polling. Anything else aborts.
    $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=1&uuid=u')
        ->assertOk()
        ->assertExactJson(['error' => 'No authed oidc is found']);
});

it('treats an unknown code as pending rather than an error', function () {
    $this->getJson('/api/oidc/auth-query?code=nope&id=1&uuid=u')
        ->assertOk()
        ->assertJsonPath('error', 'No authed oidc is found');
});

it('treats an expired code as pending', function () {
    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);
    $pending->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=1&uuid=u')
        ->assertJsonPath('error', 'No authed oidc is found');
});

it('refuses to hand a token to a different device', function () {
    configureOidc();
    fakeProvider();
    User::factory()->create(['email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => 'device-a', 'uuid' => 'uuid-a']);
    completeClientFlow($pending);

    // Right code, wrong device: must not reveal the token.
    $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=device-b&uuid=uuid-b')
        ->assertJsonPath('error', 'No authed oidc is found')
        ->assertJsonMissing(['type' => 'access_token']);
});

// --- The full round trip ----------------------------------------------------

it('completes the flow and returns a usable AuthBody', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['username' => 'ssouser', 'email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '123456789', 'uuid' => 'bWFjaGluZQ==', 'type' => 'client']);

    completeClientFlow($pending)->assertOk()->assertSee('You can close this tab');

    $response = $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=123456789&uuid=bWFjaGluZQ==')
        ->assertOk();

    // `type` must be access_token or the client discards the token, and
    // `user.info` must exist or Rust deserialization fails and it hangs.
    $response->assertJsonPath('type', 'access_token')
        ->assertJsonStructure(['access_token', 'type', 'user' => ['info']]);

    $token = $response->json('access_token');

    expect(ClientToken::query()->where('token', $token)->exists())->toBeTrue();

    // And the token actually works against the client API.
    $this->withToken($token)->postJson('/api/currentUser', [])->assertOk();
});

it('binds the issued token to the device that asked', function () {
    configureOidc();
    fakeProvider();
    User::factory()->create(['email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '987654321', 'uuid' => 'dXVpZA==', 'os' => 'macos']);
    completeClientFlow($pending);

    $token = $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=987654321&uuid=dXVpZA==')
        ->json('access_token');

    $record = ClientToken::query()->where('token', $token)->first();

    expect($record->device_id)->toBe('987654321')
        ->and($record->device_uuid)->toBe('dXVpZA==')
        ->and($record->device_os)->toBe('macos');
});

it('registers the device that signed in through SSO (issue #110)', function () {
    configureOidc();
    fakeProvider();
    $user = User::factory()->create(['email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '987654322', 'uuid' => 'dXVpZA==', 'os' => 'android', 'name' => 'Pixel']);
    completeClientFlow($pending);

    expect(Device::where('rustdesk_id', '987654322')->first())
        ->not->toBeNull()
        ->status->toBe(Device::STATUS_ACTIVE)
        ->user_id->toBe($user->id)
        ->os->toBe('android');
});

it('redeems a code only once', function () {
    configureOidc();
    fakeProvider();
    User::factory()->create(['email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);
    completeClientFlow($pending);

    $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=1&uuid=u')
        ->assertJsonPath('type', 'access_token');

    // Replaying the same code must not mint a second session.
    $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=1&uuid=u')
        ->assertJsonPath('error', 'No authed oidc is found');
});

it('logs the client SSO sign-in', function () {
    configureOidc();
    fakeProvider();
    User::factory()->create(['username' => 'ssouser', 'email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '55', 'uuid' => 'u', 'os' => 'windows', 'type' => 'client']);
    completeClientFlow($pending);

    $log = LoginLog::query()->latest('id')->first();

    expect((bool) $log->successful)->toBeTrue()
        ->and($log->username)->toBe('ssouser')
        ->and($log->device_id)->toBe('55');
});

// --- Browser-half failures --------------------------------------------------

it('reports a rejected identity to the polling client', function () {
    // deny policy + no matching account = refused.
    configureOidc(['oidc_new_user_policy' => 'deny']);
    fakeProvider();

    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);

    completeClientFlow($pending)->assertForbidden();

    // A real error (not the sentinel) so the client stops and shows it.
    $response = $this->getJson('/api/oidc/auth-query?code='.$pending->code.'&id=1&uuid=u');

    expect($response->json('error'))->not->toBe('No authed oidc is found')
        ->and($response->json('error'))->toContain('No console account');
});

it('refuses an unverified email for the client flow too', function () {
    // With the operator's opt-in requirement on; ignored by default.
    configureOidc(['oidc_new_user_policy' => 'active', 'oidc_require_verified_email' => '1']);
    fakeProvider(['id_token' => oidcIdToken(['email_verified' => false])]);
    User::factory()->create(['email' => 'ssouser@example.com']);

    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);
    completeClientFlow($pending)->assertForbidden();

    expect(ClientToken::query()->count())->toBe(0);
});

it('rejects a token whose nonce does not match the pending row', function () {
    configureOidc();
    fakeProvider();

    // Row keeps its own random nonce; the fixture token carries 'test-nonce'.
    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);

    $this->get(route('login.oidc.client-callback', ['code' => 'auth-code', 'state' => $pending->state]))
        ->assertForbidden();

    expect(ClientToken::query()->count())->toBe(0);
});

it('rejects a callback with an unknown state', function () {
    configureOidc();
    fakeProvider();

    $this->get(route('login.oidc.client-callback', ['code' => 'x', 'state' => 'never-issued']))
        ->assertStatus(400);
});

it('keeps a disabled account out of the client flow', function () {
    configureOidc();
    fakeProvider();

    $user = User::factory()->create(['email' => 'ssouser@example.com', 'is_active' => false]);
    $user->forceFill(['oidc_iss' => OIDC_ISSUER, 'oidc_sub' => 'subject-abc-123'])->save();

    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);
    completeClientFlow($pending)->assertForbidden();

    expect(ClientToken::query()->count())->toBe(0);
});

it('holds a pending-approval account out of the client flow', function () {
    configureOidc(['oidc_new_user_policy' => 'pending']);
    fakeProvider();

    $pending = OidcAuthRequest::start(['id' => '1', 'uuid' => 'u']);
    completeClientFlow($pending)->assertForbidden();

    expect(ClientToken::query()->count())->toBe(0)
        ->and(User::query()->first()->oidc_status)->toBe('pending');
});

it('answers with an error, not a 500, when the provider refuses the connection', function () {
    configureOidc();
    Illuminate\Support\Facades\Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Connection refused'));

    $this->postJson('/api/oidc/auth', ['id' => '1', 'uuid' => 'u'])
        ->assertOk()
        ->assertJsonPath('error', fn ($e) => str_starts_with($e, "Could not read the provider's configuration"));

    expect(OidcAuthRequest::query()->count())->toBe(0);
});

it('sends the console back to sign-in when the provider refuses the connection', function () {
    configureOidc();
    Illuminate\Support\Facades\Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Connection refused'));

    $this->get(route('login.oidc'))->assertRedirect(route('login'));
});
