<?php

/*
 * End-of-session notes (issue #79). The controlling client fetches the
 * session's guid from GET /api/audit/conn/active, then sends the note with
 * PUT /api/audit {guid, note}. The in-session Note button posts
 * {id, session_id, note} to /api/audit/conn without a token.
 */

use App\Livewire\ConnectionLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\ClientToken;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

const NOTE_SESSION = '15532460215839577001'; // past PHP_INT_MAX on purpose

function noteToken(?User $user = null, ?string $device = '999888777'): array
{
    // The request guard caches its user for the life of the app, and tests
    // reuse one app across requests: without this the second request skips
    // the callback that attaches the token.
    app('auth')->forgetGuards();

    $user ??= User::factory()->create();
    $token = ClientToken::issue($user, ['id' => $device]);

    return ['Authorization' => "Bearer {$token->token}"];
}

/** Controlled device 111 accepts controller 999888777, as the client posts it. */
function openNoteSession(int $type = 0): AuditConnection
{
    test()->postJson('/api/audit/conn', [
        'action' => 'new', 'ip' => '1.2.3.4', 'id' => '111', 'uuid' => 'u',
        'conn_id' => 5, 'session_id' => 0,
    ])->assertOk();

    // Raw body: a u64 session_id is a bare JSON number, which PHP would
    // otherwise decode as a float.
    test()->call('POST', '/api/audit/conn', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        '{"peer":["999888777","Front Desk"],"type":'.$type.',"id":"111","uuid":"u","conn_id":5,"session_id":'.NOTE_SESSION.'}',
    )->assertOk();

    return AuditConnection::sole();
}

function activeUrl(int $type = 0): string
{
    return '/api/audit/conn/active?id=111&session_id='.NOTE_SESSION.'&conn_type='.$type;
}

it('gives every session a guid and keeps the u64 session id exact', function () {
    $row = openNoteSession();

    expect($row->guid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($row->session_id)->toBe(NOTE_SESSION);
});

it('returns the guid to the device that opened the session', function () {
    $row = openNoteSession();

    $response = $this->getJson(activeUrl(), noteToken())->assertOk();

    // A bare JSON string: the client runs jsonDecode(body) as String.
    expect($response->getContent())->toBe(json_encode($row->guid));
});

it('answers 200 with an empty string until the session is recorded, so the client keeps retrying', function () {
    $response = $this->getJson(activeUrl(), noteToken())->assertOk();
    expect($response->getContent())->toBe('""');

    $row = openNoteSession();

    expect($this->getJson(activeUrl(), noteToken())->getContent())->toBe(json_encode($row->guid));
});

it('does not hand a guid to another device, or for another session type', function () {
    openNoteSession();

    expect($this->getJson(activeUrl(), noteToken(device: '123123123'))->getContent())->toBe('""')
        ->and($this->getJson(activeUrl(), noteToken(device: null))->getContent())->toBe('""')
        ->and($this->getJson(activeUrl(1), noteToken())->getContent())->toBe('""');
});

it('requires a bearer token for the guid and the note', function () {
    $row = openNoteSession();

    $this->getJson(activeUrl())->assertUnauthorized();
    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'x'])->assertUnauthorized();

    expect($row->fresh()->note)->toBeNull();
});

it('gives an older row without a guid one on first request', function () {
    $row = openNoteSession();
    AuditConnection::whereKey($row->id)->update(['guid' => null]);

    $guid = json_decode($this->getJson(activeUrl(), noteToken())->getContent());

    expect($guid)->not->toBeEmpty()->and($row->fresh()->guid)->toBe($guid);
});

it('stores the end-of-session note with its author', function () {
    $row = openNoteSession();
    $user = User::factory()->create(['username' => 'tech1']);

    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => "  Replaced the printer driver.\n"], noteToken($user))
        ->assertOk();

    $row->refresh();
    expect($row->note)->toBe('Replaced the printer driver.')
        ->and($row->note_user_id)->toBe($user->id)
        ->and($row->noted_at)->not->toBeNull();
});

it('refuses a note from another device, for an unknown guid, or over another user\'s note', function () {
    $row = openNoteSession();
    $first = User::factory()->create();

    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'nope'], noteToken(device: '123123123'))
        ->assertForbidden();
    $this->putJson('/api/audit', ['guid' => '00000000-0000-0000-0000-000000000000', 'note' => 'nope'], noteToken())
        ->assertNotFound();

    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'first'], noteToken($first))->assertOk();
    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'second'], noteToken())->assertStatus(409);
    // The author can still correct it.
    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'first, fixed'], noteToken($first))->assertOk();

    expect($row->fresh()->note)->toBe('first, fixed');
});

it('stores the tokenless in-session note from the Note button', function () {
    $row = openNoteSession();

    $this->call('POST', '/api/audit/conn', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        '{"id":"111","session_id":'.NOTE_SESSION.',"note":"Checking backups"}',
    )->assertOk();

    expect($row->fresh()->note)->toBe('Checking backups')
        ->and($row->fresh()->note_user_id)->toBeNull();

    // It never replaces a note a signed-in user wrote.
    $this->putJson('/api/audit', ['guid' => $row->guid, 'note' => 'Signed note'], noteToken())->assertOk();
    $this->call('POST', '/api/audit/conn', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        '{"id":"111","session_id":'.NOTE_SESSION.',"note":"overwrite"}',
    )->assertOk();

    expect($row->fresh()->note)->toBe('Signed note');
});

it('shows the note in the connection log, its search and its CSV export', function () {
    $row = openNoteSession();
    $row->update(['note' => 'Installed updates', 'note_user_id' => User::factory()->create(['username' => 'tech2'])->id]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ConnectionLog::class)
        ->assertSee('Installed updates')
        ->assertSee('tech2')
        ->set('search', 'updates')
        ->assertSee('Installed updates');

    $this->actingAs($admin);
    ob_start();
    (new ConnectionLog)->export()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Note,"Note By"')->toContain('"Installed updates",tech2');
});

it('shows the note on the device page', function () {
    $row = openNoteSession();
    $row->update(['note' => 'Reset the VPN profile']);
    $device = Device::create(['rustdesk_id' => '111', 'uuid' => 'note-uuid', 'hostname' => 'front-desk']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devices/'.$device->id)
        ->assertOk()
        ->assertSee('Reset the VPN profile');
});

it('appends the note to the admin API connection listing', function () {
    $row = openNoteSession();
    $author = User::factory()->create(['username' => 'tech3']);
    $row->update(['note' => 'Rebooted', 'note_user_id' => $author->id, 'noted_at' => now()]);
    [, $plain] = ApiToken::issue(User::factory()->admin()->create(), 'notes', ['audit' => 'r']);

    $this->getJson('/api/v1/audits/conn', ['Authorization' => 'Bearer '.$plain])
        ->assertOk()
        ->assertJsonPath('data.0.note', 'Rebooted')
        ->assertJsonPath('data.0.note_by', 'tech3')
        ->assertJsonPath('data.0.session_id', NOTE_SESSION);
});
