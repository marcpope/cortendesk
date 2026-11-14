<?php

use App\Models\User;

/*
 * navigator.clipboard only exists in a secure context, so a direct call
 * silently fails on a plain-HTTP install — which is a documented way to run
 * this. Every copy button goes through the helper, which falls back.
 */

it('loads the clipboard helper on console pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/settings')
        ->assertOk()
        ->assertSee('assets/js/cortendesk.js', false);
});

it('has no copy button calling navigator.clipboard directly', function () {
    $offenders = [];

    foreach (glob(resource_path('views/**/*.blade.php')) + glob(resource_path('views/*.blade.php')) as $view) {
        if (str_contains((string) file_get_contents($view), 'navigator.clipboard')) {
            $offenders[] = basename($view);
        }
    }

    expect($offenders)->toBe([]);
});
