<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A share link for one device (docs/share-links.md).
 *
 * The link token is 32 random bytes, hex encoded, shown once at creation and
 * stored only as its sha256. A share is usable while it is not revoked, not
 * expired, its device still exists, and its creator is still active and still
 * allowed to manage that device.
 */
class DeviceShare extends Model
{
    /** Lifetime choices, in minutes. */
    public const DURATIONS = ['15m' => 15, '1h' => 60, '8h' => 480, '24h' => 1440, '7d' => 10080];

    public const MAX_VIEWERS = 10;

    protected $fillable = [
        'device_id', 'user_id', 'label', 'token_hash', 'max_viewers',
        'expires_at', 'revoked_at', 'last_used_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'max_viewers' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DeviceShareSession::class);
    }

    /** @return array{token: string, hash: string} */
    public static function newToken(): array
    {
        $token = bin2hex(random_bytes(32));

        return ['token' => $token, 'hash' => self::hashToken($token)];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function validTokenFormat(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }

    /** The usable share for a link token, or null. */
    public static function findUsableByToken(string $token): ?self
    {
        if (! self::validTokenFormat($token)) {
            return null;
        }
        $share = self::query()->with(['device', 'user'])->where('token_hash', self::hashToken($token))->first();

        return $share && $share->isUsable() ? $share : null;
    }

    public function scopeNotRevokedOrExpired(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null || $this->isExpired()) {
            return false;
        }
        $device = $this->device;
        $owner = $this->user;
        if (! $device || $device->trashed() || ! $owner || ! $owner->is_active) {
            return false;
        }
        if (! $owner->consoleAllows('device', 'rw')) {
            return false;
        }

        return Device::query()->visibleTo($owner)->whereKey($device->getKey())->exists();
    }

    public function liveViewers(): int
    {
        return $this->sessions()->live()->count();
    }
}
