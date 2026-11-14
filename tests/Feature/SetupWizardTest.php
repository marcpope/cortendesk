<?php

use App\Livewire\SetupWizard;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

it('opens only for users with settings access', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/setup')->assertOk();
    $this->actingAs(User::factory()->create())->get('/setup')->assertRedirect('/');
});

it('points at Client Setup rather than repeating the values', function () {
    // The values live on Settings → Client Setup. Two copies would drift.
    Setting::put('id_server', 'id.example.test:21116');
    Setting::put('public_key', 'PUBLIC_KEY');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(SetupWizard::class)
        ->assertSee('Copy them from Client Setup')
        ->assertDontSee('PUBLIC_KEY')
        ->assertDontSee('id_ed25519');
});

it('warns when a client value is still unset', function () {
    Setting::put('id_server', '');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(SetupWizard::class)
        ->assertSee('is not set yet');
});

it('prompts on an empty install and remembers a dismissal', function () {
    $admin = User::factory()->admin()->create();
    expect(SetupWizard::shouldPrompt($admin))->toBeTrue();

    Livewire::actingAs($admin)->test(SetupWizard::class)->call('dismiss');

    expect($admin->fresh()->setup_wizard_dismissed_at)->not->toBeNull()
        ->and(SetupWizard::shouldPrompt($admin->fresh()))->toBeFalse();
});

it('does not accept a device record that has never checked in', function () {
    // An imported or pre-registered device proves nothing about connectivity.
    $admin = User::factory()->admin()->create();
    Device::create(['rustdesk_id' => '123456', 'uuid' => 'imported-only']);

    Livewire::actingAs($admin)->test(SetupWizard::class)
        ->assertSet('deviceConnected', false)
        ->call('complete')
        ->assertHasErrors('device');

    expect($admin->fresh()->setup_wizard_completed_at)->toBeNull();
});

it('completes once a device has heartbeated', function () {
    $admin = User::factory()->admin()->create();
    Device::create(['rustdesk_id' => '123456', 'uuid' => 'uuid-1', 'last_online_at' => now()]);

    Livewire::actingAs($admin)->test(SetupWizard::class)
        ->assertSet('deviceConnected', true)
        ->call('complete');

    expect($admin->fresh()->setup_wizard_completed_at)->not->toBeNull()
        ->and(SetupWizard::shouldPrompt($admin->fresh()))->toBeFalse();
});
