<?php

use App\Livewire\AccountProfile;
use App\Models\User;
use Livewire\Livewire;

/*
 * The console theme is saved on the user, so it holds in every tab, browser
 * and device (issue #94). It used to live in sessionStorage and reset with
 * every new tab.
 */

it('renders dark for a user who never picked a theme', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('data-bs-theme="dark" data-rd-theme-pref="dark"', false);
});

it('renders the saved light theme on the server, with no client step', function () {
    $this->actingAs(User::factory()->create(['theme' => 'light']))
        ->get('/')
        ->assertOk()
        ->assertSee('data-bs-theme="light" data-rd-theme-pref="light"', false);
});

it('hands "system" to the browser to resolve', function () {
    $this->actingAs(User::factory()->create(['theme' => 'system']))
        ->get('/devices')
        ->assertOk()
        ->assertSee('data-rd-theme-pref="system"', false);
});

it('saves the topbar switch', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/account/theme', ['theme' => 'light'])
        ->assertNoContent();

    expect($user->fresh()->theme)->toBe('light');
});

it('rejects an unknown theme', function () {
    $user = User::factory()->create(['theme' => 'dark']);

    $this->actingAs($user)
        ->post('/account/theme', ['theme' => 'neon'])
        ->assertSessionHasErrors('theme');

    expect($user->fresh()->theme)->toBe('dark');
});

it('requires a signed-in user', function () {
    $this->post('/account/theme', ['theme' => 'light'])->assertRedirect(route('login'));
});

it('saves the choice from My Account and tells the page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountProfile::class)
        ->assertSet('theme', 'dark')
        ->set('theme', 'system')
        ->assertDispatched('rd-theme', theme: 'system');

    expect($user->fresh()->theme)->toBe('system');
});

it('uses the saved theme on the web client page too', function () {
    $this->actingAs(User::factory()->create(['theme' => 'light']))
        ->get('/webclient')
        ->assertOk()
        ->assertSee('data-bs-theme="light"', false);
});
