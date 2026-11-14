<?php

use App\Livewire\ConnectionLog;
use App\Livewire\FileTransferLog;
use App\Livewire\LoginLogList;
use App\Models\AuditConnection;
use App\Models\User;
use Livewire\Livewire;

function logScreensUser(): User
{
    return User::create([
        'username' => 'log-tester',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
}

it('renders the connection log page', function () {
    $this->actingAs(logScreensUser())
        ->get(route('logs.connections'))
        ->assertOk()
        ->assertSeeLivewire(ConnectionLog::class);
});

it('renders the file transfer log page', function () {
    $this->actingAs(logScreensUser())
        ->get(route('logs.file-transfers'))
        ->assertOk()
        ->assertSeeLivewire(FileTransferLog::class);
});

it('renders the login log page', function () {
    $this->actingAs(logScreensUser())
        ->get(route('logs.logins'))
        ->assertOk()
        ->assertSeeLivewire(LoginLogList::class);
});

it('filters the connection log by rustdesk id', function () {
    AuditConnection::create([
        'action' => 'close',
        'rustdesk_id' => '123456789',
        'from_peer' => '111222333',
        'from_name' => 'ops-laptop',
        'ip' => '203.0.113.10',
        'conn_type' => 0,
    ]);
    AuditConnection::create([
        'action' => 'close',
        'rustdesk_id' => '987654321',
        'from_peer' => '444555666',
        'from_name' => 'helpdesk-01',
        'ip' => '198.51.100.7',
        'conn_type' => 0,
    ]);

    Livewire::actingAs(logScreensUser())
        ->test(ConnectionLog::class)
        ->set('search', '123456789')
        ->assertSee('123456789')
        ->assertDontSee('987654321');
});

it('exports the connection log as csv', function () {
    AuditConnection::create([
        'action' => 'close',
        'rustdesk_id' => '123456789',
        'from_peer' => '111222333',
        'from_name' => 'ops-laptop',
        'ip' => '203.0.113.10',
        'conn_type' => 1,
    ]);

    Livewire::actingAs(logScreensUser())
        ->test(ConnectionLog::class)
        ->call('export')
        ->assertFileDownloaded('connection-log.csv');
});

it('labels every documented session type and keeps unknown ones readable', function () {
    expect(AuditConnection::typeLabel(0))->toBe('Remote Control')
        ->and(AuditConnection::typeLabel(3))->toBe('View Camera')
        ->and(AuditConnection::typeLabel(4))->toBe('Terminal')
        ->and(AuditConnection::typeLabel(9))->toBe('Type 9');

    foreach ([3 => 'View Camera', 4 => 'Terminal', 9 => 'Type 9'] as $type => $label) {
        AuditConnection::create([
            'action' => 'close',
            'rustdesk_id' => '1000'.$type,
            'from_peer' => '111222333',
            'from_name' => 'ops-laptop',
            'ip' => '203.0.113.10',
            'conn_type' => $type,
        ]);
    }

    Livewire::actingAs(logScreensUser())
        ->test(ConnectionLog::class)
        ->assertSee('View Camera')
        ->assertSee('Terminal')
        ->assertSee('Type 9');
});
