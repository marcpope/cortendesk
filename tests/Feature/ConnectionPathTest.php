<?php

use App\Livewire\ConnectionLog;
use App\Models\AuditConnection;
use App\Models\RelaySession;
use App\Models\User;
use App\Services\ConnectionPath;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * How each connection got through (issue #130): hbbs's tag, echoed by the
 * device in its connection audit, and the sessions hbbr reports it carried.
 */

const PATH_SECRET = 'path-test-secret';

beforeEach(function () {
    config(['cortendesk.server_secret' => PATH_SECRET]);
    $this->travelTo(now()->startOfMinute());
});

function relayReport(array $session): TestResponse
{
    return test()->postJson('/api/server/relay-sessions', ['sessions' => [$session + [
        'uuid' => 'r-1', 'id' => '111111111', 'conn_type' => 0,
        'controller_ip' => '203.0.113.5', 'device_ip' => '198.51.100.7',
        'started_at' => now()->timestamp,
    ]]], ['Authorization' => 'Bearer '.PATH_SECRET]);
}

function connNew(int $connId, ?string $tag = null): void
{
    test()->postJson('/api/audit/conn', array_filter([
        'action' => 'new', 'ip' => '203.0.113.5', 'id' => '111111111', 'uuid' => 'u',
        'conn_id' => $connId, 'session_id' => 0, 'conn_audit_ref' => $tag,
    ], fn ($v) => $v !== null))->assertOk();
}

function connAuthorized(int $connId, int $type = 0): void
{
    test()->postJson('/api/audit/conn', [
        'peer' => ['222222222', 'Front Desk'], 'type' => $type,
        'id' => '111111111', 'uuid' => 'u', 'conn_id' => $connId, 'session_id' => 77,
    ])->assertOk();
}

function connClose(int $connId): void
{
    test()->postJson('/api/audit/conn', [
        'action' => 'close', 'id' => '111111111', 'uuid' => 'u', 'conn_id' => $connId, 'session_id' => 77,
    ])->assertOk();
}

function connRow(int $connId): AuditConnection
{
    return AuditConnection::where('conn_id', $connId)->sole();
}

it('only takes relay reports with the server secret', function () {
    test()->postJson('/api/server/relay-sessions', ['sessions' => []])->assertUnauthorized();
    test()->postJson('/api/server/relay-sessions', ['sessions' => []], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

    expect(RelaySession::count())->toBe(0);
});

it('stores relay sessions by uuid, in the console session types', function () {
    relayReport(['conn_type' => 5])->assertOk()->assertJson(['stored' => 1]);
    relayReport(['conn_type' => 5, 'ended_at' => now()->addMinutes(3)->timestamp, 'bytes' => 5000])->assertOk();
    // A late copy of the open does not reopen it.
    relayReport(['conn_type' => 5])->assertOk();

    $relay = RelaySession::sole();
    expect($relay->rustdesk_id)->toBe('111111111')
        ->and($relay->conn_type)->toBe(4)
        ->and($relay->controller_ip)->toBe('203.0.113.5')
        ->and($relay->bytes)->toBe(5000)
        ->and($relay->ended_at)->not->toBeNull()
        ->and($relay->ended_at->lte(now()))->toBeTrue('a clock ahead is clamped to now');
});

it('keeps the path hbbs tagged the connection with', function () {
    connNew(1, 'cd1/punch-webrtc');
    connNew(2, 'cd1/lan');
    connNew(3, 'something-else');
    connNew(4);

    expect(connRow(1)->path)->toBe('punch-webrtc')
        ->and(connRow(1)->transport())->toBe('direct-webrtc')
        ->and(connRow(2)->transport())->toBe('lan')
        ->and(connRow(3)->path)->toBeNull()
        ->and(connRow(4)->transport())->toBeNull();
});

it('marks a session the relay carried from start to end as relayed', function () {
    connNew(1, 'cd1/punch');
    relayReport([]);
    connAuthorized(1);

    expect(connRow(1)->transport())->toBe('relay');

    $this->travel(10)->minutes();
    connClose(1);
    relayReport(['ended_at' => now()->timestamp, 'bytes' => 400_000]);

    $row = connRow(1);
    expect($row->transport())->toBe('relay')
        ->and($row->relaySession->bytes)->toBe(400_000);
});

it('does not count a relay attempt that lost the race to a direct one', function () {
    // The direct attempt logs in; the relay attempt is its own connection.
    connNew(1, 'cd1/punch');
    connNew(2, 'cd1/punch');
    relayReport([]);
    connAuthorized(1);

    $this->travel(3)->seconds();
    connClose(2);
    relayReport(['ended_at' => now()->timestamp, 'bytes' => 2_000]);

    $this->travel(20)->minutes();
    connClose(1);

    expect(connRow(1)->transport())->toBe('direct')
        ->and(connRow(1)->relay_session_id)->toBeNull();
});

it('keeps the relay when the device closes before the relay reports it', function () {
    connNew(1, 'cd1/relay');
    connAuthorized(1, 1);
    relayReport(['conn_type' => 1]);

    $this->travel(5)->minutes();
    connClose(1);
    $this->travel(5)->seconds();
    relayReport(['conn_type' => 1, 'ended_at' => now()->timestamp, 'bytes' => 10_000]);

    expect(connRow(1)->relay_session_id)->not->toBeNull();
});

it('counts a relay that carried real traffic even when the close came late', function () {
    connNew(1, 'cd1/punch');
    connAuthorized(1);
    relayReport([]);

    $this->travel(10)->minutes();
    relayReport(['ended_at' => now()->timestamp, 'bytes' => 50_000_000]);
    // The device never said; the heartbeat sweep closes it much later.
    $this->travel(10)->minutes();
    connRow(1)->update(['action' => 'close', 'closed_at' => now()]);
    ConnectionPath::refresh(connRow(1));

    expect(connRow(1)->transport())->toBe('relay');
});

it('does not match a relay session of another kind or another device', function () {
    connNew(1, 'cd1/punch');
    connAuthorized(1, 0);
    relayReport(['conn_type' => 1]);
    relayReport(['uuid' => 'r-2', 'id' => '999999999']);

    expect(connRow(1)->relay_session_id)->toBeNull();
});

it('filters and exports the connection log by path', function () {
    connNew(1, 'cd1/punch');
    connAuthorized(1);
    connNew(2, 'cd1/lan');
    connAuthorized(2);
    connNew(3);
    connAuthorized(3);
    connNew(4, 'cd1/punch');
    relayReport([]);
    connAuthorized(4);
    // An attempt that never logged in: listed, but under no path (#169).
    connNew(5, 'cd1/punch');
    // Conns 1-3 logged in first and match too; keep the relay on 4 only.
    foreach ([1, 2, 3] as $id) {
        connRow($id)->forceFill(['created_at' => now()->subMinutes(5)])->save();
    }
    ConnectionPath::linkRelay(RelaySession::sole());

    $admin = User::factory()->admin()->create();
    $log = Livewire::actingAs($admin)->test(ConnectionLog::class);

    $ids = fn () => $log->viewData('connections')->pluck('conn_id')->sort()->values()->all();
    expect($ids())->toBe([1, 2, 3, 4, 5]);
    $log->set('path', 'relay');
    expect($ids())->toBe([4]);
    $log->set('path', 'direct');
    expect($ids())->toBe([1]);
    $log->set('path', 'lan');
    expect($ids())->toBe([2]);
    $log->set('path', 'unknown');
    expect($ids())->toBe([3]);

    $log->set('path', '')->assertSee(__('logs.path_relay'))->assertSee(__('logs.path_lan'));

    $export = $log->call('export')->assertFileDownloaded('connection-log.csv');
    expect(base64_decode($export->effects['download']['content']))->toContain(',Path,')->toContain(',relay,');
});

it('shows how sessions connected on the dashboard once paths are known', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/')->assertOk()->assertDontSee(__('overview.how_sessions_connected'));

    connNew(1, 'cd1/punch');
    connAuthorized(1);

    $this->actingAs($admin)->get('/')->assertOk()
        ->assertSee(__('overview.how_sessions_connected'))
        ->assertSee(__('logs.path_direct'));
});

it('opens the log with the same rows the dashboard counts (issue #169)', function () {
    connNew(1, 'cd1/punch');
    connAuthorized(1);
    connNew(2, 'cd1/punch');
    connAuthorized(2, 1); // a file transfer
    connNew(3, 'cd1/punch'); // never logged in
    connNew(4, 'cd1/punch');
    connAuthorized(4);
    connRow(4)->forceFill(['created_at' => now()->subDays(20)])->save(); // before the 14 days

    $admin = User::factory()->admin()->create();
    $from = now()->subDays(13)->toDateString();
    $this->actingAs($admin)->get('/?range=14')->assertOk()
        ->assertSee(e(route('logs.connections', ['path' => 'direct', 'dateFrom' => $from])), false);

    $log = Livewire::actingAs($admin)->withQueryParams(['path' => 'direct', 'dateFrom' => $from])->test(ConnectionLog::class);
    expect($log->viewData('connections')->pluck('conn_id')->sort()->values()->all())->toBe([1, 2]);
});
