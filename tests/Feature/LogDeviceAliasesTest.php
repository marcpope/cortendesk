<?php

use App\Livewire\AlarmLogList;
use App\Livewire\ConnectionLog;
use App\Livewire\FileTransferLog;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

it('shows escaped current aliases in desktop and phone logs and tolerates missing devices', function ($component, $model, $rows) {
    $admin = User::create(['username' => 'admin', 'password' => 'test', 'is_admin' => true]);
    $device = Device::withoutEvents(fn () => Device::create(['rustdesk_id' => '123', 'uuid' => 'test', 'alias' => '<script>Customer</script>', 'status' => 'active']));
    foreach (['123', 'missing'] as $id) {
        $model::create(['rustdesk_id' => $id, 'from_peer' => '456', 'typ' => 0]);
    }
    $this->actingAs($admin);
    Livewire::test($component)->assertSee('<script>Customer</script>')->assertDontSee('<script>Customer</script>', false)
        ->assertViewHas($rows, fn ($logs) => $logs->every(fn ($log) => $log->relationLoaded('controlledDevice')));
    $device->update(['alias' => 'Renamed target']);
    Livewire::test($component)->assertSee('Renamed target');
    $device->delete();
    Livewire::test($component)->assertDontSee('Renamed target')->assertSee('123');
})->with([
    [ConnectionLog::class, AuditConnection::class, 'connections'],
    [FileTransferLog::class, AuditFileTransfer::class, 'transfers'],
    [AlarmLogList::class, AlarmLog::class, 'alarms'],
]);

it('does not expose a hidden target alias to a scoped reader', function ($component, $model) {
    $viewer = User::create(['username' => 'viewer', 'password' => 'test']);
    Device::withoutEvents(fn () => Device::create(['rustdesk_id' => 'hidden', 'uuid' => 'hidden', 'alias' => 'Private target', 'status' => 'active']));
    $model::create(['rustdesk_id' => 'hidden', 'from_peer' => '456', 'typ' => 0]);
    $this->actingAs($viewer);
    Livewire::test($component)->assertDontSee('Private target');
})->with([
    [ConnectionLog::class, AuditConnection::class],
    [FileTransferLog::class, AuditFileTransfer::class],
    [AlarmLogList::class, AlarmLog::class],
]);
