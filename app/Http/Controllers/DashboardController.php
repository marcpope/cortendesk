<?php

namespace App\Http\Controllers;

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Ranges the connections chart offers, in days. */
    private const RANGES = [14, 30, 90];

    public function index(Request $request): View
    {
        $user = $request->user();
        $canAudit = $user->consoleAllows('audit', 'r');

        // Non-admins: everything on the dashboard is scoped to the devices
        // they can see. Admins: null = no scope (whole fleet). Do not resolve
        // audit scope at all when the role cannot view logs: even aggregate
        // connection activity is part of the gated Logs area.
        $visibleIds = ! $canAudit || $user->seesAllDevices()
            ? null
            : Device::query()->visibleTo($user)->pluck('rustdesk_id')->all();

        // Anything but one of the offered windows (including ?range[]=…) falls
        // back to the default rather than 500ing or querying an odd span.
        $requested = $request->query('range');
        $range = is_scalar($requested) && in_array((int) $requested, self::RANGES, true)
            ? (int) $requested
            : self::RANGES[0];

        return view('overview', [
            'range' => $range,
            'ranges' => self::RANGES,
            'connectionSeries' => $canAudit ? $this->connectionSeries($visibleIds, $range) : null,
            'platformMix' => $this->platformMix($user),
            'versionCounts' => $this->versionCounts($user),
            // Alarm detail is an audit screen; the count tile is fleet-level but
            // the rows name devices, so this panel is gated. null = not allowed.
            'recentAlarms' => $canAudit
                ? $this->recentAlarms($visibleIds)
                : null,
        ]);
    }

    /**
     * Daily connection counts for the requested window, split by type:
     * Remote Control (0), File Transfer (1), Other (port forward, camera, terminal).
     *
     * @param  array<int,string>|null  $visibleIds  null = all devices (admin)
     */
    private function connectionSeries(?array $visibleIds, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = AuditConnection::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->selectRaw('DATE(created_at) as day, conn_type, COUNT(*) as c')
            ->where('created_at', '>=', $from)
            ->groupBy('day', 'conn_type')
            ->get()
            ->groupBy('day');

        $labels = [];
        $remote = [];
        $file = [];
        $other = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i);
            $key = $date->toDateString();
            $labels[] = $date->format('M j');

            $byType = $rows->get($key, collect())->keyBy('conn_type');
            $remote[] = (int) ($byType->get(0)->c ?? 0);
            $file[] = (int) ($byType->get(1)->c ?? 0);
            $other[] = (int) $rows->get($key, collect())
                ->reject(fn ($r) => in_array((int) $r->conn_type, [0, 1], true))
                ->sum('c');
        }

        return [
            'labels' => $labels,
            'series' => [
                ['name' => AuditConnection::typeLabel(0), 'data' => $remote],
                ['name' => AuditConnection::typeLabel(1), 'data' => $file],
                ['name' => __('labels.misc_platform_other'), 'data' => $other],
            ],
        ];
    }

    /** Device platform mix: top 3 platforms + Other (max 4 donut segments). */
    private function platformMix(User $user): array
    {
        $names = [
            'windows' => 'Windows',
            'macos' => 'macOS',
            'linux' => 'Linux',
            'android' => 'Android',
            'ios' => 'iOS',
            'unknown' => __('labels.misc_platform_unknown'),
        ];

        $counts = Device::query()->visibleTo($user)->get()
            ->groupBy(fn (Device $d) => $d->platform())
            ->map->count()
            ->sortDesc();

        $top = $counts->take(3);
        $otherCount = $counts->slice(3)->sum();

        $labels = $top->keys()->map(fn ($k) => $names[$k] ?? ucfirst($k))->values()->all();
        $values = $top->values()->all();

        if ($otherCount > 0) {
            $labels[] = 'Other';
            $values[] = $otherCount;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** Client version distribution (top 6). */
    private function versionCounts(User $user): array
    {
        $rows = Device::query()
            ->visibleTo($user)
            ->selectRaw("COALESCE(NULLIF(version, ''), 'unknown') as v, COUNT(*) as c")
            ->groupBy('v')
            ->orderByDesc('c')
            ->limit(6)
            ->get();

        return [
            'labels' => $rows->pluck('v')->all(),
            'values' => $rows->pluck('c')->map(fn ($c) => (int) $c)->all(),
        ];
    }

    /**
     * The five most recent alarms of the last 24 hours. Scoping a non-admin to
     * their visible device ids also drops console-raised alarms, which carry
     * the 'console' sentinel id — the same rule the alarm log screen applies.
     *
     * @param  array<int,string>|null  $visibleIds  null = all devices (admin)
     * @return Collection<int, AlarmLog>
     */
    private function recentAlarms(?array $visibleIds): Collection
    {
        return AlarmLog::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('rustdesk_id', $visibleIds))
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(5)
            ->get();
    }
}
