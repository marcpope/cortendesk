<?php

use App\Livewire\ActiveSessions;
use App\Livewire\LiveStats;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function dashboardUserWithAuditLevel(string $level): User
{
    $role = Role::create([
        'name' => 'Dashboard '.$level.' '.fake()->unique()->word(),
        'permissions' => Role::normalizePermissions([
            'device' => 'r',
            'audit' => $level,
        ]),
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

function dashboardAuditFixture(User $user): void
{
    Device::create([
        'rustdesk_id' => '900001',
        'uuid' => 'dashboard-audit-device',
        'status' => Device::STATUS_ACTIVE,
        'user_id' => $user->id,
        'last_online_at' => now(),
    ]);

    AuditConnection::create([
        'action' => 'new',
        'conn_id' => 1234,
        'rustdesk_id' => '900001',
        'from_peer' => 'sensitive-controller-id',
        'from_name' => 'Sensitive Operator',
        'conn_type' => 0,
    ]);

    AlarmLog::create([
        'rustdesk_id' => '900001',
        'typ' => 9,
        'info' => json_encode(['detail' => 'sensitive alarm detail']),
    ]);
}

test('the overview does not disclose audit activity to a role without log access', function () {
    $user = dashboardUserWithAuditLevel('none');
    dashboardAuditFixture($user);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)
        ->get(route('overview'))
        ->assertOk()
        ->assertSee('Devices')
        ->assertDontSee('Sensitive Operator')
        ->assertDontSee('Active Sessions')
        ->assertDontSee('Connections started today')
        ->assertDontSee('Alarms (24h)');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'audit_connections')
        || str_contains($sql, 'alarm_logs')))->toBeEmpty();
});

test('live dashboard stats omit audit tiles for a role without log access', function () {
    $user = dashboardUserWithAuditLevel('none');
    dashboardAuditFixture($user);

    Livewire::actingAs($user)
        ->test(LiveStats::class)
        ->assertSee('Devices')
        ->assertDontSee('Sessions')
        ->assertDontSee('Connections')
        ->assertDontSee('Alarms (24h)');
});

test('the active sessions component rejects direct access without log permission', function () {
    $user = dashboardUserWithAuditLevel('none');
    dashboardAuditFixture($user);

    Livewire::actingAs($user)
        ->test(ActiveSessions::class)
        ->assertForbidden();
});

test('a role with audit view still receives the dashboard audit surfaces', function () {
    $user = dashboardUserWithAuditLevel('r');
    dashboardAuditFixture($user);

    expect($user->consoleAllows('audit', 'r'))->toBeTrue()
        ->and(Permissions::satisfies('r', 'r'))->toBeTrue();

    $this->actingAs($user)
        ->get(route('overview'))
        ->assertOk()
        ->assertSee('Sensitive Operator')
        ->assertSee('Active Sessions')
        ->assertSee('Connections started today')
        ->assertSee('Alarms (24h)');
});
