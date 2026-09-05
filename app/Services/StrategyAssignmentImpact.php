<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds an assignment preview and fingerprints its complete routing source. */
class StrategyAssignmentImpact
{
    private const LEVELS = [
        Strategy::LEVEL_DEVICE => ['device_strategy', 'device_id'],
        Strategy::LEVEL_USER => ['strategy_user', 'user_id'],
        Strategy::LEVEL_DEVICE_GROUP => ['device_group_strategy', 'device_group_id'],
    ];

    /**
     * @param  array<int,int|string>  $deviceIds
     * @param  array<int,int|string>  $userIds
     * @param  array<int,int|string>  $groupIds
     * @return array<string,mixed>
     */
    public static function preview(Strategy $strategy, array $deviceIds, array $userIds, array $groupIds): array
    {
        $desired = [
            Strategy::LEVEL_DEVICE => self::ids($deviceIds),
            Strategy::LEVEL_USER => self::ids($userIds),
            Strategy::LEVEL_DEVICE_GROUP => self::ids($groupIds),
        ];
        $desiredLookup = array_map(fn (array $ids) => array_fill_keys($ids, true), $desired);
        $hash = hash_init('sha256');
        hash_update($hash, json_encode(['strategy_id' => $strategy->id, 'desired' => $desired], JSON_THROW_ON_ERROR));

        $strategies = Strategy::query()->orderBy('id')->get(['id', 'name', 'enabled', 'is_default', 'enforce', 'options']);
        $enabled = $strategies->mapWithKeys(fn (Strategy $item) => [(int) $item->id => (bool) $item->enabled])->all();
        $names = $strategies->pluck('name', 'id')->all();
        $defaultId = $strategies->first(fn (Strategy $item) => $item->enabled && $item->is_default)?->id;
        foreach ($strategies as $item) {
            hash_update($hash, 'strategy:'.$item->id.':'.$item->name.':'.(int) $item->enabled.':'.(int) $item->is_default.':'.(int) $item->enforce.':'.json_encode($item->optionMap(), JSON_THROW_ON_ERROR).';');
        }

        $assignmentChanges = [];
        foreach (self::LEVELS as $level => [$table, $column]) {
            $currentCount = DB::table($table)->where('strategy_id', $strategy->id)->count();
            $retained = 0;
            foreach (array_chunk($desired[$level], 500) as $chunk) {
                $retained += DB::table($table)->where('strategy_id', $strategy->id)->whereIn($column, $chunk)->count();
            }
            $assignmentChanges[$level] = [
                'added_count' => count($desired[$level]) - $retained,
                'removed_count' => $currentCount - $retained,
            ];
            DB::table($table)->orderBy($column)->chunkById(500, function (Collection $rows) use ($hash, $level, $column): void {
                foreach ($rows as $row) {
                    hash_update($hash, $level.':'.$row->{$column}.':'.$row->strategy_id.';');
                }
            }, $column);
        }

        $affectedCount = 0;
        $affectedDevices = [];
        Device::query()->where('status', Device::STATUS_ACTIVE)->orderBy('id')->chunkById(500,
            function (Collection $devices) use ($strategy, $desiredLookup, $enabled, $names, $defaultId, $hash, &$affectedCount, &$affectedDevices): void {
                $deviceIds = $devices->pluck('id')->all();
                $userIds = $devices->pluck('user_id')->filter()->unique()->all();
                $groupIds = $devices->pluck('device_group_id')->filter()->unique()->all();
                $maps = [
                    Strategy::LEVEL_DEVICE => DB::table('device_strategy')->whereIn('device_id', $deviceIds)->pluck('strategy_id', 'device_id')->all(),
                    Strategy::LEVEL_USER => $userIds === [] ? [] : DB::table('strategy_user')->whereIn('user_id', $userIds)->pluck('strategy_id', 'user_id')->all(),
                    Strategy::LEVEL_DEVICE_GROUP => $groupIds === [] ? [] : DB::table('device_group_strategy')->whereIn('device_group_id', $groupIds)->pluck('strategy_id', 'device_group_id')->all(),
                ];

                foreach ($devices as $device) {
                    hash_update($hash, 'device:'.$device->id.':'.$device->user_id.':'.$device->device_group_id.':'.$device->strategy_id_resolved.';');
                    $targets = [
                        Strategy::LEVEL_DEVICE => (int) $device->id,
                        Strategy::LEVEL_USER => $device->user_id === null ? null : (int) $device->user_id,
                        Strategy::LEVEL_DEVICE_GROUP => $device->device_group_id === null ? null : (int) $device->device_group_id,
                    ];
                    $candidates = [];
                    foreach ($targets as $level => $targetId) {
                        if ($targetId === null) {
                            $candidates[$level] = null;

                            continue;
                        }
                        $current = $maps[$level][$targetId] ?? null;
                        if (isset($desiredLookup[$level][$targetId])) {
                            $current = $strategy->id;
                        } elseif ((int) $current === (int) $strategy->id) {
                            $current = null;
                        }
                        $candidates[$level] = $current;
                    }
                    $candidates['default'] = $defaultId;

                    $winner = null;
                    $winningLevel = null;
                    foreach ($candidates as $level => $candidate) {
                        if ($candidate !== null && ($enabled[(int) $candidate] ?? false)) {
                            $winner = (int) $candidate;
                            $winningLevel = $level;
                            break;
                        }
                    }

                    $before = $device->strategy_id_resolved === null ? null : (int) $device->strategy_id_resolved;
                    if ($before !== $winner) {
                        $affectedCount++;
                        if (count($affectedDevices) < 50) {
                            $affectedDevices[] = [
                                'id' => $device->id,
                                'rustdesk_id' => $device->rustdesk_id,
                                'label' => $device->alias ?: ($device->hostname ?: $device->rustdesk_id),
                                'before_strategy' => $before ? ($names[$before] ?? 'Unknown') : 'None',
                                'after_strategy' => $winner ? ($names[$winner] ?? 'Unknown') : 'None',
                                'winning_level' => $winningLevel,
                            ];
                        }
                    }
                }
            });

        usort($affectedDevices, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));

        return [
            'affected_count' => $affectedCount,
            'affected_devices' => $affectedDevices,
            'assignment_changes' => $assignmentChanges,
            'fingerprint' => hash_final($hash),
        ];
    }

    /** @param array<int,int|string> $ids @return array<int,int> */
    private static function ids(array $ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
    }
}
