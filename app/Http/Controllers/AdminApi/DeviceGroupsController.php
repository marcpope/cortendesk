<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeviceGroupsController extends AdminApiController
{
    /** GET /api/v1/device-groups. */
    public function index(Request $request): JsonResponse
    {
        $groups = DeviceGroup::query()
            ->withCount('devices')
            ->when($request->filled('name'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->query('name').'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($groups, fn (DeviceGroup $g) => $this->serialize($g));
    }

    public function show(DeviceGroup $deviceGroup): JsonResponse
    {
        return $this->ok($this->serialize($deviceGroup->loadCount('devices'), true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('device_groups', 'name')],
            'note' => ['nullable', 'string'],
        ]);

        $group = DeviceGroup::create($data);

        ConsoleAudit::record('group.create', 'Created device group '.$group->name.' (API)', 'group', $group->name);

        return $this->created($this->serialize($group->loadCount('devices')), 'Device group created.');
    }

    public function update(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('device_groups', 'name')->ignore($deviceGroup->id)],
            'note' => ['nullable', 'string'],
        ]);

        $deviceGroup->update($data);

        ConsoleAudit::record('group.update', 'Updated device group '.$deviceGroup->name.' (API)', 'group', $deviceGroup->name);

        return $this->ok($this->serialize($deviceGroup->loadCount('devices')), 'Device group updated.');
    }

    public function destroy(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $name = $deviceGroup->name;
        try {
            DB::transaction(function () use ($request, $deviceGroup): void {
                // Detach devices (keep them), then remove the folder.
                Device::bulkUpdateStrategyContext(
                    Device::query()->where('device_group_id', $deviceGroup->id),
                    ['device_group_id' => null],
                    $this->token($request)->allows('strategy', 'rw'),
                );
                $deviceGroup->userGroups()->detach();
                $deviceGroup->delete();
            });
        } catch (AuthorizationException) {
            return $this->fail("Token lacks 'rw' permission on 'strategy'.", 403);
        }

        ConsoleAudit::record('group.delete', 'Deleted device group '.$name.' (API)', 'group', $name);

        return $this->ok(null, 'Device group deleted.');
    }

    /** POST /api/v1/device-groups/{deviceGroup}/members — put a device in this folder. */
    public function addMember(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $device = $this->resolveDevice($request);
        if (! $device) {
            return $this->fail('Device not found.', 404);
        }

        try {
            $device = Device::updateWithStrategyContext(
                $device,
                ['device_group_id' => $deviceGroup->id],
                $this->token($request)->allows('strategy', 'rw'),
            );
        } catch (AuthorizationException) {
            return $this->fail("Token lacks 'rw' permission on 'strategy'.", 403);
        }

        ConsoleAudit::record('group.update', 'Added device '.$device->rustdesk_id.' to '.$deviceGroup->name.' (API)', 'group', $deviceGroup->name);

        return $this->ok($this->serialize($deviceGroup->loadCount('devices'), true), 'Device added.');
    }

    /** DELETE /api/v1/device-groups/{deviceGroup}/members — remove a device from this folder. */
    public function removeMember(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $device = $this->resolveDevice($request);
        if (! $device) {
            return $this->fail('Device not found.', 404);
        }

        if ($device->device_group_id === $deviceGroup->id) {
            try {
                $device = Device::updateWithStrategyContext(
                    $device,
                    ['device_group_id' => null],
                    $this->token($request)->allows('strategy', 'rw'),
                );
            } catch (AuthorizationException) {
                return $this->fail("Token lacks 'rw' permission on 'strategy'.", 403);
            }
        }

        ConsoleAudit::record('group.update', 'Removed device '.$device->rustdesk_id.' from '.$deviceGroup->name.' (API)', 'group', $deviceGroup->name);

        return $this->ok(null, 'Device removed.');
    }

    private function resolveDevice(Request $request): ?Device
    {
        if ($request->filled('device_id')) {
            return Device::find($request->input('device_id'));
        }
        if ($request->filled('rustdesk_id')) {
            return Device::where('rustdesk_id', $request->input('rustdesk_id'))->first();
        }

        return null;
    }

    private function serialize(DeviceGroup $group, bool $withMembers = false): array
    {
        $out = [
            'id' => $group->id,
            'name' => $group->name,
            'note' => $group->note,
            'devices_count' => $group->devices_count ?? $group->devices()->count(),
        ];

        if ($withMembers) {
            $out['devices'] = $group->devices()->orderBy('rustdesk_id')
                ->get(['id', 'rustdesk_id', 'alias', 'hostname'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'rustdesk_id' => $d->rustdesk_id,
                    'alias' => $d->alias,
                    'hostname' => $d->hostname,
                ])->all();
        }

        return $out;
    }
}
