<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicesController extends AdminApiController
{
    /**
     * GET /api/v1/devices — filters: id, name, user, group (owner's user
     * group), device_group, offline_days.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()
            ->with(['user', 'group'])
            ->when($request->filled('id'), fn ($q) => $q
                ->where('rustdesk_id', 'like', '%'.$request->query('id').'%'))
            ->when($request->filled('name'), function ($q) use ($request) {
                $s = '%'.$request->query('name').'%';
                $q->where(fn ($q) => $q->where('alias', 'like', $s)
                    ->orWhere('hostname', 'like', $s));
            })
            ->when($request->filled('user'), function ($q) use ($request) {
                $s = '%'.$request->query('user').'%';
                $q->whereHas('user', fn ($u) => $u->where('username', 'like', $s)
                    ->orWhere('name', 'like', $s));
            })
            ->when($request->filled('group'), function ($q) use ($request) {
                $group = $request->query('group');
                $q->whereHas('user.groups', fn ($g) => is_numeric($group)
                    ? $g->where('user_groups.id', (int) $group)
                    : $g->where('user_groups.name', $group));
            })
            ->when($request->filled('device_group'), function ($q) use ($request) {
                $dg = $request->query('device_group');
                is_numeric($dg)
                    ? $q->where('device_group_id', (int) $dg)
                    : $q->whereHas('group', fn ($g) => $g->where('name', $dg));
            })
            ->when($request->filled('offline_days'), function ($q) use ($request) {
                $cutoff = now()->subDays((int) $request->query('offline_days'));
                $q->where(fn ($q) => $q->whereNull('last_online_at')
                    ->orWhere('last_online_at', '<', $cutoff));
            })
            ->orderBy('rustdesk_id')
            ->paginate($this->perPage($request));

        return $this->paginated($devices, fn (Device $d) => $this->serialize($d));
    }

    /** GET /api/v1/devices/{device}. */
    public function show(Device $device): JsonResponse
    {
        return $this->ok($this->serialize($device->load(['user', 'group'])));
    }

    /** POST /api/v1/devices/{device}/enable — clear disabled flag (soft-restore). */
    public function enable(Device $device): JsonResponse
    {
        if ($device->trashed()) {
            $device = Device::restoreWithStrategyContext($device);
        }

        ConsoleAudit::record('device.enable', 'Enabled device '.$device->rustdesk_id.' (API)', 'device', $device->rustdesk_id);

        return $this->ok($this->serialize($device), 'Device enabled.');
    }

    /** POST /api/v1/devices/{device}/disable — soft-delete (revocable). */
    public function disable(Device $device): JsonResponse
    {
        Device::deleteWithStrategyContext($device);

        ConsoleAudit::record('device.disable', 'Disabled device '.$device->rustdesk_id.' (API)', 'device', $device->rustdesk_id);

        return $this->ok(null, 'Device disabled.');
    }

    /** DELETE /api/v1/devices/{device}. */
    public function destroy(Device $device): JsonResponse
    {
        $id = $device->rustdesk_id;
        Device::deleteWithStrategyContext($device, true);

        ConsoleAudit::record('device.destroy', 'Destroyed device '.$id.' (API)', 'device', $id);

        return $this->ok(null, 'Device deleted.');
    }

    /**
     * POST /api/v1/devices/{device}/assign — set owner and/or device group.
     * Accepts user_id|user_name and device_group_id|device_group_name.
     */
    public function assign(Request $request, Device $device): JsonResponse
    {
        $changes = [];

        if ($request->has('user_id') || $request->has('user_name')) {
            if ($request->filled('user_id')) {
                $user = User::find($request->input('user_id'));
            } elseif ($request->filled('user_name')) {
                $user = User::where('username', $request->input('user_name'))->first();
            } else {
                $user = null; // explicit unassign
            }

            if (($request->filled('user_id') || $request->filled('user_name')) && ! $user) {
                return $this->fail('User not found.', 404);
            }

            $changes['user_id'] = $user?->id;
        }

        if ($request->has('device_group_id') || $request->has('device_group_name')) {
            if ($request->filled('device_group_id')) {
                $group = DeviceGroup::find($request->input('device_group_id'));
            } elseif ($request->filled('device_group_name')) {
                $group = DeviceGroup::where('name', $request->input('device_group_name'))->first();
            } else {
                $group = null; // explicit unassign
            }

            if (($request->filled('device_group_id') || $request->filled('device_group_name')) && ! $group) {
                return $this->fail('Device group not found.', 404);
            }

            $changes['device_group_id'] = $group?->id;
        }

        if ($changes === []) {
            return $this->fail('Nothing to assign; provide user_(id|name) or device_group_(id|name).', 422);
        }

        try {
            $device = Device::updateWithStrategyContext(
                $device,
                $changes,
                $this->token($request)->allows('strategy', 'rw'),
            );
        } catch (AuthorizationException) {
            return $this->fail("Token lacks 'rw' permission on 'strategy'.", 403);
        }

        ConsoleAudit::record('device.assign', 'Reassigned device '.$device->rustdesk_id.' (API)', 'device', $device->rustdesk_id);

        return $this->ok($this->serialize($device->fresh()->load(['user', 'group'])), 'Device assigned.');
    }

    private function serialize(Device $device): array
    {
        return [
            'id' => $device->id,
            'rustdesk_id' => $device->rustdesk_id,
            'uuid' => $device->uuid,
            'alias' => $device->alias,
            'hostname' => $device->hostname,
            'os' => $device->os,
            'platform' => $device->platform(),
            'cpu' => $device->cpu,
            'memory' => $device->memory,
            'version' => $device->version,
            'username' => $device->username,
            'note' => $device->note,
            'status' => $device->trashed() ? 'disabled' : ($device->isPending() ? 'pending' : 'active'),
            'online' => $device->isOnline(),
            'last_online_at' => $device->last_online_at?->toIso8601String(),
            'last_online_ip' => $device->last_online_ip,
            'registered_ip' => $device->registered_ip,
            'created_at' => $device->created_at?->toIso8601String(),
            'updated_at' => $device->updated_at?->toIso8601String(),
            'user' => $device->user ? ['id' => $device->user->id, 'username' => $device->user->username] : null,
            'device_group' => $device->group ? ['id' => $device->group->id, 'name' => $device->group->name] : null,
            'disabled' => $device->trashed(),
        ];
    }
}
