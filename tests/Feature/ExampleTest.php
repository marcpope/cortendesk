<?php

use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Sign In');
});

it('renders the overview dashboard with charts and live tiles', function () {
    $user = User::create(['username' => 'dash', 'password' => 'secret-password']);

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('chart-connections')
        ->assertSee('chart-platforms')
        ->assertSee('chart-versions')
        ->assertSee('Active Sessions');
});
