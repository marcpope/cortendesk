<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DevicePresenceNotificationState extends Model
{
    public const CLAIM_TIMEOUT_SECONDS = 300;

    protected $fillable = ['device_id', 'offline_notified_at', 'offline_claim_token', 'offline_claimed_at'];

    protected function casts(): array
    {
        return [
            'offline_notified_at' => 'datetime',
            'offline_claimed_at' => 'datetime',
        ];
    }

    /**
     * Atomically claim a bounded legacy cache marker for either conversion or
     * recovery. Null means another contender owns the short-lived claim; false
     * means no marker exists. The owner must consume before acting.
     */
    public static function claimLegacyMarkerFor(Device $device): string|false|null
    {
        $token = (string) Str::uuid();
        $lock = Cache::lock(static::legacyClaimKey($device), self::CLAIM_TIMEOUT_SECONDS, $token);

        if (! $lock->get()) {
            return null;
        }

        if (! Cache::has(static::legacyMarkerKey($device))) {
            $lock->release();

            return false;
        }

        return $token;
    }

    /**
     * Consume a legacy marker only while this token still owns its claim.
     *
     * A process dying before this pull leaves the original marker recoverable
     * when its lock expires. Once pulled, recovery is deliberately at-most-once.
     */
    public static function consumeClaimedLegacyMarkerFor(Device $device, string $token): bool
    {
        $lock = Cache::lock(static::legacyClaimKey($device), self::CLAIM_TIMEOUT_SECONDS, $token);

        if (! $lock->isOwnedByCurrentProcess()) {
            return false;
        }

        try {
            return Cache::pull(static::legacyMarkerKey($device)) !== null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Consume the marker and materialize its delivered state before releasing
     * the shared claim, so recovery cannot delete state between those steps.
     */
    public static function convertClaimedLegacyMarkerFor(Device $device, string $token): bool
    {
        $lock = Cache::lock(static::legacyClaimKey($device), self::CLAIM_TIMEOUT_SECONDS, $token);

        if (! $lock->isOwnedByCurrentProcess()) {
            return false;
        }

        try {
            if (Cache::pull(static::legacyMarkerKey($device)) === null) {
                return false;
            }

            static::recordLegacyOffline($device);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Preserve a delivered offline marker from the legacy 30-day cache key. */
    public static function recordLegacyOffline(Device $device): void
    {
        $now = now();
        static::query()->insertOrIgnore([
            'device_id' => $device->id,
            'offline_notified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Record an offline alert that was delivered outside the claim flow (a
     * retry). Converts a leftover pending claim rather than ignoring it.
     */
    public static function recordDeliveredOffline(Device $device): void
    {
        static::recordLegacyOffline($device);
        static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->update([
                'offline_notified_at' => now(),
                'offline_claim_token' => null,
                'offline_claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Atomically acquire the right to attempt one offline delivery.
     *
     * The unique device row is inserted pending before transport starts. A dead
     * worker's pending row is reclaimable after a short bounded timeout.
     */
    public static function claimOfflineFor(Device $device): ?string
    {
        $token = (string) Str::uuid();
        $now = now();

        if (static::query()->insertOrIgnore([
            'device_id' => $device->id,
            'offline_claim_token' => $token,
            'offline_claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1) {
            return $token;
        }

        $reclaimed = static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claimed_at', '<=', $now->copy()->subSeconds(self::CLAIM_TIMEOUT_SECONDS))
            ->update([
                'offline_claim_token' => $token,
                'offline_claimed_at' => $now,
                'updated_at' => $now,
            ]);

        return $reclaimed === 1 ? $token : null;
    }

    /** Mark delivery only when this contender still owns the pending claim. */
    public static function markDelivered(Device $device, string $token): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claim_token', $token)
            ->update([
                'offline_notified_at' => now(),
                'offline_claim_token' => null,
                'offline_claimed_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /** Release a failed, disabled, scoped, or cooldown-suppressed owned claim. */
    public static function releaseClaim(Device $device, string $token): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNull('offline_notified_at')
            ->where('offline_claim_token', $token)
            ->delete() === 1;
    }

    /**
     * Atomically consume the single outstanding recovery marker for a device.
     * Consumption is at-most-once so a second consumer never sends a
     * duplicate. A failed transport is retried from its delivery row.
     */
    /** Unlocked existence check — the cheap gate in front of the claim dance. */
    public static function hasAnyRecoverableStateFor(Device $device): bool
    {
        return Cache::has(static::legacyMarkerKey($device))
            || static::query()
                ->where('device_id', $device->id)
                ->whereNotNull('offline_notified_at')
                ->exists();
    }

    public static function consumeFor(Device $device): bool
    {
        return static::query()
            ->where('device_id', $device->id)
            ->whereNotNull('offline_notified_at')
            ->delete() === 1;
    }

    private static function legacyClaimKey(Device $device): string
    {
        return 'apprise:device-offline-claim:'.sha1($device->rustdesk_id);
    }

    private static function legacyMarkerKey(Device $device): string
    {
        return 'apprise:device-offline:'.sha1($device->rustdesk_id);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
