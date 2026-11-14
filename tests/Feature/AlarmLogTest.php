<?php

use App\Livewire\AlarmLogList;
use App\Models\AlarmLog;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Livewire\Livewire;

function alarmLogAdmin(): User
{
    return User::create([
        'username' => 'alarm-admin-'.uniqid(),
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
}

function alarmLogUser(): User
{
    return User::create([
        'username' => 'alarm-user-'.uniqid(),
        'password' => 'secret-password',
        'is_admin' => false,
    ]);
}

function makeAlarm(string $rustdeskId, int $typ = 0, array $extra = []): AlarmLog
{
    return AlarmLog::create(array_merge([
        'rustdesk_id' => $rustdeskId,
        'uuid' => 'uuid-'.uniqid(),
        'typ' => $typ,
        'info' => '{"ip":"203.0.113.9"}',
    ], $extra));
}

it('renders the alarm log page with rows', function () {
    makeAlarm('123456789', 1);

    $this->actingAs(alarmLogAdmin())
        ->get(route('logs.alarms'))
        ->assertOk()
        ->assertSeeLivewire(AlarmLogList::class);

    Livewire::actingAs(alarmLogAdmin())
        ->test(AlarmLogList::class)
        ->assertSee('123456789')
        ->assertSee('Many failed attempts');
});

it('filters the alarm log by type', function () {
    makeAlarm('123456789', 1);
    makeAlarm('987654321', 2);

    Livewire::actingAs(alarmLogAdmin())
        ->test(AlarmLogList::class)
        ->set('type', '1')
        ->assertSee('123456789')
        ->assertDontSee('987654321');
});

it('filters the alarm log by device id', function () {
    makeAlarm('123456789');
    makeAlarm('987654321');

    Livewire::actingAs(alarmLogAdmin())
        ->test(AlarmLogList::class)
        ->set('search', '123456789')
        ->assertSee('123456789')
        ->assertDontSee('987654321');
});

it('scopes alarms to visible devices for non-admins', function () {
    $u = alarmLogUser();
    $granted = DeviceGroup::create(['name' => 'Granted']);
    $u->deviceGroups()->attach($granted);

    Device::create(['rustdesk_id' => '111111111', 'uuid' => 'a1', 'device_group_id' => $granted->id]);
    Device::create(['rustdesk_id' => '222222222', 'uuid' => 'a2']); // not granted

    makeAlarm('111111111');          // visible device
    makeAlarm('222222222');          // hidden device
    makeAlarm('333333333');          // unknown device: admins only

    Livewire::actingAs($u)
        ->test(AlarmLogList::class)
        ->assertSee('111111111')
        ->assertDontSee('222222222')
        ->assertDontSee('333333333');

    Livewire::actingAs(alarmLogAdmin())
        ->test(AlarmLogList::class)
        ->assertSee('111111111')
        ->assertSee('222222222')
        ->assertSee('333333333');
});

it('exports the alarm log as csv with the expected headers', function () {
    makeAlarm('123456789', 9, ['conn_id' => 42]);

    Livewire::actingAs(alarmLogAdmin())
        ->test(AlarmLogList::class)
        ->call('export')
        ->assertFileDownloaded('alarm-log.csv');

    // Verify header row + data by rendering the streamed response directly.
    $this->actingAs(alarmLogAdmin());
    $response = (new AlarmLogList)->export();
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('When,Device,Type,Info,"Conn ID"')
        ->toContain('123456789')
        ->toContain('Session scope violation')
        ->toContain('42');
});

it('maps typ values to labels and severities', function () {
    expect((new AlarmLog(['typ' => 0]))->typeLabel())->toBe('IP whitelist block')
        ->and((new AlarmLog(['typ' => 0]))->typeSeverity())->toBe('danger')
        ->and((new AlarmLog(['typ' => 1]))->typeLabel())->toBe('Many failed attempts (>30)')
        ->and((new AlarmLog(['typ' => 2]))->typeLabel())->toBe('Rapid access attempts')
        ->and((new AlarmLog(['typ' => 2]))->typeSeverity())->toBe('warning')
        ->and((new AlarmLog(['typ' => 6]))->typeLabel())->toBe('IPv6 prefix attempts exceeded')
        ->and((new AlarmLog(['typ' => 10]))->typeLabel())->toBe('ID whitelist block')
        ->and((new AlarmLog(['typ' => 10]))->typeSeverity())->toBe('danger')
        ->and((new AlarmLog(['typ' => 7]))->typeLabel())->toBe('Terminal login backoff')
        ->and((new AlarmLog(['typ' => 8]))->typeLabel())->toBe('Terminal login concurrency')
        ->and((new AlarmLog(['typ' => 9]))->typeLabel())->toBe('Session scope violation')
        ->and((new AlarmLog(['typ' => 9]))->typeSeverity())->toBe('danger')
        ->and((new AlarmLog(['typ' => 42]))->typeLabel())->toBe('Type 42')
        ->and((new AlarmLog(['typ' => 42]))->typeSeverity())->toBe('secondary');
});

it('requires auth for the alarms page but allows non-admins', function () {
    $this->get('/logs/alarms')->assertRedirect('/login');

    $this->actingAs(alarmLogUser())
        ->get('/logs/alarms')
        ->assertOk();
});
