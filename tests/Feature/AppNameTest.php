<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Application name
|--------------------------------------------------------------------------
| `config('app.name')` is not cosmetic trivia: it renders into every page
| title, the From name on outgoing mail, and emailed sign-in codes.
|
| The Docker image sets no APP_NAME, so whatever the config default is, that
| is what most installs actually run with. Laravel's stock default shipped
| unnoticed through every release up to 1.0.0, and every Docker console read
| "Sign In | Laravel" in the browser tab.
|
| These assert the default itself, NOT that some .env happens to be right —
| the .env is the one thing a user install will not have copied from us.
*/

it('defaults the app name to CortenDesk, not the framework default', function () {
    // Explicitly ignore any APP_NAME in the environment: the point is that the
    // fallback is correct for an install that never sets one.
    expect(config('app.name'))->toBe('CortenDesk');
});

it('never renders the framework name in a page title', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<title>Sign In | CortenDesk</title>', false)
        ->assertDontSee('Laravel', false);
});

it('titles an authenticated console page with CortenDesk', function () {
    $this->actingAs(User::factory()->create())
        ->get('/devices')
        ->assertOk()
        ->assertSee('| CortenDesk</title>', false);
});

it('sends mail from the product name rather than the framework name', function () {
    expect(config('mail.from.name'))->not->toBe('Laravel')
        ->and(config('mail.from.name'))->toBe('CortenDesk');
});
