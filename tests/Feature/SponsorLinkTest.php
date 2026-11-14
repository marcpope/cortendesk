<?php

use App\Models\User;

it('shows the sponsor link in the sidebar to every signed-in user', function (bool $admin) {
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertSee('https://cortendesk.com/sponsor', false)
        ->assertSee(__('nav.sponsor_cortendesk'));
})->with(['admin' => true, 'regular user' => false]);
