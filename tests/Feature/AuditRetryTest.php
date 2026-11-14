<?php

/*
 * Issue #88. RustDesk 1.5.0 retries an audit post unless the answer is a 2xx
 * with an EMPTY body, and tags each record with a "nonce" so the server can
 * drop the retries. We answered `{}`, so every record was stored three times.
 * Clipboard file transfers carry an empty path by design; their file names
 * are in info.files.
 */

use App\Livewire\FileTransferLog;
use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** A file audit as 1.5.0 posts it (connection.rs post_file_audit). */
function fileAudit(array $overrides = [], array $info = []): array
{
    return array_merge([
        'id' => '111', 'uuid' => 'dXVpZA==', 'peer_id' => '222', 'conn_id' => 5,
        'type' => 0, 'path' => '/home/ops/reports', 'is_file' => false,
        'info' => json_encode(array_merge(['ip' => '1.2.3.4', 'name' => 'ctrl', 'num' => 1, 'files' => [['a.txt', 12]]], $info)),
        'nonce' => '6f1c3b0e-4a7d-4e57-9a51-0c3c6d6f0a01',
    ], $overrides);
}

/** A clipboard copy-paste: empty path, names in info.files sorted by size. */
function clipboardAudit(array $overrides = []): array
{
    return fileAudit(['path' => '', 'nonce' => 'c1'] + $overrides, [
        'num' => 5,
        'files' => [['report.pdf', 9000], ['notes.txt', 300], ['a.csv', 20], ['b.csv', 10], ['c.csv', 5]],
    ]);
}

function connAudit(array $body): array
{
    return $body + ['id' => '111', 'uuid' => 'dXVpZA==', 'conn_id' => 5, 'session_id' => 0];
}

function retryAdmin(): User
{
    return User::factory()->admin()->create();
}

// --------------------------------------------------------- empty body ----

it('answers every tokenless audit post with 200 and an empty body', function (string $url, array $body) {
    $response = $this->postJson($url, $body)->assertOk();

    expect($response->getContent())->toBe('');
})->with([
    'conn new' => ['/api/audit/conn', ['action' => 'new', 'ip' => '1.2.3.4', 'id' => '111', 'uuid' => 'u', 'conn_id' => 5, 'session_id' => 0, 'nonce' => 'n1']],
    'conn authorized' => ['/api/audit/conn', ['peer' => ['222', 'Desk'], 'type' => 0, 'id' => '111', 'uuid' => 'u', 'conn_id' => 5, 'session_id' => 7, 'nonce' => 'n2']],
    'conn close' => ['/api/audit/conn', ['action' => 'close', 'id' => '111', 'uuid' => 'u', 'conn_id' => 5, 'session_id' => 7, 'nonce' => 'n3']],
    'conn without id' => ['/api/audit/conn', ['action' => 'new', 'conn_id' => 5]],
    'conn without conn_id' => ['/api/audit/conn', ['action' => 'new', 'id' => '111']],
    'note button' => ['/api/audit/conn', ['id' => '111', 'session_id' => 7, 'note' => 'hi']],
    'file' => ['/api/audit/file', ['id' => '111', 'type' => 0, 'path' => '/x', 'info' => '{}', 'nonce' => 'n4']],
    'file without id' => ['/api/audit/file', ['path' => '/x']],
    'alarm' => ['/api/audit/alarm', ['id' => '111', 'typ' => 2, 'info' => '{"ip":"1.2.3.4"}', 'conn_id' => 5, 'nonce' => 'n5']],
    'alarm without id' => ['/api/audit/alarm', ['typ' => 2]],
]);

// ------------------------------------------------------------- nonces ----

it('stores a retried file audit once', function () {
    foreach (range(1, 3) as $attempt) {
        expect($this->postJson('/api/audit/file', fileAudit())->assertOk()->getContent())->toBe('');
    }

    expect(AuditFileTransfer::count())->toBe(1);

    // A new record has a new nonce.
    $this->postJson('/api/audit/file', fileAudit(['nonce' => 'another']))->assertOk();
    expect(AuditFileTransfer::count())->toBe(2);
});

it('stores a retried alarm once', function () {
    $body = ['id' => '111', 'uuid' => 'u', 'typ' => 2, 'info' => '{"ip":"1.2.3.4"}', 'conn_id' => 5, 'nonce' => 'alarm-1'];

    $this->postJson('/api/audit/alarm', $body)->assertOk();
    $this->postJson('/api/audit/alarm', $body)->assertOk();

    expect(AlarmLog::count())->toBe(1);
});

it('keeps nonces per device and per endpoint', function () {
    $this->postJson('/api/audit/file', fileAudit(['nonce' => 'same']))->assertOk();
    $this->postJson('/api/audit/file', fileAudit(['nonce' => 'same', 'id' => '333']))->assertOk();
    $this->postJson('/api/audit/alarm', ['id' => '111', 'typ' => 2, 'info' => '{}', 'nonce' => 'same'])->assertOk();

    expect(AuditFileTransfer::count())->toBe(2)->and(AlarmLog::count())->toBe(1);
});

it('records a 1.5.0 connection once when every post is retried', function () {
    $new = connAudit(['action' => 'new', 'ip' => '1.2.3.4', 'nonce' => 'conn-new']);
    $authorized = connAudit(['peer' => ['222', 'Front Desk'], 'type' => 0, 'session_id' => 99, 'nonce' => 'conn-auth']);
    $close = connAudit(['action' => 'close', 'session_id' => 99, 'nonce' => 'conn-close']);

    foreach ([$new, $new, $new, $authorized, $authorized, $close, $close, $close] as $body) {
        $this->postJson('/api/audit/conn', $body)->assertOk();
    }

    $row = AuditConnection::sole();
    expect($row->from_peer)->toBe('222')
        ->and($row->session_id)->toBe('99')
        ->and($row->closed_at)->not->toBeNull();
});

it('releases the nonce and answers 500 when the write fails', function () {
    $fail = true;
    AuditFileTransfer::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: database is locked');
        }
    });

    $this->postJson('/api/audit/file', fileAudit())
        ->assertStatus(500)
        ->assertJsonPath('error', 'Audit record not stored');
    expect(AuditFileTransfer::count())->toBe(0);

    // The database recovers; the client's retry stores the record.
    $fail = false;

    expect($this->postJson('/api/audit/file', fileAudit())->assertOk()->getContent())->toBe('');
    expect(AuditFileTransfer::count())->toBe(1);
});

it('releases the nonce of a failed connection write', function () {
    $fail = true;
    AuditConnection::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('write failed');
        }
    });

    $new = connAudit(['action' => 'new', 'ip' => '1.2.3.4', 'nonce' => 'fails-once']);
    $this->postJson('/api/audit/conn', $new)->assertStatus(500);

    $fail = false;
    $this->postJson('/api/audit/conn', $new)->assertOk();

    expect(AuditConnection::count())->toBe(1);
});

// ------------------------------------------- retries without a nonce ----

it('ignores a repeated "new" for an open connection within two minutes', function () {
    $new = connAudit(['action' => 'new', 'ip' => '1.2.3.4']);

    $this->postJson('/api/audit/conn', $new)->assertOk();
    $this->travel(10)->seconds();
    $this->postJson('/api/audit/conn', $new)->assertOk();
    $this->travel(30)->seconds();
    $this->postJson('/api/audit/conn', $new)->assertOk();

    expect(AuditConnection::count())->toBe(1);
});

it('opens a new row for a reused conn_id after two minutes or once authorized', function () {
    $new = connAudit(['action' => 'new', 'ip' => '1.2.3.4']);

    $this->postJson('/api/audit/conn', $new)->assertOk();
    $this->travel(3)->minutes();
    $this->postJson('/api/audit/conn', $new)->assertOk();
    expect(AuditConnection::count())->toBe(2);

    // The service restarted and reused conn_id 7 while the old row was live.
    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'conn_id' => 7]))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['peer' => ['222', 'Desk'], 'type' => 0, 'conn_id' => 7, 'session_id' => 1]))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'conn_id' => 7]))->assertOk();
    expect(AuditConnection::where('conn_id', 7)->count())->toBe(2);
});

it('updates the right row on authorized and close after a dropped retry', function () {
    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'ip' => '1.2.3.4']))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'ip' => '1.2.3.4']))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'ip' => '5.6.7.8', 'conn_id' => 6]))->assertOk();

    $this->postJson('/api/audit/conn', connAudit(['peer' => ['222', 'Desk'], 'type' => 1, 'session_id' => 42]))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['action' => 'close', 'session_id' => 42]))->assertOk();

    $five = AuditConnection::where('conn_id', 5)->sole();
    $six = AuditConnection::where('conn_id', 6)->sole();
    expect($five->from_peer)->toBe('222')
        ->and($five->conn_type)->toBe(1)
        ->and($five->closed_at)->not->toBeNull()
        ->and($six->from_peer)->toBeNull()
        ->and($six->closed_at)->toBeNull();
});

it('still stores posts from clients before 1.5.0, which send no nonce', function () {
    $body = fileAudit();
    unset($body['nonce']);

    $this->postJson('/api/audit/file', $body)->assertOk();
    $this->postJson('/api/audit/file', $body)->assertOk();
    $this->postJson('/api/audit/alarm', ['id' => '111', 'typ' => 2, 'info' => '{}', 'conn_id' => 5])->assertOk();

    expect(AuditFileTransfer::count())->toBe(2)->and(AlarmLog::count())->toBe(1);
});

it('treats a blank nonce as no nonce', function () {
    $this->postJson('/api/audit/file', fileAudit(['nonce' => '  ']))->assertOk();
    $this->postJson('/api/audit/file', fileAudit(['nonce' => '  ']))->assertOk();

    expect(AuditFileTransfer::count())->toBe(2);
});

// ----------------------------------------------------- clipboard names ----

it('describes a clipboard transfer by its file names', function () {
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();
    $this->postJson('/api/audit/file', fileAudit(['nonce' => 'fm']))->assertOk();

    $clip = AuditFileTransfer::where('path', '')->sole();
    $fm = AuditFileTransfer::where('path', '!=', '')->sole();

    expect($clip->isClipboard())->toBeTrue()
        ->and($clip->pathLabel())->toBe('report.pdf, notes.txt +3 more')
        ->and($clip->fileNames())->toHaveCount(5)
        ->and($fm->isClipboard())->toBeFalse()
        ->and($fm->pathLabel())->toBe('/home/ops/reports');
});

it('shows a single-file job by its path, not its unnamed entry', function () {
    $this->postJson('/api/audit/file', fileAudit(['path' => '/tmp/a.iso', 'is_file' => true], ['files' => [['', 4096]]]))->assertOk();

    $row = AuditFileTransfer::sole();
    expect($row->isClipboard())->toBeFalse()
        ->and($row->fileNames())->toBe([])
        ->and($row->pathLabel())->toBe('/tmp/a.iso');
});

it('shows clipboard file names in the file transfer log', function () {
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();

    Livewire::actingAs(retryAdmin())
        ->test(FileTransferLog::class)
        ->assertSee('Clipboard')
        ->assertSee('report.pdf, notes.txt +3 more');
});

it('finds a clipboard transfer by a file name', function () {
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();
    $this->postJson('/api/audit/file', fileAudit(['id' => '444', 'nonce' => 'other']))->assertOk();

    Livewire::actingAs(retryAdmin())
        ->test(FileTransferLog::class)
        ->set('search', 'notes.txt')
        ->assertSee('report.pdf, notes.txt')
        ->assertDontSee('/home/ops/reports');
});

it('shows clipboard file names on the device detail page', function () {
    $device = Device::create(['rustdesk_id' => '111', 'uuid' => 'u', 'hostname' => 'DESK']);
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();

    $this->actingAs(retryAdmin())
        ->get('/devices/'.$device->id)
        ->assertOk()
        ->assertSee('Clipboard')
        ->assertSee('report.pdf, notes.txt +3 more');
});

it('appends the file names to the csv export', function () {
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();
    $this->actingAs(retryAdmin());

    $component = new FileTransferLog;
    ob_start();
    $component->export()->sendContent();
    $csv = ob_get_clean();

    $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    expect($lines[0])->toBe(['When', 'Device', 'From ID', 'From Name', 'Direction', 'Path', 'Files', 'IP', 'File names'])
        ->and($lines[1][5])->toBe('')
        ->and($lines[1][6])->toBe('5')
        ->and($lines[1][8])->toBe('report.pdf; notes.txt; a.csv; b.csv; c.csv');
});

it('lists the files and the clipboard flag in the admin api', function () {
    $this->postJson('/api/audit/file', clipboardAudit())->assertOk();
    [, $plain] = ApiToken::issue(retryAdmin(), 'audit-reader', ['audit' => 'r']);

    $this->getJson('/api/v1/audits/file', ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.0.path', '')
        ->assertJsonPath('data.0.clipboard', true)
        ->assertJsonPath('data.0.files.0', ['name' => 'report.pdf', 'size' => 9000])
        ->assertJsonCount(5, 'data.0.files');
});

// ------------------------------------------------------ dedupe command ----

/** Rows as 1.5.0 left them: the record, then copies 10s and 40s later. */
function storeRetried(callable $create, Carbon $at): void
{
    foreach ([0, 10, 40] as $offset) {
        Carbon::setTestNow($at->copy()->addSeconds($offset));
        $create();
    }
    Carbon::setTestNow();
}

function seedRetryCopies(): void
{
    Device::create(['rustdesk_id' => '111', 'uuid' => 'u', 'hostname' => 'NEW', 'version' => '1.5.0']);
    Device::create(['rustdesk_id' => '999', 'uuid' => 'v', 'hostname' => 'OLD', 'version' => '1.4.9']);

    $at = Carbon::parse('2026-10-03 09:00:00');
    $info = json_encode(['ip' => '1.2.3.4', 'name' => 'ctrl', 'num' => 1, 'files' => [['report.pdf', 9000]]]);
    $file = fn (string $id = '111') => AuditFileTransfer::create([
        'rustdesk_id' => $id, 'from_peer' => '222', 'from_name' => 'ctrl', 'path' => '',
        'info' => $info, 'is_file' => false, 'direction' => 0, 'file_count' => 1, 'ip' => '1.2.3.4', 'uuid' => 'u',
    ]);

    // Tripled clipboard transfer.
    storeRetried($file, $at);
    // The same transfer done again a minute later is a second, tripled record.
    storeRetried($file, $at->copy()->addSeconds(60));
    // A 1.4.9 device doing the same thing twice: real records, kept.
    storeRetried(fn () => $file('999'), $at);

    storeRetried(fn () => AlarmLog::create([
        'rustdesk_id' => '111', 'uuid' => 'u', 'typ' => 2, 'info' => '{"ip":"1.2.3.4"}', 'conn_id' => 5,
    ]), $at);

    // Connection 5: new x3, authorized updated the newest, close retries closed the rest.
    storeRetried(fn () => AuditConnection::create([
        'action' => 'new', 'conn_id' => 5, 'rustdesk_id' => '111', 'ip' => '1.2.3.4', 'session_id' => '0', 'uuid' => 'u',
    ]), $at);
    $rows = AuditConnection::orderBy('id')->get();
    $rows[2]->update(['action' => 'close', 'from_peer' => '222', 'from_name' => 'Desk', 'session_id' => '42', 'closed_at' => $at->copy()->addMinutes(5)]);
    $rows[1]->update(['action' => 'close', 'closed_at' => $at->copy()->addMinutes(5)]);

    // Connection 6, still open: new x3, authorized newest.
    storeRetried(fn () => AuditConnection::create([
        'action' => 'new', 'conn_id' => 6, 'rustdesk_id' => '111', 'ip' => '1.2.3.4', 'session_id' => '0', 'uuid' => 'u',
    ]), $at->copy()->addMinutes(10));
    AuditConnection::where('conn_id', 6)->orderByDesc('id')->first()->update(['action' => 'authorized', 'from_peer' => '333']);

    // Before 1.5.0 shipped: left alone unless --all.
    storeRetried($file, Carbon::parse('2026-09-20 09:00:00'));
}

it('reports retry copies without removing them by default', function () {
    seedRetryCopies();

    $this->artisan('cortendesk:dedupe-audits')
        ->expectsOutputToContain('Would remove 10 row(s) and fix the start time of 2 session(s)')
        ->assertSuccessful();

    expect(AuditFileTransfer::count())->toBe(12)
        ->and(AlarmLog::count())->toBe(3)
        ->and(AuditConnection::count())->toBe(6);
});

it('removes retry copies with --force and keeps the real records', function () {
    seedRetryCopies();

    $this->artisan('cortendesk:dedupe-audits', ['--force' => true])
        ->expectsOutputToContain('Removed 10 row(s)')
        ->assertSuccessful();

    expect(AuditFileTransfer::where('rustdesk_id', '111')->where('created_at', '>=', '2026-10-01')->count())->toBe(2)
        ->and(AuditFileTransfer::where('rustdesk_id', '999')->count())->toBe(3)
        ->and(AuditFileTransfer::where('created_at', '<', '2026-10-01')->count())->toBe(3)
        ->and(AlarmLog::count())->toBe(1);

    $five = AuditConnection::where('conn_id', 5)->sole();
    expect($five->from_peer)->toBe('222')
        ->and($five->session_id)->toBe('42')
        ->and($five->created_at->toDateTimeString())->toBe('2026-10-03 09:00:00');

    $six = AuditConnection::where('conn_id', 6)->sole();
    expect($six->from_peer)->toBe('333')
        ->and($six->closed_at)->toBeNull()
        ->and($six->created_at->toDateTimeString())->toBe('2026-10-03 09:10:00');

    // Nothing left to do.
    $this->artisan('cortendesk:dedupe-audits')->expectsOutputToContain('No retry copies found.');
});

it('looks at older rows and older clients only when asked', function () {
    seedRetryCopies();

    $this->artisan('cortendesk:dedupe-audits', ['--force' => true, '--all' => true])
        ->expectsOutputToContain('Removed 12 row(s)');

    $this->artisan('cortendesk:dedupe-audits', ['--force' => true, '--all' => true, '--any-version' => true])
        ->expectsOutputToContain('Removed 2 row(s)');

    expect(AuditFileTransfer::count())->toBe(4);
});

it('leaves identical records that do not follow the retry schedule', function () {
    Device::create(['rustdesk_id' => '111', 'uuid' => 'u', 'hostname' => 'NEW', 'version' => '1.5.1']);
    // Same second, then 60s and 90s later: a person, not the client's retries.
    foreach (['09:00:00', '09:00:00', '09:01:00', '09:02:30'] as $time) {
        Carbon::setTestNow('2026-10-03 '.$time);
        AuditFileTransfer::create(['rustdesk_id' => '111', 'path' => '/a', 'info' => '{}', 'direction' => 0]);
    }
    Carbon::setTestNow();

    $this->artisan('cortendesk:dedupe-audits', ['--force' => true])
        ->expectsOutputToContain('No retry copies found.');
    expect(AuditFileTransfer::count())->toBe(4);
});

// ---------------------------------------------------- oversize strings ----

// MySQL in strict mode rejects a string longer than its column. The record
// used to be lost with a 500; now the string is cut and the record kept.
it('keeps a file audit whose strings are longer than their columns', function () {
    $long = str_repeat('é', 300);

    $this->postJson('/api/audit/file', fileAudit(
        ['peer_id' => $long, 'uuid' => $long, 'path' => str_repeat('漢', 30000)],
        ['name' => $long, 'ip' => str_repeat('1', 60)],
    ))->assertOk();

    $row = AuditFileTransfer::sole();
    expect(mb_strlen($row->from_peer))->toBe(255)
        ->and(mb_strlen($row->from_name))->toBe(255)
        ->and(mb_strlen($row->uuid))->toBe(255)
        ->and(strlen($row->ip))->toBe(45)
        ->and(strlen($row->path))->toBe(65535 - 65535 % 3);
});

it('keeps a connection audit whose strings are longer than their columns', function () {
    $long = str_repeat('é', 300);

    $this->postJson('/api/audit/conn', connAudit(['action' => 'new', 'ip' => str_repeat('1', 60), 'uuid' => $long, 'nonce' => 'o1']))->assertOk();
    $this->postJson('/api/audit/conn', connAudit(['peer' => [$long, $long], 'type' => 0, 'session_id' => 7, 'nonce' => 'o2']))->assertOk();

    $row = AuditConnection::sole();
    expect($row->action)->toBe('authorized')
        ->and(mb_strlen($row->from_peer))->toBe(255)
        ->and(mb_strlen($row->from_name))->toBe(255)
        ->and(mb_strlen($row->uuid))->toBe(255)
        ->and(strlen($row->ip))->toBe(45);
});

it('keeps an alarm whose info is longer than its column', function () {
    $this->postJson('/api/audit/alarm', ['id' => '111', 'uuid' => 'u', 'typ' => 0, 'info' => str_repeat('x', 70000), 'nonce' => 'o3'])->assertOk();

    expect(strlen(AlarmLog::sole()->info))->toBe(65535);
});
