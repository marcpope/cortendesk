<?php

use App\Livewire\UserList;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('renders the users page for an admin', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/users')
        ->assertOk()
        ->assertSeeLivewire(UserList::class);
});

it('creates a user from the modal', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('create')
        ->assertSet('showModal', true)
        ->set('username', 'jdoe')
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@example.com')
        ->set('is_admin', false)
        ->set('is_active', true)
        ->set('password', 'supersecret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $user = User::where('username', 'jdoe')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email)->toBe('jane@example.com')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('supersecret', $user->password))->toBeTrue();
});

it('requires a password when creating a user', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('create')
        ->set('username', 'nopass')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors(['password' => 'required']);
});

it('prevents deleting yourself', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('deleteUser', $admin->id);

    expect(User::find($admin->id))->not->toBeNull();
});

it('prevents disabling yourself', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('toggleActive', $admin->id);

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('keeps the password when editing with a blank password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['password' => 'original-pass']);
    $originalHash = $user->fresh()->getAuthPassword();

    Livewire::actingAs($admin)
        ->test(UserList::class)
        ->call('edit', $user->id)
        ->assertSet('editing', $user->id)
        ->assertSet('username', $user->username)
        ->assertSet('password', '')
        ->set('name', 'Renamed Person')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Renamed Person')
        ->and($user->getAuthPassword())->toBe($originalHash)
        ->and(Hash::check('original-pass', $user->password))->toBeTrue();
});

it('force logout revokes client tokens, sessions and remember token', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['remember_token' => 'old-remember']);
    \App\Models\ClientToken::create([
        'user_id' => $user->id,
        'token' => 'tok-123',
        'device_id' => '111222333',
        'device_uuid' => 'uuid-1',
    ]);
    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        'id' => 'sess-1', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 't', 'payload' => base64_encode('x'), 'last_activity' => time(),
    ]);

    Livewire::actingAs($admin)->test(UserList::class)->call('forceLogout', $user->id);

    expect(\App\Models\ClientToken::where('user_id', $user->id)->count())->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe('old-remember');
});

it('disabling a user revokes access immediately', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    \App\Models\ClientToken::create([
        'user_id' => $user->id, 'token' => 'tok-456',
        'device_id' => '444555666', 'device_uuid' => 'uuid-2',
    ]);

    Livewire::actingAs($admin)->test(UserList::class)->call('toggleActive', $user->id);

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(\App\Models\ClientToken::where('user_id', $user->id)->count())->toBe(0);
});

it('re-enabling a user does not touch tokens', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['is_active' => false]);

    Livewire::actingAs($admin)->test(UserList::class)->call('toggleActive', $user->id);

    expect($user->fresh()->is_active)->toBeTrue();
});

it('kicks a disabled user out of a live console session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk();

    $user->update(['is_active' => false]);

    $this->actingAs($user)->get('/')
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('cannot force logout yourself', function () {
    $admin = User::factory()->admin()->create(['remember_token' => 'keep-me']);

    Livewire::actingAs($admin)->test(UserList::class)->call('forceLogout', $admin->id);

    expect($admin->fresh()->remember_token)->toBe('keep-me');
});

it('bulk-assigns selected devices to a user and releases the rest', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $other = User::factory()->create();

    $keep = \App\Models\Device::create(['rustdesk_id' => '100000001', 'uuid' => 'u1']);
    $add = \App\Models\Device::create(['rustdesk_id' => '100000002', 'uuid' => 'u2']);
    // Currently owned by target but NOT reselected -> should be released.
    $drop = \App\Models\Device::create(['rustdesk_id' => '100000003', 'uuid' => 'u3', 'user_id' => $target->id]);

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Livewire\UserList::class)
        ->call('openAssign', $target->id)
        ->set('assignDeviceIds', [$keep->id, $add->id])
        ->call('saveAssign');

    expect($keep->fresh()->user_id)->toBe($target->id)
        ->and($add->fresh()->user_id)->toBe($target->id)
        ->and($drop->fresh()->user_id)->toBeNull()
        ->and(\App\Models\ConsoleAudit::where('action', 'user.assign-devices')->count())->toBe(1);
});

it('preselects the devices a user already owns when opening assign', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $owned = \App\Models\Device::create(['rustdesk_id' => '100000004', 'uuid' => 'u4', 'user_id' => $target->id]);
    \App\Models\Device::create(['rustdesk_id' => '100000005', 'uuid' => 'u5']);

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Livewire\UserList::class)
        ->call('openAssign', $target->id)
        ->assertSet('assignDeviceIds', [$owned->id]);
});
