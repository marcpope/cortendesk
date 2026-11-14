<?php

use App\Contracts\IdServerConsole;
use App\Livewire\DeviceDetail;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Services\ReleaseId;
use Livewire\Livewire;

/*
 * Release ID (cortendesk-server #19): the device page frees a device's ID on
 * the bundled ID server through hbbs's loopback console (`release-id <id>`).
 */

/** Stands in for hbbs's loopback console. */
class FakeIdServerConsole implements IdServerConsole
{
    public array $sent = [];

    public function __construct(public ?string $reply = null) {}

    public function command(string $command): ?string
    {
        $this->sent[] = $command;

        return $this->reply;
    }
}

function fakeIdServer(?string $reply): FakeIdServerConsole
{
    $console = new FakeIdServerConsole($reply);
    app()->instance(IdServerConsole::class, $console);

    return $console;
}

beforeEach(function () {
    config(['cortendesk.embedded_server' => true]);
    $this->device = Device::create(['rustdesk_id' => '123456789', 'uuid' => 'u-1', 'hostname' => 'pc']);
    $this->admin = User::factory()->admin()->create();
});

it('releases the ID and records it', function () {
    $console = fakeIdServer("OK 123456789\n");

    Livewire::actingAs($this->admin)->test(DeviceDetail::class, ['deviceId' => $this->device->id])
        ->assertSee(__('devices.release_id'))
        ->call('releaseId')
        ->assertSet('releaseOk', true)
        ->assertSee(__('devices.release_id_released', ['id' => '123456789']));

    expect($console->sent)->toBe(['release-id 123456789'])
        ->and(ConsoleAudit::where('action', 'device.release-id')->count())->toBe(1)
        ->and(Device::find($this->device->id))->not->toBeNull('the console keeps its device record');
});

it('says when the machine was online and will take the ID back', function () {
    fakeIdServer("OK 123456789 online\n");

    Livewire::actingAs($this->admin)->test(DeviceDetail::class, ['deviceId' => $this->device->id])
        ->call('releaseId')
        ->assertSee(__('devices.release_id_released_online', ['id' => '123456789']));
});

it('reports an unknown ID, an unreachable server and a refusal without auditing', function (?string $reply, string $key, bool $withId) {
    fakeIdServer($reply);

    Livewire::actingAs($this->admin)->test(DeviceDetail::class, ['deviceId' => $this->device->id])
        ->call('releaseId')
        ->assertSet('releaseOk', false)
        ->assertSee($withId ? __($key, ['id' => '123456789']) : __($key));

    expect(ConsoleAudit::where('action', 'device.release-id')->count())->toBe(0);
})->with([
    'not found' => ["NOT_FOUND 123456789\n", 'devices.release_id_not_found', true],
    'unreachable' => [null, 'devices.release_id_unavailable', false],
    'older server' => ["unknown command\n", 'devices.release_id_failed', true],
    'error' => ["ERROR database\n", 'devices.release_id_failed', true],
]);

it('is only offered with the bundled server', function () {
    config(['cortendesk.embedded_server' => false]);
    $console = fakeIdServer("OK 123456789\n");

    Livewire::actingAs($this->admin)->test(DeviceDetail::class, ['deviceId' => $this->device->id])
        ->assertDontSee(__('devices.release_id_hint'))
        ->call('releaseId')
        ->assertSee(__('devices.release_id_unavailable'));

    expect($console->sent)->toBe([]);
});

it('needs write access to devices', function () {
    $role = Role::create(['name' => 'Device viewer', 'permissions' => Role::normalizePermissions(['device' => 'r'])]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $this->device->forceFill(['user_id' => $viewer->id])->save();
    $console = fakeIdServer("OK 123456789\n");

    Livewire::actingAs($viewer)->test(DeviceDetail::class, ['deviceId' => $this->device->id])
        ->call('releaseId')
        ->assertForbidden();

    expect($console->sent)->toBe([]);
});

it('never sends an ID that could carry another command', function () {
    $console = fakeIdServer("OK x\n");

    expect(app(ReleaseId::class)->release("123\nblock-list-add 1.2.3.4"))->toBe(ReleaseId::UNAVAILABLE)
        ->and(app(ReleaseId::class)->release('12 34'))->toBe(ReleaseId::UNAVAILABLE)
        ->and($console->sent)->toBe([]);
});
