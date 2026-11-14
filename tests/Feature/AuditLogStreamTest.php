<?php

use App\Http\Middleware\ApiTokenCan;
use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use App\Models\RelaySession;
use App\Models\User;
use App\Support\AuditLogStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Point the audit channel at a temp file and return a reader for its JSON lines. */
function streamToFile(bool $enabled = true): Closure
{
    $file = tempnam(sys_get_temp_dir(), 'audit');
    config([
        'cortendesk.audit_log' => $enabled,
        'logging.channels.cortendesk_audit.handler_with.stream' => $file,
    ]);
    Log::forgetChannel(AuditLogStream::CHANNEL);

    return function () use ($file): array {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

        foreach ($lines as $line) {
            expect($line)->toStartWith('{"cortendesk_audit":1,')
                ->and(strlen($line))->toBeLessThanOrEqual(AuditLogStream::MAX_LINE_BYTES);
        }

        return array_map(fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR), $lines);
    };
}

function streamAdmin(): User
{
    return User::create([
        'username' => 'auditor',
        'name' => 'Auditor',
        'email' => 'auditor@example.com',
        'password' => 'secret-password',
        'is_admin' => true,
        'is_active' => true,
    ]);
}

it('writes nothing unless switched on', function () {
    $read = streamToFile(enabled: false);

    ConsoleAudit::system('logs.prune', 'Pruned logs');
    LoginLog::create(['username' => 'x', 'client' => 'console', 'ip' => '192.0.2.1', 'successful' => false]);

    expect($read())->toBe([]);
});

it('streams console actions, timed by the row', function () {
    $read = streamToFile();
    $this->travelTo(now()->setDate(2026, 3, 4)->setTime(5, 6, 7));

    ConsoleAudit::system('device.approve', 'Approved device 123456789', 'device', '123456789');

    $line = $read()[0];
    expect($line)->toMatchArray([
        'source' => 'console',
        'event' => 'device.approve',
        'user' => 'system',
        'target_type' => 'device',
        'target_id' => '123456789',
        'summary' => 'Approved device 123456789',
        'ts' => '2026-03-04T05:06:07.000000Z',
    ])->and($line['id'])->toBe(ConsoleAudit::sole()->id);
});

it('leaves the error out of failed backup summaries', function () {
    $read = streamToFile();

    ConsoleAudit::system('backup.restore', 'Restore of b.zip failed: SQLSTATE[HY000] secret host db.internal', 'backup', 'b.zip');
    ConsoleAudit::system('backup.create', 'Scheduled backup: Backup failed: ftp://user:pw@host refused', 'backup', null);

    [$restore, $create] = $read();
    expect($restore['summary'])->toBe('Restore of b.zip failed')
        ->and($create['summary'])->toBe('Scheduled backup: Backup failed');
});

it('streams sign-ins, and keeps a failed one\'s name only when it is an account', function () {
    streamAdmin();
    $read = streamToFile();

    LoginLog::create(['username' => 'alice', 'client' => 'console', 'ip' => '192.0.2.10', 'successful' => true]);
    LoginLog::create(['username' => 'auditor', 'client' => 'client', 'device_id' => '123', 'ip' => '192.0.2.66', 'successful' => false, 'note' => 'bad password']);
    LoginLog::create(['username' => 'hunter2!Secret', 'client' => 'web', 'ip' => '192.0.2.67', 'successful' => false]);

    [$ok, $bad, $typo] = $read();
    expect($ok)->toMatchArray(['source' => 'login', 'event' => 'login.success', 'user' => 'alice', 'ip' => '192.0.2.10'])
        ->and($ok)->not->toHaveKey('unknown_user')
        ->and($bad)->toMatchArray(['event' => 'login.failure', 'user' => 'auditor', 'device' => '123', 'note' => 'bad password'])
        ->and($typo)->toMatchArray(['event' => 'login.failure', 'unknown_user' => true, 'ip' => '192.0.2.67'])
        ->and($typo)->not->toHaveKey('user');
});

it('streams a session from open to close, with its path, without the uuid, guid or note', function () {
    $read = streamToFile();

    $conn = AuditConnection::create([
        'action' => 'new', 'conn_id' => 7, 'rustdesk_id' => '123456789', 'ip' => '192.0.2.20',
        'session_id' => '42', 'uuid' => 'ZGV2aWNlLXV1aWQ=', 'path' => 'punch',
    ]);
    $conn->update(['action' => 'authorized', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'conn_type' => 0]);
    $conn->update(['note' => 'private remark']);
    $this->travel(5)->minutes();
    $conn->update(['action' => 'close', 'closed_at' => now()]);

    $lines = $read();
    $all = json_encode($lines);
    expect(array_column($lines, 'event'))->toBe(['connection.new', 'connection.authorized', 'connection.close'])
        ->and($lines[1])->toMatchArray(['device' => '123456789', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'conn_id' => 7, 'path' => 'punch', 'transport' => 'direct'])
        ->and($lines[2]['ts'])->toBe($conn->closed_at->utc()->format('Y-m-d\TH:i:s.u\Z'))
        ->and($lines[2]['ts'])->not->toBe($lines[0]['ts'])
        ->and($all)->not->toContain('ZGV2aWNlLXV1aWQ=')
        ->and($all)->not->toContain($conn->guid)
        ->and($all)->not->toContain('private remark');
});

it('reports transport as relay once the relay link is in place', function () {
    $read = streamToFile();
    $relay = RelaySession::forceCreate(['uuid' => 'relay-1', 'rustdesk_id' => '9', 'started_at' => now()]);

    $conn = AuditConnection::create(['action' => 'new', 'conn_id' => 1, 'rustdesk_id' => '9']);
    $conn->forceFill(['relay_session_id' => $relay->id])->saveQuietly();
    $conn->update(['action' => 'close', 'closed_at' => now()]);

    [$new, $close] = $read();
    expect($new)->not->toHaveKey('transport')
        ->and($close['transport'])->toBe('relay');
});

it('names a session row with no known action connection.unknown', function () {
    $read = streamToFile();

    AuditConnection::create(['action' => null, 'conn_id' => 3, 'rustdesk_id' => '1']);
    AuditConnection::create(['action' => 'weird', 'conn_id' => 4, 'rustdesk_id' => '1']);

    [$none, $weird] = $read();
    expect($none['event'])->toBe('connection.unknown')
        ->and($none)->not->toHaveKey('action')
        ->and($weird)->toMatchArray(['event' => 'connection.unknown', 'action' => 'weird']);
});

it('streams sessions the console closes in bulk, with their reason', function () {
    $conn = AuditConnection::create(['action' => 'authorized', 'conn_id' => 9, 'rustdesk_id' => '555', 'from_peer' => 'web-1']);
    $conn->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    $read = streamToFile();

    expect(AuditConnection::reconcileFor('555', []))->toBe(1);

    $lines = $read();
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['event' => 'connection.close', 'device' => '555', 'reason' => 'reconciled'])
        ->and($conn->fresh()->closed_at)->not->toBeNull();
});

it('sends no UPDATE when a bulk close finds nothing, and only one when the stream is off', function () {
    $conn = AuditConnection::create(['action' => 'authorized', 'conn_id' => 9, 'rustdesk_id' => '555']);
    $conn->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower(strtok($q->sql, ' '));
    });

    streamToFile();
    expect(AuditConnection::reconcileFor('555', [9]))->toBe(0)
        ->and($queries)->toBe(['select']);

    $queries = [];
    streamToFile(enabled: false);
    expect(AuditConnection::reconcileFor('555', []))->toBe(1)
        ->and($queries)->toBe(['update']);
});

it('writes nothing for rows that are rolled back, and writes on commit', function () {
    $read = streamToFile();

    DB::beginTransaction();
    ConsoleAudit::system('device.approve', 'Rolled back');
    expect($read())->toBe([]);
    DB::rollBack();

    DB::transaction(fn () => ConsoleAudit::system('device.approve', 'Kept'));

    expect(array_column($read(), 'summary'))->toBe(['Kept']);
});

it('streams file transfers and alarms, without typed names that match no account', function () {
    streamAdmin();
    $read = streamToFile();

    AuditFileTransfer::create([
        'rustdesk_id' => '123', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'path' => 'C:\\Users\\Public\\report.pdf',
        'info' => '{}', 'is_file' => true, 'direction' => 0, 'file_count' => 1, 'ip' => '192.0.2.30', 'uuid' => 'dXVpZA==',
    ]);
    AlarmLog::console(AlarmLog::TYP_BRUTE_FORCE, ['username' => 'auditor', 'ip' => '192.0.2.5']);
    AlarmLog::console(AlarmLog::TYP_SPRAYING, ['last_username' => 'pa55word!', 'ip' => '192.0.2.6']);

    [$file, $known, $typo] = $read();
    expect($file)->toMatchArray(['source' => 'file_transfer', 'device' => '123', 'path' => 'C:\\Users\\Public\\report.pdf', 'file_count' => 1])
        ->and($file)->not->toHaveKey('uuid')
        ->and($known)->toMatchArray(['source' => 'alarm', 'event' => 'alarm.100', 'device' => 'console', 'alarm' => 'Console brute force'])
        ->and(json_decode($known['info'], true))->toMatchArray(['username' => 'auditor'])
        ->and(json_decode($typo['info'], true))->toBe(['ip' => '192.0.2.6', 'unknown_user' => true]);
});

it('streams admin-API token use and refusals with their status, never the token', function () {
    $admin = streamAdmin();
    [$token, $plain] = ApiToken::issue($admin, 'Inventory sync', ['device' => 'r']);
    $read = streamToFile();

    $this->getJson('/api/v1/devices', ['Authorization' => 'Bearer '.$plain])->assertOk();
    $this->getJson('/api/v1/users', ['Authorization' => 'Bearer '.$plain])->assertForbidden();
    $unauthenticated = (new ApiTokenCan)->handle(Request::create('/api/v1/devices'), fn () => response('no'), 'device');

    [$use, $denied, $noToken] = $read();
    expect($use)->toMatchArray([
        'source' => 'api_token', 'event' => 'api_token.use', 'token_id' => $token->id, 'token_name' => 'Inventory sync',
        'user' => 'auditor', 'resource' => 'device', 'level' => 'r', 'method' => 'GET', 'path' => '/api/v1/devices',
    ])->and($use)->not->toHaveKey('status')
        ->and($denied)->toMatchArray(['event' => 'api_token.denied', 'resource' => 'user', 'status' => 403])
        ->and($unauthenticated->getStatusCode())->toBe(401)
        ->and($noToken)->toMatchArray(['event' => 'api_token.denied', 'status' => 401]);

    $all = json_encode($read());
    expect($all)->not->toContain($plain)
        ->and($all)->not->toContain($token->token_prefix)
        ->and($all)->not->toContain(hash('sha256', $plain));
});

it('cuts long free text', function () {
    $read = streamToFile();

    AlarmLog::create(['rustdesk_id' => '1', 'typ' => 9, 'info' => str_repeat('y', 5000)]);

    expect(strlen($read()[0]['info']))->toBeLessThanOrEqual(1003);
});

it('keeps every line under the byte cap, cutting the longest strings first', function () {
    $read = streamToFile();

    // 1000 characters each, but 3 to 6 bytes a character once encoded. Not saved: the columns hold 255.
    AuditLogStream::fileTransfer(new AuditFileTransfer([
        'rustdesk_id' => '1', 'from_peer' => str_repeat("\u{1F600}", 1000), 'from_name' => str_repeat("\x01", 1000),
        'path' => str_repeat('漢', 1000), 'info' => '{}', 'is_file' => true, 'direction' => 0, 'file_count' => 1,
    ]));

    $line = $read()[0];
    expect($line)->toMatchArray(['source' => 'file_transfer', 'device' => '1', 'file_count' => 1])
        ->and($line['from_name'])->toBe(str_repeat("\x01", 1000));
});

it('never fails the request when the stream cannot be written', function () {
    config([
        'cortendesk.audit_log' => true,
        'logging.channels.cortendesk_audit.handler_with.stream' => '/nonexistent-dir/audit.log',
    ]);
    Log::forgetChannel(AuditLogStream::CHANNEL);

    ConsoleAudit::system('device.approve', 'Still saved');

    expect(ConsoleAudit::sole()->summary)->toBe('Still saved');
});

it('writes to the scheduler\'s descriptor in the console, where stderr goes nowhere', function () {
    // Open a file and find its descriptor number, as the image's scheduler hands one to every job.
    $file = tempnam(sys_get_temp_dir(), 'audit');
    $handle = fopen($file, 'a');
    $inode = fstat($handle)['ino'];
    $fd = collect(scandir('/dev/fd'))->first(fn ($n) => ctype_digit($n) && (@stat('/dev/fd/'.$n)['ino'] ?? null) === $inode);

    config(['cortendesk.audit_log' => true, 'logging.channels.cortendesk_audit.handler_with.stream' => 'php://stderr']);
    Log::forgetChannel(AuditLogStream::CHANNEL);
    putenv('CORTENDESK_AUDIT_LOG_FD='.$fd);

    try {
        ConsoleAudit::system('backup.create', 'Scheduled backup: done');
        fflush($handle);

        $line = json_decode(trim(file_get_contents($file)), true);
        expect($line)->toMatchArray(['event' => 'backup.create', 'summary' => 'Scheduled backup: done'])
            ->and(config('logging.channels.cortendesk_audit.handler_with.stream'))->toBe('php://fd/'.$fd);
    } finally {
        putenv('CORTENDESK_AUDIT_LOG_FD');
        fclose($handle);
    }
});

it('keeps stderr when the scheduler\'s descriptor is not open, and a file stream as it is', function () {
    putenv('CORTENDESK_AUDIT_LOG_FD=987');

    try {
        config(['logging.channels.cortendesk_audit.handler_with.stream' => 'php://stderr']);
        (new ReflectionMethod(AuditLogStream::class, 'useSchedulerDescriptor'))->invoke(null);
        expect(config('logging.channels.cortendesk_audit.handler_with.stream'))->toBe('php://stderr');

        $read = streamToFile();
        ConsoleAudit::system('device.approve', 'to the file');
        expect(array_column($read(), 'summary'))->toBe(['to the file']);
    } finally {
        putenv('CORTENDESK_AUDIT_LOG_FD');
    }
});
