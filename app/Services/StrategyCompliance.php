<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-strategy fleet compliance: what each device is holding versus what its
 * resolved strategy wants (docs/strategy-protocol.md §1, §2.2).
 *
 * States, for a device whose resolved strategy is S:
 *   confirmed  the device echoed S's current map after it was sent
 *   pending    the map was pushed (or changed) within the confirmation window
 *   stale      window passed, device is online, still no matching echo
 *   offline    window passed and the device is offline
 * A device counts as "overridden" for every strategy assigned to it (directly,
 * via owner, via group) that lost precedence to S.
 */
class StrategyCompliance
{
    public const DETAIL_LIMIT = 200;

    public const STATES = ['confirmed', 'pending', 'stale', 'offline', 'overridden'];

    /** @return array{counts:array<string,int>,devices:array<string,array<int,array<string,mixed>>>} */
    public function summary(Strategy $strategy, string $detailState = 'all', int $detailLimit = self::DETAIL_LIMIT): array
    {
        return $this->summaries(collect([$strategy]), $strategy->id, $detailState, $detailLimit)[$strategy->id];
    }

    /**
     * One streamed query over the active fleet, joined to the three assignment
     * pivots; the per-device classification runs in PHP. Counts are exact,
     * detail rows are capped.
     *
     * @param  Collection<int,Strategy>  $strategies
     * @return array<int,array{counts:array<string,int>,devices:array<string,array<int,array<string,mixed>>>}>
     */
    public function summaries(
        Collection $strategies,
        ?int $detailStrategyId = null,
        string $detailState = 'all',
        int $detailLimit = self::DETAIL_LIMIT,
    ): array {
        $strategies = $strategies->keyBy('id');
        if ($strategies->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($strategies as $strategy) {
            $result[$strategy->id] = [
                'counts' => array_fill_keys(self::STATES, 0),
                'devices' => array_fill_keys(self::STATES, []),
            ];
        }
        $desired = $strategies->map(fn (Strategy $strategy) => $strategy->configOptions())->all();
        $changedAt = $this->optionsChangedAt($strategies);
        $detailLimit = max(0, min(1000, $detailLimit));
        $storedDetails = 0;

        $rows = Device::query()
            ->leftJoin('device_strategy as ds', 'ds.device_id', '=', 'devices.id')
            ->leftJoin('strategy_user as su', 'su.user_id', '=', 'devices.user_id')
            ->leftJoin('device_group_strategy as dgs', 'dgs.device_group_id', '=', 'devices.device_group_id')
            ->where('devices.status', Device::STATUS_ACTIVE)
            ->orderBy('devices.id')
            ->select([
                'devices.*',
                'ds.strategy_id as direct_strategy_id',
                'su.strategy_id as owner_strategy_id',
                'dgs.strategy_id as group_strategy_id',
            ])
            ->cursor();

        foreach ($rows as $device) {
            $resolvedId = $device->strategy_id_resolved === null ? null : (int) $device->strategy_id_resolved;

            if ($resolvedId !== null && isset($result[$resolvedId])) {
                $state = $this->stateFor($device, $strategies[$resolvedId], $desired[$resolvedId], $changedAt[$resolvedId]);
                $this->record($result, $device, $resolvedId, $state, $detailStrategyId, $detailState, $detailLimit, $storedDetails);
            }

            // The default strategy is not a candidate here: it applies only
            // when nothing else does, so a device with its own assignment has
            // not "overridden" it.
            $candidateIds = array_unique(array_filter([
                $device->getAttribute('direct_strategy_id'),
                $device->getAttribute('owner_strategy_id'),
                $device->getAttribute('group_strategy_id'),
            ], fn ($id) => $id !== null));
            foreach ($candidateIds as $candidateId) {
                $candidateId = (int) $candidateId;
                if (! isset($result[$candidateId]) || $resolvedId === $candidateId) {
                    continue;
                }
                $this->record($result, $device, $candidateId, 'overridden', $detailStrategyId, $detailState, $detailLimit, $storedDetails);
            }
        }

        foreach ($result as &$summary) {
            foreach ($summary['devices'] as &$devices) {
                usort($devices, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));
            }
            unset($devices);
        }
        unset($summary);

        return $result;
    }

    /**
     * When each strategy's option map last changed: the created_at of the
     * oldest revision in the run of revisions (newest first) that still carries
     * the current map. A note-only save writes a revision but does not move
     * this. Falls back to updated_at for strategies with no revisions.
     *
     * @param  Collection<int,Strategy>  $strategies
     * @return array<int,?Carbon>
     */
    private function optionsChangedAt(Collection $strategies): array
    {
        $revisions = StrategyRevision::query()
            ->whereIn('strategy_id', $strategies->keys())
            ->orderByDesc('revision')
            ->get(['strategy_id', 'revision', 'snapshot', 'created_at'])
            ->groupBy('strategy_id');

        $out = [];
        foreach ($strategies as $id => $strategy) {
            $current = $strategy->configOptions();
            $at = null;
            foreach ($revisions->get($id, collect()) as $revision) {
                $options = Strategy::expandIdWhitelistGroups(Strategy::sanitizeOptions(is_array($revision->snapshot['options'] ?? null) ? $revision->snapshot['options'] : []));
                if ($options !== $current) {
                    break;
                }
                $at = $revision->created_at;
            }
            $out[$id] = $at ?? $strategy->updated_at;
        }

        return $out;
    }

    /** @param array<string,string> $desired */
    private function stateFor(Device $device, Strategy $strategy, array $desired, ?Carbon $changedAt): string
    {
        $acked = Strategy::stringMap($device->strategy_acked_options);
        $sentAt = $device->strategy_sent_at;
        $ackedAt = $device->strategy_acked_at;
        if ($ackedAt !== null && $acked === $desired && ($sentAt === null || $ackedAt->gte($sentAt))) {
            return 'confirmed';
        }

        // The clock starts when the current map was pushed to this device, or,
        // if the device has not been sent the current map yet, when the map
        // changed.
        $sent = Strategy::stringMap($device->strategy_options);
        $deadlineFrom = $sent === $desired ? ($sentAt ?? $changedAt) : $changedAt;
        $timeout = max(1, (int) ($strategy->confirmation_timeout_minutes ?: 15));
        if ($deadlineFrom === null || $deadlineFrom->gt(now()->subMinutes($timeout))) {
            return 'pending';
        }

        return $device->isOnline() ? 'stale' : 'offline';
    }

    private function record(
        array &$result,
        Device $device,
        int $strategyId,
        string $state,
        ?int $detailStrategyId,
        string $detailState,
        int $detailLimit,
        int &$storedDetails,
    ): void {
        $result[$strategyId]['counts'][$state]++;
        if ($strategyId !== $detailStrategyId
            || ($detailState !== 'all' && $detailState !== $state)
            || $storedDetails >= $detailLimit) {
            return;
        }

        $result[$strategyId]['devices'][$state][] = $this->row($device, $state);
        $storedDetails++;
    }

    /** @return array<string,mixed> */
    private function row(Device $device, string $state): array
    {
        return [
            'id' => $device->id,
            'rustdesk_id' => $device->rustdesk_id,
            'label' => $device->alias ?: $device->hostname ?: 'Unlabelled',
            'state' => $state,
            'last_online' => $this->when($device->last_online_at) ?? 'Never',
            'sent' => $this->when($device->strategy_sent_at) ?? '—',
            'confirmed' => $this->when($device->strategy_acked_at) ?? '—',
        ];
    }

    private function when(?Carbon $at): ?string
    {
        return $at?->timezone(config('app.timezone'))->format('M j, Y g:i A T');
    }
}
