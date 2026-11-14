<?php

use Illuminate\Support\Facades\Route;

/*
 * With SESSION_SECURE_COOKIE unset the Secure flag follows the request
 * scheme, so one console works over HTTPS and over plain HTTP on the LAN.
 * An explicit value wins, and HTTPS-only over HTTP explains itself instead
 * of failing sign-in with a bare 419 (#78).
 */

function sessionCookie($response)
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === config('session.cookie')) {
            return $cookie;
        }
    }

    return null;
}

it('sets no Secure flag over plain http when unset', function () {
    config(['session.secure' => null]);

    $cookie = sessionCookie($this->get('/login')->assertOk());

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeFalse();
});

it('sets the Secure flag over https when unset', function () {
    config(['session.secure' => null]);

    $cookie = sessionCookie($this->get('https://localhost/login')->assertOk());

    expect($cookie->isSecure())->toBeTrue();
});

it('sets the Secure flag behind a TLS-terminating proxy when unset', function () {
    config(['session.secure' => null]);

    $response = $this->withServerVariables(['HTTP_X_FORWARDED_PROTO' => 'https'])->get('/login');

    expect(sessionCookie($response)->isSecure())->toBeTrue();
});

it('keeps an explicit false over https', function () {
    config(['session.secure' => false]);

    expect(sessionCookie($this->get('https://localhost/login'))->isSecure())->toBeFalse();
});

it('explains HTTPS-only cookies on the login page over http', function () {
    config(['session.secure' => true]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign-in only works over HTTPS here', false);

    $this->get('https://localhost/login')
        ->assertOk()
        ->assertDontSee('Sign-in only works over HTTPS here', false);
});

// The test kernel skips CSRF verification, so raise the 419 it would throw.
function csrfFailureRoute(): void
{
    Route::post('/_csrf-failure', fn () => abort(419))->middleware('web');
}

it('sends a dropped-cookie sign-in back to the login page instead of a 419', function () {
    config(['session.secure' => true]);
    csrfFailureRoute();

    $this->post('/_csrf-failure')->assertRedirect(route('login'));
});

it('still answers a real CSRF failure over https with 419', function () {
    config(['session.secure' => true]);
    csrfFailureRoute();

    $this->post('https://localhost/_csrf-failure')->assertStatus(419);
});
