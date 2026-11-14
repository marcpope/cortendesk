<?php

use App\Livewire\ConnectionLog;
use App\Models\AuditConnection;
use App\Models\User;
use App\Support\Csv;

/*
 * Client-supplied text (device names, paths, session notes) must not run as a
 * spreadsheet formula when an export is opened in Excel or Sheets.
 */

it('neutralises cells that a spreadsheet would run as a formula', function () {
    expect(Csv::row(['=SUM(A1)', '+1', '-2+3', '@cmd', "\tx", "\rx", 'plain', '', null, 42, 'a=b']))
        ->toBe(["'=SUM(A1)", "'+1", "'-2+3", "'@cmd", "'\tx", "'\rx", 'plain', '', null, 42, 'a=b']);
});

it('escapes a formula in a session note in the connection log export', function () {
    AuditConnection::create([
        'action' => 'close',
        'rustdesk_id' => '123456789',
        'from_peer' => '111222333',
        'from_name' => '=HYPERLINK("http://evil.example","x")',
        'conn_type' => 0,
        'note' => '@SUM(1+1)',
    ]);

    $this->actingAs(User::factory()->admin()->create());
    $component = app(ConnectionLog::class);

    ob_start();
    $component->export()->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('"\'=HYPERLINK(""http://evil.example"",""x"")"')
        ->and($csv)->toContain("'@SUM(1+1)");
});
