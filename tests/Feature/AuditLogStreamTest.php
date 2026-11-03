<?php

use App\Models\AlarmLog;
use App\Models\ApiToken;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\LoginLog;
use App\Models\User;
use App\Support\AuditLogStream;
use Illuminate\Support\Facades\Log;

/** Point the audit channel at a temp file and return a reader for its JSON lines. */
function streamToFile(bool $enabled = true): Closure
{
    $file = tempnam(sys_get_temp_dir(), 'audit');
    config([
        'cortendesk.audit_log_stdout' => $enabled,
        'logging.channels.cortendesk_audit.handler_with.stream' => $file,
    ]);
    Log::forgetChannel(AuditLogStream::CHANNEL);

    return function () use ($file): array {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

        foreach ($lines as $line) {
            expect($line)->toStartWith('{"cortendesk_audit":1,');
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

it('streams console actions', function () {
    $read = streamToFile();

    ConsoleAudit::system('device.approve', 'Approved device 123456789', 'device', '123456789');

    $line = $read()[0];
    expect($line)->toMatchArray([
        'source' => 'console',
        'event' => 'device.approve',
        'user' => 'system',
        'target_type' => 'device',
        'target_id' => '123456789',
        'summary' => 'Approved device 123456789',
    ])->and($line['id'])->toBe(ConsoleAudit::sole()->id)
        ->and($line['ts'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/');
});

it('streams sign-ins, failed ones too', function () {
    $read = streamToFile();

    LoginLog::create(['username' => 'alice', 'client' => 'console', 'ip' => '192.0.2.10', 'successful' => true]);
    LoginLog::create(['username' => 'mallory', 'client' => 'client', 'device_id' => '123', 'ip' => '192.0.2.66', 'successful' => false, 'note' => 'bad password']);

    [$ok, $bad] = $read();
    expect($ok)->toMatchArray(['source' => 'login', 'event' => 'login.success', 'user' => 'alice', 'ip' => '192.0.2.10'])
        ->and($bad)->toMatchArray(['event' => 'login.failure', 'user' => 'mallory', 'device' => '123', 'note' => 'bad password']);
});

it('streams a session from open to close, without the device uuid or the note', function () {
    $read = streamToFile();

    $conn = AuditConnection::create([
        'action' => 'new', 'conn_id' => 7, 'rustdesk_id' => '123456789', 'ip' => '192.0.2.20',
        'session_id' => '42', 'uuid' => 'ZGV2aWNlLXV1aWQ=',
    ]);
    $conn->update(['action' => 'authorized', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'conn_type' => 0]);
    $conn->update(['note' => 'private remark']);
    $conn->update(['action' => 'close', 'closed_at' => now()]);

    $lines = $read();
    expect(array_column($lines, 'event'))->toBe(['connection.new', 'connection.authorized', 'connection.close'])
        ->and($lines[1])->toMatchArray(['device' => '123456789', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'conn_id' => 7])
        ->and(json_encode($lines))->not->toContain('ZGV2aWNlLXV1aWQ=')
        ->and(json_encode($lines))->not->toContain('private remark');
});

it('streams sessions the console closes in bulk', function () {
    $conn = AuditConnection::create(['action' => 'authorized', 'conn_id' => 9, 'rustdesk_id' => '555', 'from_peer' => 'web-1']);
    $conn->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    $read = streamToFile();

    expect(AuditConnection::reconcileFor('555', []))->toBe(1);

    expect($read())->toHaveCount(1)
        ->and($read()[0])->toMatchArray(['event' => 'connection.close', 'device' => '555', 'reason' => 'reconciled']);
});

it('streams file transfers and alarms', function () {
    $read = streamToFile();

    AuditFileTransfer::create([
        'rustdesk_id' => '123', 'from_peer' => 'web-1', 'from_name' => 'Alice', 'path' => 'C:\\Users\\Public\\report.pdf',
        'info' => '{}', 'is_file' => true, 'direction' => 0, 'file_count' => 1, 'ip' => '192.0.2.30', 'uuid' => 'dXVpZA==',
    ]);
    AlarmLog::console(AlarmLog::TYP_BRUTE_FORCE, ['username' => 'admin']);

    [$file, $alarm] = $read();
    expect($file)->toMatchArray(['source' => 'file_transfer', 'device' => '123', 'path' => 'C:\\Users\\Public\\report.pdf', 'file_count' => 1])
        ->and($file)->not->toHaveKey('uuid')
        ->and($alarm)->toMatchArray(['source' => 'alarm', 'event' => 'alarm.100', 'device' => 'console', 'alarm' => 'Console brute force']);
});

it('streams admin-API token use and refusals, never the token', function () {
    $admin = streamAdmin();
    [$token, $plain] = ApiToken::issue($admin, 'Inventory sync', ['device' => 'r']);
    $read = streamToFile();

    $this->getJson('/api/v1/devices', ['Authorization' => 'Bearer '.$plain])->assertOk();
    $this->getJson('/api/v1/users', ['Authorization' => 'Bearer '.$plain])->assertForbidden();

    [$use, $denied] = $read();
    expect($use)->toMatchArray([
        'source' => 'api_token', 'event' => 'api_token.use', 'token_id' => $token->id, 'token_name' => 'Inventory sync',
        'user' => 'auditor', 'resource' => 'device', 'level' => 'r', 'method' => 'GET', 'path' => '/api/v1/devices',
    ])->and($denied)->toMatchArray(['event' => 'api_token.denied', 'resource' => 'user']);

    $all = json_encode($read());
    expect($all)->not->toContain($plain)
        ->and($all)->not->toContain($token->token_prefix)
        ->and($all)->not->toContain(hash('sha256', $plain));
});

it('cuts long free text', function () {
    $read = streamToFile();

    ConsoleAudit::system('settings.update', str_repeat('x', 240));
    AlarmLog::create(['rustdesk_id' => '1', 'typ' => 9, 'info' => str_repeat('y', 5000)]);

    expect(strlen($read()[1]['info']))->toBeLessThanOrEqual(1003);
});
