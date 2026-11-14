<?php

use App\Http\Middleware\TrustConfiguredProxies;
use App\Models\User;
use Illuminate\Http\Request;

it('generates https asset urls when behind a TLS-terminating proxy', function () {
    // Simulate the proxy hop: the container is reached over http, but the
    // original request was https and the proxy says so via X-Forwarded-Proto.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->get('/')
        ->assertOk()
        // Assets must be referenced over https, not the plain-http proxy hop.
        ->assertSee('https://localhost/assets', false)
        ->assertDontSee('http://localhost/assets', false);
});

it('ignores forwarded headers from untrusted (public) peers', function () {
    // A direct client from a public address must not be able to spoof scheme
    // or client IP — its REMOTE_ADDR is outside the trusted proxy ranges.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ])
        ->get('/')
        ->assertOk()
        ->assertDontSee('https://localhost/assets', false);

    expect(request()->ip())->toBe('203.0.113.50');
});

it('ignores a forged X-Forwarded-Host even from a trusted peer', function () {
    // XFH is excluded from the trusted header set: generated URLs must keep
    // the real Host, killing host-poisoning/open-redirect vectors.
    $this->actingAs(User::factory()->admin()->create())
        ->withServerVariables([
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
        ])
        ->get('/')
        ->assertOk()
        ->assertDontSee('evil.example.com', false)
        ->assertSee('https://localhost/assets', false);
});

it('treats a forwarded-https request as secure', function () {
    // With the proxy trusted, X-Forwarded-Proto: https must mark the request
    // secure even though the container connection itself is plain http.
    $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])
        ->get('/login')
        ->assertOk();

    expect(request()->isSecure())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reading the proxy list from config (#7)
|--------------------------------------------------------------------------
| The list used to be built with env() inside bootstrap/app.php. That closure
| runs outside a config file, so once `php artisan config:cache` had run — as
| the Docker image does on every start — env() returned null and a
| TRUSTED_PROXIES set in .env was silently ignored on every production install.
*/

it('reads the proxy list from config, so it survives config:cache', function () {
    config()->set('trustedproxy.proxies', ['203.0.113.5']);

    $middleware = new TrustConfiguredProxies;

    expect((new ReflectionMethod($middleware, 'proxies'))->invoke($middleware))
        ->toBe(['203.0.113.5']);
});

it('passes a bare * through as a string', function () {
    // Laravel wants "trust any peer" as a string, not a one-element array.
    config()->set('trustedproxy.proxies', ['*']);

    $middleware = new TrustConfiguredProxies;

    expect((new ReflectionMethod($middleware, 'proxies'))->invoke($middleware))
        ->toBe('*');
});

it('keeps X-Forwarded-Host out of the trusted header set', function () {
    // Belt and braces alongside the forged-XFH request test above.
    $middleware = new TrustConfiguredProxies;
    $headers = (new ReflectionMethod($middleware, 'headers'))->invoke($middleware);

    expect($headers & Request::HEADER_X_FORWARDED_HOST)->toBe(0)
        ->and($headers & Request::HEADER_X_FORWARDED_PROTO)->not->toBe(0);
});
