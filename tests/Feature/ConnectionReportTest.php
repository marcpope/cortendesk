<?php

use App\Livewire\ConnectionReport;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\SessionTime;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Connection report (PR #173, by @Liaders): time between who connected and
 * what they reached, clipped to the period, with overlaps merged per operator.
 */

beforeEach(function () {
    $this->travelTo('2026-11-15 12:00:00');

    $this->admin = User::factory()->admin()->create();
    $this->operator = User::factory()->create(['username' => 'operator']);
    $this->customer = DeviceGroup::create(['name' => 'Customer']);
    $this->other = DeviceGroup::create(['name' => 'Other']);
    $this->team = UserGroup::create(['name' => 'Team']);
    $this->operator->groups()->attach($this->team);

    Device::withoutEvents(function () {
        foreach ([
            ['source1', null, $this->operator->id],
            ['source2', null, $this->operator->id],
            ['target', $this->customer->id, null],
            ['hidden', $this->other->id, null],
        ] as [$id, $group, $owner]) {
            Device::create(['rustdesk_id' => $id, 'uuid' => $id, 'device_group_id' => $group, 'user_id' => $owner, 'status' => 'active']);
        }
    });

    foreach ([
        ['source1', 'target', '2026-10-31 23:30:00', '2026-11-01 01:00:00'], // 1h in November
        ['source2', 'target', '2026-11-01 00:30:00', '2026-11-01 02:00:00'], // overlaps the first
        ['source1', 'target', '2026-11-30 23:00:00', '2026-12-01 01:00:00'], // 1h in November
        ['source1', 'target', '2026-11-02 00:00:00', null],                  // still open
        ['source1', 'target', '2026-10-31 23:00:00', '2026-11-01 00:00:00'], // ends as November starts
        ['source1', 'hidden', '2026-11-01 00:00:00', '2026-11-01 03:00:00'], // another group
    ] as [$from, $to, $start, $end]) {
        reportRow($from, $to, $start, $end);
    }
});

function reportRow(string $from, string $to, string $start, ?string $end): AuditConnection
{
    $row = new AuditConnection(['from_peer' => $from, 'rustdesk_id' => $to, 'closed_at' => $end]);
    $row->created_at = $start;
    $row->save();

    return $row;
}

function connectionReport(User $user, array $set = []): Testable
{
    $test = Livewire::actingAs($user)->test(ConnectionReport::class)
        ->set('dateFrom', '2026-11-01')->set('dateTo', '2026-11-30');
    foreach ($set as $key => $value) {
        $test->set($key, $value);
    }

    return $test;
}

it('merges overlapping intervals per operator', function () {
    expect(SessionTime::withoutOverlaps(['a' => [[10, 20], [0, 15], [2, 5], [20, 30]], 'b' => [[0, 5]]]))->toBe(35)
        ->and(SessionTime::format(360001))->toBe('100:00:01');
});

it('clips sessions to the period, counts open ones apart and merges overlaps', function () {
    $r = connectionReport($this->admin, ['target' => ['device_group_id' => (string) $this->customer->id]]);

    expect($r->viewData('count'))->toBe(4)
        ->and($r->viewData('active'))->toBe(1)
        ->and($r->viewData('total'))->toBe(12600)
        ->and($r->viewData('unique'))->toBe(10800)
        ->and($r->viewData('breakdown'))->toHaveCount(1)
        ->and($r->viewData('operators'))->toHaveCount(1);
});

it('filters both sides by owner, user group and device', function () {
    $target = ['device_group_id' => (string) $this->customer->id];

    expect(connectionReport($this->admin, ['target' => $target, 'source' => ['user_group_id' => (string) $this->team->id]])->viewData('count'))->toBe(4)
        ->and(connectionReport($this->admin, ['target' => $target, 'source' => ['rustdesk_id' => 'source2']])->viewData('count'))->toBe(1)
        ->and(connectionReport($this->admin, ['target' => $target, 'source' => ['user_id' => (string) $this->admin->id]])->viewData('count'))->toBe(0)
        ->and(connectionReport($this->admin, ['target' => ['user_group_id' => (string) $this->team->id]])->viewData('count'))->toBe(0);
});

it('leaves out attempts that never logged in, like the Overview', function () {
    reportRow('', 'target', '2026-11-03 10:00:00', '2026-11-03 10:01:00');
    tap(reportRow('x', 'target', '2026-11-03 11:00:00', '2026-11-03 11:01:00'), fn ($r) => $r->forceFill(['from_peer' => null])->save());

    $r = connectionReport($this->admin, ['target' => ['device_group_id' => (string) $this->customer->id]]);

    expect($r->viewData('count'))->toBe(4)->and($r->viewData('total'))->toBe(12600);
});

it('rejects a period that ends before it starts', function () {
    connectionReport($this->admin)->set('dateTo', '2026-10-01')->assertHasErrors('dateTo');
});

it('exports the sessions as CSV', function () {
    $r = connectionReport($this->admin)->call('export')->assertFileDownloaded('connection-report.csv');
    $csv = base64_decode($r->effects['download']['content']);

    // Header plus every session that overlaps November, the open one included.
    expect(substr_count($csv, "\n"))->toBe(6)->and($csv)->toContain('Seconds in period');
});

it('exports an Excel workbook with client text kept literal', function () {
    AuditConnection::query()->orderBy('id')->first()->update(['note' => '<f>1+1</f>', 'from_name' => '=1+1']);

    $r = connectionReport($this->admin)->call('exportXlsx')->assertFileDownloaded('connection-report.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'report-');
    file_put_contents($path, base64_decode($r->effects['download']['content']));
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $book = $zip->getFromName('xl/workbook.xml');
    foreach (['Summary', 'Target groups', 'Operators', 'Sessions'] as $sheet) {
        expect($book)->toContain('name="'.$sheet.'"');
    }
    $sessions = $zip->getFromName('xl/worksheets/sheet4.xml');
    expect(substr_count($sessions, '<row '))->toBe(6)
        ->and($sessions)->not->toContain('<f>')
        ->and($zip->getFromName('xl/sharedStrings.xml'))->toContain('&lt;f&gt;1+1&lt;/f&gt;')->toContain('=1+1')
        ->and($zip->getFromName('xl/styles.xml'))->toContain('[h]:mm:ss');

    $zip->close();
    unlink($path);
});

it('shows a delegated reader only the targets they can see', function () {
    $role = Role::create(['name' => 'Auditor', 'permissions' => Role::normalizePermissions(['audit' => 'r'])]);
    $this->operator->update(['role_id' => $role->id]);
    Device::withoutEvents(fn () => Device::where('rustdesk_id', 'target')->update(['user_id' => $this->operator->id]));
    $reader = $this->operator->fresh();

    expect(connectionReport($reader)->viewData('count'))->toBe(4)
        ->and(connectionReport($reader, ['target' => ['rustdesk_id' => 'hidden']])->viewData('count'))->toBe(0);
});

it('checks the audit permission again on export', function (string $action) {
    $role = Role::create(['name' => 'Auditor', 'permissions' => Role::normalizePermissions(['audit' => 'r'])]);
    $this->operator->update(['role_id' => $role->id]);
    $reader = $this->operator->fresh();
    $r = connectionReport($reader);

    $role->update(['permissions' => Role::normalizePermissions([])]);
    $reader->refresh(); // what the next request would load

    $r->call($action)->assertForbidden();
})->with(['export', 'exportXlsx']);

it('lists the page under Logs', function () {
    $this->actingAs($this->admin)->get('/logs/connection-report')->assertOk()
        ->assertSee(__('report.title'))->assertSee(route('logs.connection-report'));
});
