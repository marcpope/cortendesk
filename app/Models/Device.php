<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use SoftDeletes;

    /** Approval states for the deployment gate (PLAN B3). */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    /** Fallback seconds without a heartbeat before a device counts as offline. */
    public const ONLINE_WINDOW = 60;

    private static ?int $onlineWindowCache = null;

    /**
     * Effective online window: settings table → config → constant. Memoized
     * per process so per-row isOnline() calls in a list don't each query the
     * settings table; Setting::put() flushes it, which is what lets the
     * long-lived scheduler (`schedule:work` inside the container) see a
     * changed setting without a restart.
     */
    public static function onlineWindow(): int
    {
        return self::$onlineWindowCache ??= (int) (
            Setting::get('online_window', (string) config('cortendesk.online_window', self::ONLINE_WINDOW)) ?: self::ONLINE_WINDOW
        );
    }

    public static function flushOnlineWindowCache(): void
    {
        self::$onlineWindowCache = null;
    }

    protected $fillable = [
        'rustdesk_id',
        'uuid',
        'status',
        'hostname',
        'os',
        'cpu',
        'memory',
        'username',
        'version',
        'alias',
        'note',
        'user_id',
        'device_group_id',
        'last_online_at',
        'last_online_ip',
        'registered_ip',
        'can_initiate',
    ];

    protected function casts(): array
    {
        return [
            'last_online_at' => 'datetime',
            'can_initiate' => 'boolean',
            'lan_ip_seen_at' => 'datetime',
            'strategy_options' => 'array',
            'strategy_acked_options' => 'array',
            'strategy_acked_at' => 'datetime',
            'strategy_sent_at' => 'datetime',
        ];
    }

    /**
     * Keep the cached strategy resolution (PLAN C2) honest: it depends on the
     * device's own assignment, its owner and its group, so any change to the
     * latter two invalidates it. saveQuietly() writers (the heartbeat presence
     * update) deliberately do not trigger this — they never touch either column.
     */
    protected static function booted(): void
    {
        static::created(fn (Device $device) => Strategy::syncResolvedFor($device));

        static::updated(function (Device $device) {
            if ($device->wasChanged(['user_id', 'device_group_id'])) {
                Strategy::syncResolvedFor($device);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    /** The strategy currently in force for this device (cached resolution). */
    public function resolvedStrategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id_resolved');
    }

    /** The strategy assigned directly to this device, ignoring precedence. */
    public function assignedStrategyId(): ?int
    {
        return Strategy::assignedStrategyId(Strategy::LEVEL_DEVICE, (int) $this->getKey());
    }

    /** Whether the deployment approval gate is currently enabled (PLAN B3). */
    public static function approvalGateEnabled(): bool
    {
        return (bool) Setting::get('require_device_approval', '0');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Incoming only (issue #82): the ID server refuses sessions this device
     * starts. Unset, as on a model not reloaded since create(), means allowed:
     * the column defaults to true.
     */
    public function isIncomingOnly(): bool
    {
        return $this->can_initiate === false;
    }

    /** Approved (visible) devices only. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Devices quarantined by the deployment gate, awaiting approval. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isOnline(): bool
    {
        return $this->last_online_at !== null
            && $this->last_online_at->gt(now()->subSeconds(self::onlineWindow()));
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_online_at', '>', now()->subSeconds(self::onlineWindow()));
    }

    public function scopeOffline(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('last_online_at')
                ->orWhere('last_online_at', '<=', now()->subSeconds(self::onlineWindow()));
        });
    }

    /**
     * Restrict to devices a console user may see. Admins: everything.
     * Non-admins: devices in a granted device group OR that they own.
     * This is the single source of truth for device visibility, and it also
     * hides gate-quarantined (pending) devices everywhere it is used — the
     * device list, group tab, address book, dashboards and stats. Pending
     * devices only surface through scopeOwnershipVisibleTo() + scopePending()
     * on the Devices "Pending" tab.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->ownershipVisibleTo($user)->approved();
    }

    /**
     * The ownership/group half of visibility, WITHOUT the approval filter.
     * Use directly only for the pending-approval queue; everywhere else wants
     * scopeVisibleTo so quarantined devices stay hidden.
     */
    public function scopeOwnershipVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllDevices()) {
            return $query;
        }

        $groupIds = $user->accessibleDeviceGroupIds();

        return $query->where(function (Builder $q) use ($groupIds, $user) {
            $q->where('user_id', $user->id);
            if ($groupIds !== []) {
                $q->orWhereIn('device_group_id', $groupIds);
            }
        });
    }

    /**
     * RustDesk-style platform name ("Windows", "Mac OS") — the form clients
     * sync into address books and match their own icons against. Address-book
     * entries must store this, not platform()'s console-internal slug.
     */
    public function rustdeskPlatform(): string
    {
        return match ($this->platform()) {
            'windows' => 'Windows',
            'macos' => 'Mac OS',
            'linux' => 'Linux',
            'android' => 'Android',
            'ios' => 'iOS',
            default => '',
        };
    }

    /**
     * The OS string without its "family / " prefix — clients report
     * "windows / Windows 10 Pro - 10 (19045)", and next to a platform icon the
     * first half says nothing the icon does not. Falls back to the raw value
     * when the shape is unexpected; exports keep the raw value either way.
     */
    public function osDescription(): string
    {
        $os = (string) $this->os;
        $parts = explode(' / ', $os, 2);

        return isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $os;
    }

    /** Platform slug used to pick an OS icon: windows, macos, linux, android, ios. */
    public function platform(): string
    {
        $os = strtolower($this->os ?? '');

        return match (true) {
            str_contains($os, 'windows') => 'windows',
            str_contains($os, 'mac') || str_contains($os, 'darwin') => 'macos',
            str_contains($os, 'android') => 'android',
            str_contains($os, 'ios') => 'ios',
            str_contains($os, 'linux') || str_contains($os, 'ubuntu')
                || str_contains($os, 'debian') || str_contains($os, 'fedora')
                || str_contains($os, 'arch') || str_contains($os, 'centos') => 'linux',
            default => 'unknown',
        };
    }
}
