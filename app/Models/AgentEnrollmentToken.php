<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A token an agent presents once, on install, to enroll (docs/monitoring).
 * Lookups use the hash; the plain value is kept encrypted so the console can
 * show the install command again.
 */
#[Fillable(['name', 'token_hash', 'token_encrypted', 'token_prefix', 'device_group_id', 'created_by', 'expires_at', 'max_uses', 'uses', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash', 'token_encrypted'])]
class AgentEnrollmentToken extends Model
{
    public const PREFIX = 'cde_';

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'enrollment_token_id');
    }

    /** @return array{0: self, 1: string} the row and the plain token */
    public static function issue(string $name, ?int $groupId, ?User $by, ?\DateTimeInterface $expiresAt = null, ?int $maxUses = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_encrypted' => $plain,
            'token_prefix' => substr($plain, 0, 12),
            'device_group_id' => $groupId,
            'created_by' => $by?->id,
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
        ]);

        return [$token, $plain];
    }

    /** The plain token, or null for a token made before it was kept. */
    public function plain(): ?string
    {
        return $this->token_encrypted;
    }

    /** Usable tokens the user may hand out, newest first. */
    public static function usableFor(User $user): Collection
    {
        return static::query()
            ->whereNull('revoked_at')
            ->whereNotNull('token_encrypted')
            ->when(! $user->seesAllDevices(), fn ($q) => $q->whereIn('device_group_id', $user->accessibleDeviceGroupIds() ?: [0]))
            ->with('group:id,name')
            ->latest('id')
            ->get()
            ->filter->isUsable()
            ->values();
    }

    public static function findUsable(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = static::query()->where('token_hash', hash('sha256', $plain))->first();

        return $token?->isUsable() ? $token : null;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses);
    }

    /** 'active', 'revoked', 'expired' or 'used'. */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            $this->max_uses !== null && $this->uses >= $this->max_uses => 'used',
            default => 'active',
        };
    }
}
