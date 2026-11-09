<?php

use App\Livewire\ConnectionReport;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Support\SessionTime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Run: php tests/connection-report.php (uses only an in-memory SQLite database).
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, (string) $e);
    exit(1);
});
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
Artisan::call('migrate', ['--force' => true]);

function check($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label.': '.var_export($actual, true).' != '.var_export($expected, true));
    }
    echo 'PASS '.$label.PHP_EOL;
}

check(SessionTime::withoutOverlaps(['a' => [[10, 20], [0, 15], [2, 5], [20, 30]], 'b' => [[0, 5]]]), 35, 'nested, touching, unsorted intervals and independent operators');
check(SessionTime::format(360001), '100:00:01', 'durations longer than a day');
$english = require dirname(__DIR__).'/lang/en/report.php';
foreach (array_keys(User::LOCALES) as $locale) {
    $messages = require dirname(__DIR__).'/lang/'.$locale.'/report.php';
    check(array_keys($messages), array_keys($english), 'translation keys: '.$locale);
    check(str_contains($messages['help'], ':timezone'), true, 'timezone placeholder: '.$locale);
    check(count(array_filter($messages, fn ($message) => ! is_string($message) || trim($message) === '')), 0, 'nonempty translations: '.$locale);
}

$admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
$operator = User::create(['username' => 'operator', 'password' => 'test']);
$group = DeviceGroup::create(['name' => 'Customer']);
$other = DeviceGroup::create(['name' => 'Other']);
$team = UserGroup::create(['name' => 'Team']);
$operator->groups()->attach($team);
Device::withoutEvents(function () use ($operator, $group, $other) {
    foreach ([['source1', null, $operator->id], ['source2', null, $operator->id], ['target', $group->id, null], ['hidden', $other->id, null]] as [$id, $gid, $uid]) {
        Device::create(['rustdesk_id' => $id, 'uuid' => $id, 'device_group_id' => $gid, 'user_id' => $uid, 'status' => 'active']);
    }
});
foreach ([
    ['source1', 'target', '2026-10-31 23:30:00', '2026-11-01 01:00:00'],
    ['source2', 'target', '2026-11-01 00:30:00', '2026-11-01 02:00:00'],
    ['source1', 'target', '2026-11-30 23:00:00', '2026-12-01 01:00:00'],
    ['source1', 'target', '2026-11-02 00:00:00', null],
    ['source1', 'target', '2026-10-31 23:00:00', '2026-11-01 00:00:00'],
    ['source1', 'hidden', '2026-11-01 00:00:00', '2026-11-01 03:00:00'],
] as [$from, $to, $start, $end]) {
    $row = new AuditConnection(['from_peer' => $from, 'rustdesk_id' => $to, 'closed_at' => $end]);
    $row->created_at = $start;
    $row->save();
}
auth()->login($admin);
$report = new ConnectionReport;
$report->mount();
$report->dateFrom = '2026-11-01';
$report->dateTo = '2026-11-30';
$report->target = ['device_group_id' => (string) $group->id];
$data = $report->render()->getData();
check($data['count'], 4, 'overlap query excludes sessions ending at period start');
check($data['active'], 1, 'unfinished sessions separately counted');
check($data['total'], 12600, 'both month boundaries clipped and unfinished excluded');
check($data['unique'], 10800, 'overlaps merged across devices of same owner');
check(count($data['breakdown']), 1, 'customer breakdown');
check(count($data['operators']), 1, 'operator breakdown');
$report->source = ['user_group_id' => (string) $team->id];
check($report->render()->getData()['count'], 4, 'source user group filter');
$report->source = ['rustdesk_id' => 'source2'];
check($report->render()->getData()['count'], 1, 'source device filter');
$report->source = ['user_id' => (string) $admin->id];
check($report->render()->getData()['count'], 0, 'source owner filter');
$report->source = [];
$report->target = ['user_group_id' => (string) $team->id];
check($report->render()->getData()['count'], 0, 'target user group filter');
$report->target = [];
$csv = $report->export();
ob_start();
$csv->sendContent();
$body = ob_get_clean();
check(substr_count($body, "\n"), 6, 'CSV exports every matching session including unfinished');
AuditConnection::query()->first()->update(['note' => '<f>1+1</f>', 'from_name' => '=1+1']);
$xlsx = $report->exportXlsx();
check($xlsx->headers->get('Content-Type'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'XLSX MIME type');
ob_start();
$xlsx->sendContent();
$bytes = ob_get_clean();
$path = tempnam(sys_get_temp_dir(), 'report-test-');
try {
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    check($zip->open($path), true, 'XLSX is a valid ZIP archive');
    $book = $zip->getFromName('xl/workbook.xml');
    foreach (['Summary', 'Target groups', 'Operators', 'Sessions'] as $sheet) {
        check(str_contains($book, 'name="'.$sheet.'"'), true, 'XLSX sheet: '.$sheet);
    }
    $details = $zip->getFromName('xl/worksheets/sheet4.xml');
    check(substr_count($details, '<row '), 6, 'XLSX includes all filtered sessions');
    check(str_contains($details, '<f>'), false, 'client data is never evaluated as a formula');
    $strings = $zip->getFromName('xl/sharedStrings.xml');
    check(str_contains($strings, '&lt;f&gt;1+1&lt;/f&gt;'), true, 'XLSX preserves literal markup in notes');
    check(str_contains($strings, '=1+1'), true, 'XLSX preserves formula-like text');
    check(str_contains($zip->getFromName('xl/styles.xml'), '[h]:mm:ss'), true, 'XLSX uses elapsed time format');
    $zip->close();
} finally {
    unlink($path);
}

check(str_contains($report->render()->with(['errors' => new ViewErrorBag, 'dateFrom' => $report->dateFrom, 'dateTo' => $report->dateTo, 'source' => [], 'target' => []])->render(), 'wire:key="report-'), true, 'Blade view renders');
$report->dateTo = '2026-10-01';
try {
    $report->render();
    throw new RuntimeException('reversed dates accepted');
} catch (ValidationException $e) {
    echo "PASS reversed dates rejected\n";
}
$report->dateTo = '2026-11-30';
// A delegated audit reader still only sees their own target devices.
$role = Role::create(['name' => 'Auditor', 'permissions' => ['audit' => 'r']]);
$operator->update(['role_id' => $role->id]);
Device::withoutEvents(fn () => Device::where('rustdesk_id', 'target')->update(['user_id' => $operator->id]));
auth()->login($operator->fresh());
$report->source = [];
$report->target = [];
check($report->render()->getData()['count'], 4, 'scoped reader cannot see other target');
$report->target = ['rustdesk_id' => 'hidden'];
check($report->render()->getData()['count'], 0, 'forged filter cannot bypass visibility');
$role->update(['permissions' => []]);
auth()->login($operator->fresh());
try {
    $report->export();
    throw new RuntimeException('unauthorized export accepted');
} catch (HttpException $e) {
    check($e->getStatusCode(), 403, 'export rechecks revoked audit permission');
}

try {
    $report->exportXlsx();
    throw new RuntimeException('unauthorized XLSX export accepted');
} catch (HttpException $e) {
    check($e->getStatusCode(), 403, 'XLSX export rechecks revoked audit permission');
}
