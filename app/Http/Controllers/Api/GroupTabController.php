<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\SerializesUsers;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group ("Your address book") tab endpoints. Row sets are scoped to the
 * authenticated client user — admins see everything, everyone else follows
 * the strict folder model (Device::scopeVisibleTo / accessibleDeviceGroupIds).
 * Response SHAPES are contract-frozen per docs/client-api.md §18–§20.
 */
class GroupTabController extends Controller
{
    use SerializesUsers;

    /** GET /api/device-group/accessible — spec §18: only `name` is read. */
    public function deviceGroups(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);
        $user = $request->user();

        $query = DeviceGroup::query()->orderBy('name');

        // Non-admins must never learn the names of folders they weren't granted.
        if (! $user->seesAllDevices()) {
            $query->whereIn('id', $user->accessibleDeviceGroupIds());
        }

        $total = (clone $query)->count();

        return response()->json([
            'total' => $total,
            'data' => $query->forPage($page, $size)->get()
                ->map(fn (DeviceGroup $g) => ['name' => $g->name])
                ->all(),
        ]);
    }

    /** GET /api/users — spec §19. `status=1` filters to active users. */
    public function users(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);
        $user = $request->user();

        $query = User::query()->orderBy('username');

        // Non-admins see group-mates plus anyone in a user group "accessed from"
        // one of their groups (PLAN B4), plus themself. visibleUserIds() is the
        // single source of truth for that set.
        if (! $user->seesAllDevices()) {
            $query->whereIn('id', $user->visibleUserIds());
        }

        if ((string) $request->query('status', '') === '1') {
            $query->where('is_active', true);
        }

        $total = (clone $query)->count();

        return response()->json([
            'total' => $total,
            'data' => $query->forPage($page, $size)->get()
                ->map(fn (User $u) => $this->userPayload($u))
                ->all(),
        ]);
    }

    /** GET /api/peers — spec §20: PeerPayload with `info` as an OBJECT. */
    public function peers(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);

        // Strict folder model: own devices + devices in accessible folders.
        // scopeVisibleTo is a no-op for admins.
        $query = Device::query()
            ->visibleTo($request->user())
            ->with(['user:id,username', 'group:id,name'])
            ->orderBy('rustdesk_id');

        $total = (clone $query)->count();

        return response()->json([
            'total' => $total,
            'data' => $query->forPage($page, $size)->get()
                ->map(fn (Device $d) => [
                    'id' => $d->rustdesk_id,
                    // Not read by stock clients; for customised ones that
                    // want the console's name for a device (issue #28).
                    'alias' => (string) $d->alias,
                    'info' => [
                        'username' => (string) $d->username,
                        'os' => (string) $d->os,
                        'device_name' => (string) $d->hostname,
                    ],
                    'status' => $d->isOnline() ? 1 : 0,
                    'user' => (string) ($d->user?->username ?? ''),
                    'user_name' => (string) ($d->user?->username ?? ''),
                    'device_group_name' => (string) ($d->group?->name ?? ''),
                    'note' => (string) $d->note,
                ])
                ->all(),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function pagination(Request $request): array
    {
        return [
            max(1, (int) $request->query('current', 1)),
            min(500, max(1, (int) $request->query('pageSize', 100))),
        ];
    }
}
