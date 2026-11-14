<?php

use App\Http\Middleware\SecurityHeaders;

it('sends the baseline security headers on pages and on the api', function (string $url) {
    $this->get($url)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy', SecurityHeaders::PERMISSIONS_POLICY);
})->with(['/login', '/api/login-options', '/health/live', '/no-such-page']);

it('keeps the stricter frame header a share page sets', function () {
    $this->get('/s')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
});

it('drops the frame header when framing is allowed', function () {
    config(['cortendesk.allow_framing' => true]);

    $this->get('/login')
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('leaves the microphone, the clipboard and fullscreen to the web client', function () {
    expect(SecurityHeaders::PERMISSIONS_POLICY)
        ->not->toContain('microphone')
        ->not->toContain('clipboard')
        ->not->toContain('fullscreen');
});

it('uses the same permissions policy for static files in nginx', function () {
    expect(file_get_contents(base_path('docker/nginx.conf.template')))
        ->toContain('add_header Permissions-Policy "'.SecurityHeaders::PERMISSIONS_POLICY.'" always;');
});
