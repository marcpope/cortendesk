<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;

/** Builds a reviewed policy change and fingerprints every input to its result. */
class StrategyImpact
{
    /** @param array<string,mixed> $proposed @return array<string,mixed> */
    public static function preview(?Strategy $strategy, array $proposed, bool $includeSample = true): array
    {
        $before = self::snapshot($strategy);
        $beforeOptions = $before['options'];
        $afterOptions = Strategy::sanitizeOptions(is_array($proposed['options'] ?? null) ? $proposed['options'] : []);
        $optionChanges = [];

        foreach (array_unique([...array_keys($beforeOptions), ...array_keys($afterOptions)]) as $key) {
            $old = $beforeOptions[$key] ?? null;
            $new = $afterOptions[$key] ?? null;
            if ($old !== $new) {
                $optionChanges[$key] = ['before' => $old, 'after' => $new];
            }
        }
        ksort($optionChanges);

        $dangerousValues = [
            'access-mode' => ['view'],
            'enable-keyboard' => ['N'],
            'enable-file-transfer' => ['N'],
            'enable-terminal' => ['N'],
            'allow-remote-config-modification' => ['Y'],
        ];
        $dangerous = [];
        foreach ($optionChanges as $key => $change) {
            if (isset($dangerousValues[$key]) && in_array($change['after'], $dangerousValues[$key], true)) {
                $dangerous[] = ['key' => $key, 'before' => $change['before'], 'after' => $change['after']];
            }
        }

        $snapshot = [
            'name' => (string) ($proposed['name'] ?? ''),
            'note' => $proposed['note'] ?? null,
            'enabled' => (bool) ($proposed['enabled'] ?? false),
            'is_default' => (bool) ($proposed['is_default'] ?? false),
            'enforce' => (bool) ($proposed['enforce'] ?? false),
            'options' => $afterOptions,
            'operation' => (string) ($proposed['operation'] ?? 'save'),
            'restore_revision_id' => isset($proposed['restore_revision_id']) ? (int) $proposed['restore_revision_id'] : null,
        ];
        $policyChanged = $optionChanges !== []
            || $before['enabled'] !== $snapshot['enabled']
            || $before['is_default'] !== $snapshot['is_default']
            || $before['enforce'] !== $snapshot['enforce'];
        $scan = self::scan($strategy, $snapshot, $policyChanged, $includeSample);

        return [
            'option_changes' => $optionChanges,
            'dangerous' => $dangerous,
            'resets' => collect($optionChanges)
                ->filter(fn (array $change) => $change['before'] !== null && $change['after'] === null)
                ->keys()->values()->all(),
            'affected_count' => $scan['count'],
            'affected_devices' => $scan['sample'],
            'metadata_changes' => collect(['name', 'note', 'enabled', 'is_default', 'enforce'])
                ->filter(fn (string $key) => $before[$key] !== $snapshot[$key])
                ->mapWithKeys(fn (string $key) => [$key => [
                    'before' => $before[$key],
                    'after' => $snapshot[$key],
                ]])->all(),
            'fingerprint' => $scan['fingerprint'],
        ];
    }

    /** @return array{name:?string,note:?string,enabled:bool,is_default:bool,enforce:bool,options:array<string,string>} */
    private static function snapshot(?Strategy $strategy): array
    {
        return $strategy === null ? [
            'name' => null,
            'note' => null,
            'enabled' => false,
            'is_default' => false,
            'enforce' => false,
            'options' => [],
        ] : [
            'name' => $strategy->name,
            'note' => $strategy->note,
            'enabled' => (bool) $strategy->enabled,
            'is_default' => (bool) $strategy->is_default,
            'enforce' => (bool) $strategy->enforce,
            'options' => $strategy->optionMap(),
        ];
    }

    /** @param array<string,mixed> $proposed @return array{count:int,sample:array<int,array<string,mixed>>,fingerprint:string} */
    private static function scan(?Strategy $strategy, array $proposed, bool $policyChanged, bool $includeSample = true): array
    {
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([
            'strategy_id' => $strategy?->id,
            'proposed' => $proposed,
        ], JSON_THROW_ON_ERROR));

        $targetId = $strategy?->id ?? -1;
        $strategies = Strategy::query()->orderBy('id')->get(['id', 'name', 'enabled', 'is_default', 'enforce', 'options']);
        $enabled = $strategies->mapWithKeys(fn (Strategy $item) => [(int) $item->id => (bool) $item->enabled])->all();
        $enabled[$targetId] = (bool) $proposed['enabled'];
        foreach ($strategies as $item) {
            hash_update($hash, 'strategy:'.$item->id.':'.$item->name.':'.(int) $item->enabled.':'.(int) $item->is_default.':'.(int) $item->enforce.':'.json_encode($item->optionMap(), JSON_THROW_ON_ERROR).';');
        }

        $defaultId = ($proposed['enabled'] && $proposed['is_default'])
            ? $targetId
            : $strategies->first(fn (Strategy $item) => $item->id !== $strategy?->id && $item->enabled && $item->is_default)?->id;

        foreach ([
            Strategy::LEVEL_DEVICE => ['device_strategy', 'device_id'],
            Strategy::LEVEL_USER => ['strategy_user', 'user_id'],
            Strategy::LEVEL_DEVICE_GROUP => ['device_group_strategy', 'device_group_id'],
        ] as $level => [$table, $column]) {
            DB::table($table)->orderBy($column)->each(function ($row) use ($hash, $level, $column): void {
                hash_update($hash, $level.':'.$row->{$column}.':'.$row->strategy_id.';');
            });
        }

        $count = 0;
        $sample = [];
        Device::query()->where('status', Device::STATUS_ACTIVE)->orderBy('id')->chunkById(500,
            function ($devices) use ($hash, $enabled, $defaultId, $targetId, $policyChanged, $includeSample, &$count, &$sample): void {
                $deviceIds = $devices->pluck('id')->all();
                $userIds = $devices->pluck('user_id')->filter()->unique()->all();
                $groupIds = $devices->pluck('device_group_id')->filter()->unique()->all();
                $direct = DB::table('device_strategy')->whereIn('device_id', $deviceIds)->pluck('strategy_id', 'device_id')->all();
                $owners = $userIds === [] ? [] : DB::table('strategy_user')->whereIn('user_id', $userIds)->pluck('strategy_id', 'user_id')->all();
                $groups = $groupIds === [] ? [] : DB::table('device_group_strategy')->whereIn('device_group_id', $groupIds)->pluck('strategy_id', 'device_group_id')->all();

                foreach ($devices as $device) {
                    hash_update($hash, 'device:'.$device->id.':'.$device->user_id.':'.$device->device_group_id.':'.$device->strategy_id_resolved.';');
                    $winner = null;
                    $winningLevel = null;
                    foreach ([
                        Strategy::LEVEL_DEVICE => $direct[$device->id] ?? null,
                        Strategy::LEVEL_USER => $device->user_id === null ? null : ($owners[$device->user_id] ?? null),
                        Strategy::LEVEL_DEVICE_GROUP => $device->device_group_id === null ? null : ($groups[$device->device_group_id] ?? null),
                        'default' => $defaultId,
                    ] as $level => $candidate) {
                        if ($candidate !== null && ($enabled[(int) $candidate] ?? false)) {
                            $winner = (int) $candidate;
                            $winningLevel = $level;
                            break;
                        }
                    }

                    $current = $device->strategy_id_resolved === null ? null : (int) $device->strategy_id_resolved;
                    if ($policyChanged && ($winner !== $current || $winner === $targetId)) {
                        $count++;
                        if ($includeSample && count($sample) < 50) {
                            $sample[] = [
                                'id' => $device->id,
                                'rustdesk_id' => $device->rustdesk_id,
                                'label' => $device->alias ?: ($device->hostname ?: $device->rustdesk_id),
                                'winning_level' => $winningLevel,
                            ];
                        }
                    }
                }
            });

        usort($sample, fn (array $a, array $b) => strcmp($a['rustdesk_id'], $b['rustdesk_id']));

        return ['count' => $count, 'sample' => $sample, 'fingerprint' => hash_final($hash)];
    }
}
