<?php

use App\Livewire\ApiTokenManager;
use App\Models\ApiToken;
use App\Models\ConsoleAudit;
use App\Models\User;
use Livewire\Livewire;

function tokenAdmin(): User
{
    return User::factory()->admin()->create();
}

it('creates a token, shows the plaintext once, and audits it', function () {
    $admin = tokenAdmin();

    $component = Livewire::actingAs($admin)
        ->test(ApiTokenManager::class)
        ->call('create')
        ->set('name', 'CI pipeline')
        ->set('permissions.device', 'rw')
        ->set('permissions.audit', 'r')
        ->call('save')
        ->assertHasNoErrors();

    $plain = $component->get('plaintext');
    expect($plain)->toStartWith('cdk_');

    $token = ApiToken::first();
    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('CI pipeline')
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->levelFor('device'))->toBe('rw')
        ->and($token->levelFor('audit'))->toBe('r')
        ->and($token->levelFor('user'))->toBe('none');

    expect(ConsoleAudit::where('action', 'api-token.create')->exists())->toBeTrue();
});

it('stores an expiry when a lifetime is given', function () {
    Livewire::actingAs(tokenAdmin())
        ->test(ApiTokenManager::class)
        ->call('create')
        ->set('name', 'temp')
        ->set('permissions.user', 'r')
        ->set('expiresDays', 7)
        ->call('save')
        ->assertHasNoErrors();

    expect(ApiToken::first()->expires_at)->not->toBeNull();
});

it('rejects a token with no permissions granted', function () {
    Livewire::actingAs(tokenAdmin())
        ->test(ApiTokenManager::class)
        ->call('create')
        ->set('name', 'useless')
        ->call('save')
        ->assertHasErrors('permissions');

    expect(ApiToken::count())->toBe(0);
});

it('requires a name', function () {
    Livewire::actingAs(tokenAdmin())
        ->test(ApiTokenManager::class)
        ->call('create')
        ->set('permissions.user', 'r')
        ->call('save')
        ->assertHasErrors('name');
});

it('revokes a token and audits it', function () {
    $admin = tokenAdmin();
    [$token] = ApiToken::issue($admin, 'to-revoke', ['user' => 'r']);

    Livewire::actingAs($admin)
        ->test(ApiTokenManager::class)
        ->call('revoke', $token->id);

    expect(ApiToken::find($token->id))->toBeNull()
        ->and(ConsoleAudit::where('action', 'api-token.revoke')->exists())->toBeTrue();
});

it('renders the token manager on the settings page for an admin', function () {
    $admin = tokenAdmin();
    ApiToken::issue($admin, 'visible-token', ['device' => 'rw']);

    $this->actingAs($admin)
        ->get(route('settings'))
        ->assertOk()
        ->assertSeeLivewire(ApiTokenManager::class)
        ->assertSee('visible-token');
});

it('forbids a non-admin from the API token manager', function () {
    \Livewire\Livewire::actingAs(\App\Models\User::factory()->create())
        ->test(\App\Livewire\ApiTokenManager::class)
        ->assertForbidden();
});
