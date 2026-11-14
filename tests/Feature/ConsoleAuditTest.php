<?php

use App\Livewire\ConsoleAuditList;
use App\Livewire\DeviceList;
use App\Livewire\GroupList;
use App\Livewire\SettingsPage;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

function auditAdmin(): User
{
    return User::create([
        'username' => 'audit-admin',
        'password' => 'secret-password',
        'is_admin' => true,
    ]);
}

/* -------------------------------------------------------------------------
 | ConsoleAudit::record()
 * ---------------------------------------------------------------------- */

it('records an audit row attributed to the acting user with ip', function () {
    $admin = auditAdmin();

    $this->actingAs($admin);
    ConsoleAudit::record('user.create', 'Created user bob', 'user', 'bob');

    $row = ConsoleAudit::first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($admin->id)
        ->and($row->username)->toBe('audit-admin')
        ->and($row->action)->toBe('user.create')
        ->and($row->target_type)->toBe('user')
        ->and($row->target_id)->toBe('bob')
        ->and($row->ip)->not->toBeNull();
});

it('is a no-op when unauthenticated', function () {
    ConsoleAudit::record('user.create', 'Created user bob', 'user', 'bob');

    expect(ConsoleAudit::count())->toBe(0);
});

/* -------------------------------------------------------------------------
 | Instrumented actions write audit rows
 * ---------------------------------------------------------------------- */

it('records group.create when a group is created', function () {
    Livewire::actingAs(auditAdmin())
        ->test(GroupList::class)
        ->call('create', 'devices')
        ->set('name', 'Sales')
        ->call('save')
        ->assertHasNoErrors();

    expect(ConsoleAudit::where('action', 'group.create')->exists())->toBeTrue();
});

it('records device.delete when a device is deleted', function () {
    $device = Device::create([
        'rustdesk_id' => '123123123',
        'uuid' => 'uuid-del',
        'hostname' => 'host-del',
        'os' => 'Windows 10',
        'username' => 'user',
        'version' => '1.4.2',
    ]);

    Livewire::actingAs(auditAdmin())
        ->test(DeviceList::class)
        ->call('deleteDevice', $device->id);

    $row = ConsoleAudit::where('action', 'device.delete')->first();
    expect($row)->not->toBeNull()
        ->and($row->target_id)->toBe('123123123');
});

it('records settings.update when settings are saved', function () {
    Livewire::actingAs(auditAdmin())
        ->test(SettingsPage::class)
        ->set('onlineWindow', 60)
        ->set('logRetentionDays', 365)
        ->call('save')
        ->assertHasNoErrors();

    expect(ConsoleAudit::where('action', 'settings.update')->exists())->toBeTrue();
});

/* -------------------------------------------------------------------------
 | /logs/console route gating
 * ---------------------------------------------------------------------- */

it('redirects guests from the console audit page', function () {
    $this->get(route('logs.console'))->assertRedirect(route('login'));
});

it('bounces non-admins from the console audit page', function () {
    $user = User::create([
        'username' => 'plain-user',
        'password' => 'secret-password',
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('logs.console'))
        ->assertRedirect(route('overview'));
});

it('renders the console audit page for an admin with rows', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('group.create', 'Created device group Sales', 'group', 'Sales');

    $this->get(route('logs.console'))
        ->assertOk()
        ->assertSeeLivewire(ConsoleAuditList::class)
        ->assertSee('Sales');
});

/* -------------------------------------------------------------------------
 | Filters and export
 * ---------------------------------------------------------------------- */

it('filters by action and operator search', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('group.create', 'Created device group Sales', 'group', 'Sales');
    ConsoleAudit::record('device.delete', 'Deleted device 999888777', 'device', '999888777');

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->set('action', 'group.create')
        ->assertSee('Sales')
        ->assertDontSee('999888777');

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->set('search', '999888777')
        ->assertSee('999888777')
        ->assertDontSee('Sales');
});

it('matches a known action exactly', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('user.invite', 'Invited alice', 'user', 'alice');
    ConsoleAudit::record('user.invite-revoke', 'Revoked the invitation for bob', 'user', 'bob');

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->set('action', 'user.invite')
        ->assertSee('Invited alice')
        ->assertDontSee('Revoked the invitation for bob');
});

it('offers backup actions in the filter (issue #108)', function () {
    $admin = auditAdmin();

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->assertSeeHtml('<optgroup label="Backups">')
        ->assertSeeHtml('value="backup.create"')
        ->assertSeeHtml('value="backup.restore"');
});

it('lists every action the code records in the filter', function () {
    $recorded = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        // The first argument never contains a comma: a literal, or a ternary
        // or concatenation of literals.
        preg_match_all('/ConsoleAudit::(?:record|system)\(\s*([^,]+),/', $source, $calls);
        foreach ($calls[1] as $argument) {
            preg_match_all("/'([^']*)'/", $argument, $literals);
            $prefix = '';
            foreach ($literals[1] as $literal) {
                if (str_ends_with($literal, '.')) {
                    $prefix = $literal;
                } elseif ($prefix !== '' || str_contains($literal, '.')) {
                    $recorded[] = $prefix.$literal;
                }
            }
        }
    }

    expect($recorded)->not->toBeEmpty()
        ->and(array_values(array_diff(array_unique($recorded), array_keys(ConsoleAuditList::ACTIONS))))->toBe([])
        ->and(array_diff(array_unique(array_map(fn ($a) => strstr($a, '.', true), array_keys(ConsoleAuditList::ACTIONS))), array_keys(ConsoleAuditList::AREAS)))->toBe([]);
});

it('exports the console audit as csv', function () {
    $admin = auditAdmin();
    $this->actingAs($admin);
    ConsoleAudit::record('settings.update', 'Updated server settings', 'settings', null);

    Livewire::actingAs($admin)
        ->test(ConsoleAuditList::class)
        ->call('export')
        ->assertFileDownloaded('console-audit.csv');
});
