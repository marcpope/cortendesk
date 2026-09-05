<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A named client-option policy (PLAN C2/C3).
 *
 * A strategy is a flat map of RustDesk option keys → string values that the
 * server pushes to a device inside the ordinary /api/heartbeat response. The
 * wire contract is docs/strategy-protocol.md; the two rules that matter most:
 *
 *  1. `config_options` must be a map<string,string>. ONE non-string value and
 *     the client silently discards the ENTIRE policy. Everything that leaves
 *     this class goes through sanitizeOptions(), which is the only place a
 *     value becomes a string.
 *  2. The client does not compare `modified_at` before applying — it applies
 *     whatever `strategy` it is handed. Change detection is entirely ours, so
 *     the delivery engine (deliveryFor()) sends `strategy` only when the
 *     device's echoed token differs from its stored version, unless the
 *     strategy opts into `enforce` (sticky re-push on every heartbeat).
 *
 * A strategy cannot LOCK a setting in the client UI — only push it. The user
 * can change it back and nothing re-pushes until we send the policy again.
 */
#[Fillable(['name', 'note', 'enabled', 'is_default', 'enforce', 'options', 'confirmation_timeout_minutes'])]
class Strategy extends Model
{
    use SoftDeletes;

    /** @var array<string,string> Assignment levels, highest precedence first. */
    public const LEVEL_DEVICE = 'device';

    public const LEVEL_USER = 'user';

    public const LEVEL_DEVICE_GROUP = 'device_group';

    /** level => [pivot table, target column]. */
    private const PIVOTS = [
        self::LEVEL_DEVICE => ['device_strategy', 'device_id'],
        self::LEVEL_USER => ['strategy_user', 'user_id'],
        self::LEVEL_DEVICE_GROUP => ['device_group_strategy', 'device_group_id'],
    ];

    /**
     * Server-side allowlist of pushable option keys.
     *
     * Deliberately a SECURITY CONTROL, not a convenience: the client will write
     * any key we send into its settings bucket, including `2fa` (a TOTP secret)
     * and `stop-service` (which gates heartbeats off, so a device that receives
     * it can never be reached again). A key absent from this table is dropped at
     * save time and can therefore never reach a device, whatever a form or a
     * hand-edited DB row says.
     *
     * Scope = docs/strategy-protocol.md §3.1 (permissions) + §3.2 (security /
     * password), minus `2fa`, `bot` and `allow-hide-cm`, plus the live /
     * next-session subset of §3.4 (capture & display). §3.3 (network/server) is
     * excluded on purpose and permanently. The rest of §3.4 (the `restart`-only
     * keys, which the strategy path cannot make take effect — §2.5 — and the
     * Android-only one) and all of §3.5 (`preset-*`) are still not exposed.
     *
     * type: bool   → "Y" / "N" / "" (see §3.0: the meaning of "" depends on the
     *                key prefix, so we always emit an explicit Y or N)
     *       enum   → one of `values`
     *       int    → digits, range-checked
     *       string → free text
     *
     * "" is legal for every key and means "reset to the client's built-in
     * default" (§2.3).
     *
     * @var array<string,array{group:string,type:string,values?:array<int,string>,min?:int,max?:int}>
     */
    /**
     * How stale `strategy_acked_at` may get before a re-echo refreshes it.
     * Bounds the write cost of keeping "last confirmed" honest under `enforce`.
     */
    public const ACK_REFRESH_SECONDS = 60;

    public const OPTION_KEYS = [
        // --- permissions (§3.1) -------------------------------------------
        'access-mode' => ['group' => 'permissions', 'type' => 'enum', 'values' => ['', 'full', 'view', 'custom']],
        'enable-keyboard' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-clipboard' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-file-transfer' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-audio' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-camera' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-terminal' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-tunnel' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-remote-restart' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-record-session' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-block-input' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-privacy-mode' => ['group' => 'permissions', 'type' => 'bool'],
        'enable-remote-printer' => ['group' => 'permissions', 'type' => 'bool'],

        // --- security / password (§3.2) -----------------------------------
        'verification-method' => ['group' => 'security', 'type' => 'enum', 'values' => [
            '', 'use-temporary-password', 'use-permanent-password', 'use-both-passwords',
        ]],
        'approve-mode' => ['group' => 'security', 'type' => 'enum', 'values' => [
            '', 'password', 'click', 'password-click',
        ]],
        'temporary-password-length' => ['group' => 'security', 'type' => 'enum', 'values' => ['', '6', '8', '10']],
        'allow-numeric-one-time-password' => ['group' => 'security', 'type' => 'bool'],
        'whitelist' => ['group' => 'security', 'type' => 'string'],
        'allow-only-conn-window-open' => ['group' => 'security', 'type' => 'bool'],
        'enable-trusted-devices' => ['group' => 'security', 'type' => 'bool'],
        'allow-remote-config-modification' => ['group' => 'security', 'type' => 'bool'],
        'allow-auto-disconnect' => ['group' => 'security', 'type' => 'bool'],
        'auto-disconnect-timeout' => ['group' => 'security', 'type' => 'int', 'min' => 1, 'max' => 1440],
        'allow-scope-violation-alarm' => ['group' => 'security', 'type' => 'bool'],
        'allow-scope-violation-close' => ['group' => 'security', 'type' => 'bool'],

        // --- capture / display (§3.4) --------------------------------------
        // Only the keys the doc marks `live` or `next session`. The `restart`
        // ones (allow-always-software-render, audio-input, voice-call-input)
        // are omitted: a strategy can write them but nothing re-reads them, so
        // exposing them would promise an effect we cannot deliver.
        'enable-abr' => ['group' => 'display', 'type' => 'bool'],
        'allow-remove-wallpaper' => ['group' => 'display', 'type' => 'bool'],
        'allow-auto-record-incoming' => ['group' => 'display', 'type' => 'bool'],
        'keep-awake-during-incoming-sessions' => ['group' => 'display', 'type' => 'bool'],
        'enable-lan-discovery' => ['group' => 'display', 'type' => 'bool'],

        // --- client maintenance -------------------------------------------
        // Not a permission, not an authorisation control, not capture — this
        // is the client updating itself, so it gets its own group rather than
        // being filed under something it is not.
        'allow-auto-update' => ['group' => 'client', 'type' => 'bool'],
    ];

    /** Group keys in the order the editor shows them (mirrors the doc's §3 order). */
    public const GROUP_ORDER = ['permissions', 'security', 'display'];

    /** Memoized "is there any enabled strategy at all?" for the current request. */
    private static ?bool $anyEnabledCache = null;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'enforce' => 'boolean',
            'options' => 'array',
            'confirmation_timeout_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Any strategy write can change what devices resolve to (enabled /
        // is_default), so drop the memo and refresh the cached column.
        static::saved(function (Strategy $strategy) {
            self::$anyEnabledCache = null;

            // NB: on an insert Eloquent has not synced changes yet, so
            // wasChanged() is false for a brand-new default — hence the
            // wasRecentlyCreated arm.
            if ($strategy->is_default && ($strategy->wasRecentlyCreated || $strategy->wasChanged('is_default'))) {
                // At most one default. Query builder, not model saves, so this
                // cannot recurse back through this hook.
                static::query()->whereKeyNot($strategy->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if ($strategy->wasRecentlyCreated || $strategy->wasChanged(['enabled', 'is_default'])) {
                static::recomputeAll();
            }
        });

        static::deleted(function (Strategy $strategy) {
            self::$anyEnabledCache = null;
            static::recomputeAll();
        });

        static::deleting(function (Strategy $strategy) {
            // Soft delete: revision history keeps a restricted foreign key to
            // the strategy, so the row stays. Clear live routing state and
            // free the name so a new strategy can reuse it; the snapshot keeps
            // the real name. Flags are cleared so a raw query (or an image
            // rolled back to a release without soft deletes) never sees a
            // deleted strategy as enabled or default.
            foreach (self::PIVOTS as [$table]) {
                DB::table($table)->where('strategy_id', $strategy->id)->delete();
            }
            $strategy->forceFill([
                'enabled' => false,
                'is_default' => false,
                'name' => $strategy->name.' (deleted '.now()->utc()->format('Y-m-d H:i:s').')',
            ])->saveQuietly();
        });
    }

    // ------------------------------------------------------------ options ---

    /**
     * Coerce an arbitrary option map into something the client will accept:
     * allowlisted keys only, every value a string, deterministic key order
     * (so two equal policies hash equal). Unknown keys and values that fail
     * their type are dropped rather than corrected — a policy that half-applies
     * is worse than one that visibly lost a row in the editor.
     *
     * @param  array<mixed,mixed>  $options
     * @return array<string,string>
     */
    public static function sanitizeOptions(array $options): array
    {
        $clean = [];

        foreach ($options as $key => $value) {
            $key = is_string($key) ? $key : (string) $key;
            $spec = self::OPTION_KEYS[$key] ?? null;
            if ($spec === null || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'Y' : 'N';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } elseif (! is_string($value)) {
                continue; // arrays/objects have no sane string form here
            }

            $value = trim($value);

            $ok = match ($spec['type']) {
                'bool' => in_array($value, ['Y', 'N', ''], true),
                'enum' => in_array($value, $spec['values'], true),
                'int' => $value === '' || (ctype_digit($value)
                    && (int) $value >= ($spec['min'] ?? 0)
                    && (int) $value <= ($spec['max'] ?? PHP_INT_MAX)),
                default => true,
            };

            if ($ok) {
                $clean[$key] = $value;
            }
        }

        ksort($clean);

        return $clean;
    }

    /**
     * The option map this strategy would push. Empty when disabled, which is
     * what makes "disable the strategy" behave like "unassign it" (the delivery
     * engine then resets the keys the device is holding).
     *
     * @return array<string,string>
     */
    public function configOptions(): array
    {
        if (! $this->enabled) {
            return [];
        }

        return $this->optionMap();
    }

    /**
     * The stored map, sanitized but NOT gated on `enabled`. The console editor
     * needs this: a disabled strategy still has options to show and edit, it
     * simply pushes none of them.
     *
     * @return array<string,string>
     */
    public function optionMap(): array
    {
        return self::sanitizeOptions(is_array($this->options) ? $this->options : []);
    }

    /** Store options already sanitized, so the DB never holds an unpushable value. */
    public function setOptions(array $options): void
    {
        $this->options = self::sanitizeOptions($options);
    }

    /** @return array{name:string,note:?string,enabled:bool,is_default:bool,enforce:bool,options:array<string,string>} */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'note' => $this->note,
            'enabled' => (bool) $this->enabled,
            'is_default' => (bool) $this->is_default,
            'enforce' => (bool) $this->enforce,
            'options' => $this->optionMap(),
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StrategyRevision::class)->orderByDesc('revision');
    }

    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(StrategyRevision::class, 'active_revision_id');
    }

    /** @return array<string,array<string,array{group:string,type:string,values?:array<int,string>,min?:int,max?:int}>> */
    public static function optionGroups(): array
    {
        $groups = array_fill_keys(self::GROUP_ORDER, []);
        foreach (self::OPTION_KEYS as $key => $spec) {
            $groups[$spec['group']][$key] = $spec;
        }

        return array_filter($groups);
    }

    // -------------------------------------------------------- assignments ---

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'device_strategy');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'strategy_user');
    }

    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_strategy');
    }

    /** Devices whose cached resolution currently points at this strategy. */
    public function resolvedDevices()
    {
        return $this->hasMany(Device::class, 'strategy_id_resolved');
    }

    /**
     * Point a target (a device, a console user, or a device group) at a
     * strategy, or clear it with $strategyId = null. Exactly one strategy per
     * target per level — assigning replaces. Recomputes every device the change
     * can reach.
     */
    public static function assignTo(string $level, int $targetId, ?int $strategyId): void
    {
        DB::transaction(function () use ($level, $targetId, $strategyId): void {
            [$table, $column] = self::pivot($level);
            static::query()->orderBy('id')->lockForUpdate()->get(['id']);
            match ($level) {
                self::LEVEL_DEVICE => Device::withTrashed()->whereKey($targetId)->lockForUpdate()->firstOrFail(),
                self::LEVEL_USER => User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail(),
                self::LEVEL_DEVICE_GROUP => DeviceGroup::query()->whereKey($targetId)->lockForUpdate()->firstOrFail(),
            };

            DB::table($table)->where($column, $targetId)->delete();
            if ($strategyId !== null) {
                DB::table($table)->insert([$column => $targetId, 'strategy_id' => $strategyId]);
            }

            self::recomputeForLevel($level, $targetId);
        });
    }

    /** The strategy id assigned at one level, ignoring precedence. */
    public static function assignedStrategyId(string $level, int $targetId): ?int
    {
        [$table, $column] = self::pivot($level);

        $id = DB::table($table)->where($column, $targetId)->value('strategy_id');

        return $id === null ? null : (int) $id;
    }

    /** How many targets are assigned to this strategy at each level. */
    public function assignmentCounts(): array
    {
        $counts = [];
        foreach (self::PIVOTS as $level => [$table]) {
            $counts[$level] = DB::table($table)->where('strategy_id', $this->getKey())->count();
        }

        return $counts;
    }

    /** @return array{0:string,1:string} */
    private static function pivot(string $level): array
    {
        return self::PIVOTS[$level] ?? throw new \InvalidArgumentException("Unknown strategy level [$level]");
    }

    // --------------------------------------------------------- resolution ---

    /**
     * Which strategy applies to a device, by PLAN C2 precedence:
     * device assignment > user assignment > device-group assignment > default.
     *
     * Disabled strategies are skipped as if they were not assigned, so the next
     * level down (ultimately the default) takes over.
     */
    public static function resolve(Device $device): ?int
    {
        if (! self::anyEnabled()) {
            return null;
        }

        $candidates = [
            [self::LEVEL_DEVICE, $device->getKey()],
            [self::LEVEL_USER, $device->user_id],
            [self::LEVEL_DEVICE_GROUP, $device->device_group_id],
        ];

        foreach ($candidates as [$level, $targetId]) {
            if ($targetId === null) {
                continue;
            }

            [$table, $column] = self::pivot($level);

            $id = DB::table($table)
                ->join('strategies', 'strategies.id', '=', $table.'.strategy_id')
                ->where($table.'.'.$column, $targetId)
                ->where('strategies.enabled', true)
                ->value('strategies.id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $default = static::query()->where('is_default', true)->where('enabled', true)->value('id');

        return $default === null ? null : (int) $default;
    }

    /**
     * The same walk resolve() does, but reported step by step so the console can
     * show an operator WHY a device ended up with the policy it has (PLAN C4's
     * "effective strategy" inspector).
     *
     * Deliberately a second implementation rather than instrumentation inside
     * resolve(): resolve() runs on the heartbeat path for every device in the
     * fleet and must stay a single value lookup, while this runs once, for one
     * device, with a modal open. The pair is pinned together by a test that
     * asserts the winning step always equals resolve().
     *
     * @return array{steps:array<int,array{level:string,label:string,target:?string,strategy:?Strategy,state:string}>,winner:?array<string,mixed>,resolved:?Strategy}
     */
    public static function explainFor(Device $device): array
    {
        $device->loadMissing(['user', 'group']);

        $candidates = [
            [self::LEVEL_DEVICE, 'This device', $device->rustdesk_id, $device->getKey()],
            [self::LEVEL_USER, 'Owner', $device->user?->username, $device->user_id],
            [self::LEVEL_DEVICE_GROUP, 'Device group', $device->group?->name, $device->device_group_id],
        ];

        $steps = [];
        $winner = null;

        foreach ($candidates as [$level, $label, $target, $targetId]) {
            $strategy = null;
            if ($targetId !== null) {
                $assigned = self::assignedStrategyId($level, (int) $targetId);
                $strategy = $assigned === null ? null : static::query()->find($assigned);
            }

            $state = match (true) {
                $targetId === null => 'unset',       // no owner / no group at all
                $strategy === null => 'none',        // nothing assigned at this level
                ! $strategy->enabled => 'disabled',  // assigned but skipped
                $winner !== null => 'overridden',    // a higher level already won
                default => 'applied',
            };

            $step = compact('level', 'label', 'target', 'strategy', 'state');
            if ($state === 'applied') {
                $winner = $step;
            }

            $steps[] = $step;
        }

        $default = static::query()->where('is_default', true)->first();
        $defaultState = match (true) {
            $default === null => 'none',
            ! $default->enabled => 'disabled',
            $winner !== null => 'overridden',
            default => 'applied',
        };

        $defaultStep = [
            'level' => 'default',
            'label' => 'Default strategy',
            'target' => null,
            'strategy' => $default,
            'state' => $defaultState,
        ];

        if ($defaultState === 'applied') {
            $winner = $defaultStep;
        }

        $steps[] = $defaultStep;

        return [
            'steps' => $steps,
            'winner' => $winner,
            // What the delivery engine will actually use: the cached column, so
            // a disagreement with $winner is visible rather than hidden.
            'resolved' => $device->strategy_id_resolved === null
                ? null
                : static::query()->find($device->strategy_id_resolved),
            // When the device last echoed our version token, i.e. the last time
            // we know it was actually holding the policy.
            'acked_at' => $device->strategy_acked_at,
        ];
    }

    /** Refresh one device's cached resolution. Quiet: never fires model events. */
    public static function syncResolvedFor(Device $device): void
    {
        $resolved = self::resolve($device);

        if ((int) $device->strategy_id_resolved !== (int) $resolved) {
            $device->strategy_id_resolved = $resolved;
            $device->saveQuietly();
        }
    }

    /** Refresh the cached resolution for every device a level change can reach. */
    public static function recomputeForLevel(string $level, int $targetId): void
    {
        $query = Device::withTrashed();

        match ($level) {
            self::LEVEL_DEVICE => $query->whereKey($targetId),
            self::LEVEL_USER => $query->where('user_id', $targetId),
            self::LEVEL_DEVICE_GROUP => $query->where('device_group_id', $targetId),
            default => throw new \InvalidArgumentException("Unknown strategy level [$level]"),
        };

        self::recomputeQuery($query);
    }

    /**
     * Rebuild the cached column for the whole fleet. Cheap enough to call from
     * any strategy write (chunked, and only writes rows that actually change),
     * and the repair for any path that mutates devices.user_id /
     * devices.device_group_id with the query builder — those bypass model
     * events and therefore bypass the incremental recompute.
     */
    public static function recomputeAll(): void
    {
        self::recomputeQuery(Device::withTrashed());
    }

    private static function recomputeQuery($query): void
    {
        $query->chunkById(500, function ($devices) {
            foreach ($devices as $device) {
                self::syncResolvedFor($device);
            }
        });
    }

    /**
     * Is there any enabled strategy at all? The fast negative that keeps
     * resolution free for installations that never create one.
     *
     * Memoized in a static, i.e. for the life of the PHP process: safe under
     * php-fpm (one request) and under the CLI, and invalidated by every
     * strategy write in this process. It would go stale in a persistent worker
     * (Octane/Swoole) if ANOTHER worker created the first strategy — the app
     * does not use one. flushCache() is the escape hatch.
     */
    public static function anyEnabled(): bool
    {
        return self::$anyEnabledCache ??= static::query()->where('enabled', true)->exists();
    }

    /** Drop the per-request memo (tests, long-running workers). */
    public static function flushCache(): void
    {
        self::$anyEnabledCache = null;
    }

    // ----------------------------------------------------------- delivery ---

    /**
     * Build the strategy half of a heartbeat response for a device, or null
     * when there is nothing to say (PLAN C3).
     *
     * Returning null is load-bearing: a device with no policy — now or ever —
     * must receive byte-for-byte what it received before strategies existed.
     *
     * $echoedVersion is the client's `modified_at` (0 when it has never latched
     * one, e.g. a fresh install; such a device gets the full policy again).
     *
     * @return array{modified_at:int,strategy:array{config_options:array<string,string>}}|null
     */
    public static function deliveryFor(Device $device, int $echoedVersion): ?array
    {
        // Quarantined devices are not under management yet (PLAN B3) — they get
        // no policy until an operator approves them.
        if ($device->status !== Device::STATUS_ACTIVE) {
            return null;
        }

        $strategy = $device->strategy_id_resolved !== null
            ? static::query()->find($device->strategy_id_resolved)
            : null;

        $desired = $strategy?->configOptions() ?? [];
        $acked = self::stringMap($device->strategy_acked_options);
        $version = $device->strategy_version === null ? null : (int) $device->strategy_version;

        // Never had a policy and still does not: say nothing at all.
        if ($desired === [] && $acked === [] && $version === null) {
            return null;
        }

        // What the device was last TOLD to hold, under the token it is echoing.
        $sent = self::stringMap($device->strategy_options);

        // Record the acknowledgement BEFORE anything can move the token.
        //
        // An ack always arrives one heartbeat after the push (§1: up to ~15s, or
        // arbitrarily later if the machine slept), so by the time it lands the
        // console may already have edited or unassigned the policy. Bumping
        // first and comparing afterwards would throw the ack away and lose the
        // only record of what the device is actually holding — precisely the map
        // the reset loop below has to clear. The echo is evidence about $sent,
        // not about the map we want now.
        if ($version !== null && $echoedVersion === $version) {
            // Refresh on a changed map, and otherwise at most once a minute.
            //
            // Under `enforce` the device re-echoes the SAME map every heartbeat,
            // so keying the write purely on "the map changed" froze
            // strategy_acked_at at the first ack — the console then showed a
            // stale "last confirmed" precisely while a policy was being most
            // actively enforced (observed against a real 1.4.9 client). Writing
            // on every echo would instead cost one UPDATE per device per
            // heartbeat, which on a large fleet is 4 writes/device/minute for
            // nothing. The staleness bound keeps the timestamp honest at a
            // fixed, small cost.
            $stale = $device->strategy_acked_at === null
                || $device->strategy_acked_at->lt(now()->subSeconds(self::ACK_REFRESH_SECONDS));

            if ($acked !== $sent || $stale) {
                $device->forceFill([
                    'strategy_acked_options' => $sent,
                    'strategy_acked_at' => now(),
                ])->saveQuietly();
            }
            $acked = $sent;
        }

        // Bump the version ONLY when this device's effective map really changed.
        // Two different strategies with identical maps are not a change; editing
        // the assigned strategy's map is.
        if ($version === null || $sent !== $desired) {
            $version = max(time(), ($version ?? 0) + 1);
            $device->forceFill([
                'strategy_version' => $version,
                'strategy_options' => $desired,
                'strategy_sent_at' => now(),
            ])->saveQuietly();
        }

        // The device echoed our current token: it is holding $desired (no bump
        // can have happened above, or the tokens would differ).
        if ($echoedVersion === $version) {
            if (! ($strategy?->enforce)) {
                return null; // up to date; let local edits stand
            }

            return $desired === []
                ? null
                : ['modified_at' => $version, 'strategy' => ['config_options' => $desired]];
        }

        // Behind: send the wanted map PLUS an empty string for every key the
        // device may be holding that we no longer want. The client has no revert
        // — "" is how a key goes back to the built-in default (protocol §2.3).
        //
        // "May be holding" is the union of what it last acknowledged and what we
        // last sent it: a key pushed under a token the device has not echoed yet
        // is applied on the device but absent from the ack, and clearing only
        // the acked half would strand it there forever.
        $wire = $desired;
        foreach ([...array_keys($acked), ...array_keys($sent)] as $key) {
            if (! array_key_exists($key, $wire)) {
                $wire[$key] = '';
            }
        }
        ksort($wire);

        if ($wire === []) {
            return null;
        }

        // Rows from before strategy_sent_at existed carry a token but no sent
        // time. Start the confirmation clock at this resend, not at whatever
        // the strategy's updated_at happens to be.
        if ($device->strategy_sent_at === null) {
            $device->forceFill(['strategy_sent_at' => now()])->saveQuietly();
        }

        return ['modified_at' => $version, 'strategy' => ['config_options' => $wire]];
    }

    /**
     * Defensive read of a JSON column: only string keys with string values
     * survive. Anything else in there could not have come from us and must not
     * reach the wire.
     *
     * @return array<string,string>
     */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }
        ksort($map);

        return $map;
    }
}
