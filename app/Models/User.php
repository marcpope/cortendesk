<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['username', 'name', 'email', 'password', 'avatar', 'is_admin', 'role_id', 'is_active', 'note', 'devices_columns', 'devices_sort', 'devices_sort_direction', 'theme', 'setup_wizard_dismissed_at', 'setup_wizard_completed_at'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Mirror the DB default so a freshly constructed (unsaved/unrefreshed)
    // model doesn't read as disabled (null) — EnsureUserIsActive depends on it.
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** Console themes a user can pick (issue #94). "system" follows the browser. */
    public const THEMES = ['dark', 'light', 'system'];

    /** The saved theme, or dark when none is saved. */
    public function themePreference(): string
    {
        return in_array($this->theme, self::THEMES, true) ? $this->theme : 'dark';
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            // App-key AES encryption at rest for the TOTP secret (PLAN B6).
            'totp_secret' => 'encrypted',
            'totp_enabled' => 'boolean',
            'totp_confirmed_at' => 'datetime',
            // Devices-screen column selection (issue #16); null = defaults.
            'devices_columns' => 'array',
            // First-run guide: either stamp means stop offering it.
            'setup_wizard_dismissed_at' => 'datetime',
            'setup_wizard_completed_at' => 'datetime',
        ];
    }

    /**
     * A deleted user takes any strategy assigned to it with it (the pivot row
     * cascades), so every device that resolved through this user must be
     * re-resolved. The console detaches the devices with a query-builder update
     * before deleting, which fires no model events — hence the full recompute
     * rather than a targeted one.
     */
    protected static function booted(): void
    {
        // A personal address book is private to one user and readable by nobody
        // else, so it has no meaning once that user is gone — it just becomes a
        // row labelled "unknown" that no permission check can ever reach.
        // Cleared here rather than in the console so it holds for every deletion
        // path, including artisan and future callers.
        //
        // Shared books are deliberately left alone: their access comes from
        // rules, not ownership, so they keep working for everyone they were
        // shared with, and an admin can still manage them.
        static::deleting(function (User $user) {
            AddressBook::where('owner_user_id', $user->id)
                ->where('is_personal', true)
                ->get()
                ->each(function (AddressBook $book) {
                    $book->entries()->delete();
                    $book->tags()->delete();
                    $book->rules()->delete();
                    $book->delete();
                });
        });

        static::deleted(fn () => Strategy::recomputeAll());
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** 2FA recovery codes (single-use, bcrypt-hashed). */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    /** Has this user completed 2FA enrollment? */
    public function hasTwoFactorEnabled(): bool
    {
        return (bool) $this->totp_enabled;
    }

    /** Unused recovery codes remaining for this user. */
    public function unusedRecoveryCodesCount(): int
    {
        return $this->recoveryCodes()->whereNull('used_at')->count();
    }

    /**
     * Wipe all 2FA state (secret, flags, replay pointer, recovery codes).
     * Used by admin "Reset 2FA", the break-glass CLI, and self-disable.
     */
    public function clearTwoFactor(): void
    {
        $this->recoveryCodes()->delete();
        $this->forceFill([
            'totp_secret' => null,
            'totp_enabled' => false,
            'totp_confirmed_at' => null,
            'totp_last_timestep' => null,
        ])->save();
    }

    /** Browsers allowed to skip the emailed sign-in code (PLAN D1). */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function clientTokens(): HasMany
    {
        return $this->hasMany(ClientToken::class);
    }

    /** Admin automation API tokens this user created. */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** User groups this user belongs to (many-to-many). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user');
    }

    /** Device groups this (non-admin) user has been granted console access to. */
    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class);
    }

    public function displayName(): string
    {
        return $this->name ?: $this->username;
    }

    /**
     * Was this account created by the identity provider (PLAN D3)?
     *
     * Such accounts hold a random unusable password and must never be able to
     * sign in with one. An account that existed locally and was later LINKED to
     * an SSO identity stays `local` and keeps its password — that is the way
     * back in when the IdP is unavailable.
     */
    public function isSsoProvisioned(): bool
    {
        return $this->auth_provider === 'oidc';
    }

    /** Is this account linked to an SSO identity (provisioned or linked)? */
    public function isSsoLinked(): bool
    {
        return $this->oidc_sub !== null && $this->oidc_sub !== '';
    }

    /**
     * End every way this account is currently authenticated.
     *
     * Used by an administrator's "force logout", and by a password reset — a
     * reset is the recovery path for a compromised account, so leaving the
     * attacker's session or client token alive would defeat it.
     */
    public function revokeAllAccess(): void
    {
        $this->clientTokens()->delete();
        DB::table('sessions')->where('user_id', $this->id)->delete();
        // Trusted browsers skip the emailed sign-in code (PLAN D1); leaving them
        // would let the same browser sign back in unchallenged.
        DB::table('trusted_devices')->where('user_id', $this->id)->delete();
        // A surviving remember-me cookie must not silently re-authenticate.
        $this->setRememberToken(Str::random(60));
        $this->save();
    }

    /** Is this account waiting for an administrator to approve its SSO sign-up? */
    public function isSsoPending(): bool
    {
        return $this->oidc_status === 'pending';
    }

    /**
     * Admins see the whole fleet; everyone else is scoped.
     *
     * PLAN D4 deliberately does NOT consult the delegated role here. Roles gate
     * console areas and verbs; row visibility stays with the device-group and
     * ownership grants, so a role can never widen the fleet a user can see.
     */
    public function seesAllDevices(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Delegated admin role (PLAN D4); null = the legacy standard-user baseline. */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The single authorisation entry point for console areas (PLAN D4).
     *
     * Super-admins pass everything. A role-holder is answered by their matrix.
     * A user with no role falls back to Permissions::LEGACY_USER, which encodes
     * exactly what a non-admin could do before roles existed — that fallback is
     * why an install with no roles behaves identically after this change.
     *
     * This answers "may you use this screen/verb", never "which rows do you
     * see" — see seesAllDevices() and Device::scopeVisibleTo for that axis.
     */
    public function consoleAllows(string $resource, string $level = 'r'): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->role
            ? $this->role->allows($resource, $level)
            : Permissions::satisfies(Permissions::LEGACY_USER[$resource] ?? 'none', $level);
    }

    /** Effective level for a console area, used to clamp what this user can hand out. */
    public function consoleLevel(string $resource): string
    {
        if ($this->is_admin) {
            return 'rw';
        }

        return $this->role
            ? $this->role->levelFor($resource)
            : (Permissions::LEGACY_USER[$resource] ?? 'none');
    }

    /** User-group ids this user belongs to. @return array<int,int> */
    public function userGroupIds(): array
    {
        return $this->groups()->pluck('user_groups.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Device-group ids this user may see: the UNION of their per-user grants
     * (device_group_user), the grants of every user group they belong to
     * (device_group_user_group), and any device-group "accessed from" grant in
     * group_accesses whose accessor is this user or one of their user groups
     * (PLAN B4).
     *
     * @return array<int,int>
     */
    public function accessibleDeviceGroupIds(): array
    {
        $direct = $this->deviceGroups()->pluck('device_groups.id');

        $viaGroups = DB::table('device_group_user_group')
            ->join('user_group_user', 'user_group_user.user_group_id', '=', 'device_group_user_group.user_group_id')
            ->where('user_group_user.user_id', $this->id)
            ->pluck('device_group_user_group.device_group_id');

        $viaAccess = $this->groupAccessTargetIds(GroupAccess::TARGET_DEVICE_GROUP);

        return $direct->merge($viaGroups)->merge($viaAccess)
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * User ids visible to this user in group-mate contexts (/api/users): self,
     * plus the members of every user group they belong to, plus the members of
     * any user group "accessed from" this user or one of their groups via
     * group_accesses (PLAN B4). Device visibility deliberately stays folder-based
     * (Device::scopeVisibleTo) — being a group-mate never exposes a peer's
     * personally-owned devices.
     *
     * @return array<int,int>
     */
    public function visibleUserIds(): array
    {
        $ownGroupIds = $this->userGroupIds();

        $accessedGroupIds = $this->groupAccessTargetIds(GroupAccess::TARGET_USER_GROUP)
            ->map(fn ($id) => (int) $id)->all();

        $groupIds = array_values(array_unique([...$ownGroupIds, ...$accessedGroupIds]));

        $ids = [$this->id];
        if ($groupIds !== []) {
            $ids = array_merge($ids, DB::table('user_group_user')
                ->whereIn('user_group_id', $groupIds)
                ->pluck('user_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Target ids of the given type granted to this user via group_accesses,
     * where the accessor is this user directly or any user group they belong to.
     *
     * @return Collection<int,int>
     */
    protected function groupAccessTargetIds(string $targetType)
    {
        $groupIds = $this->userGroupIds();

        return GroupAccess::query()
            ->where('target_type', $targetType)
            ->where(function ($q) use ($groupIds) {
                $q->where(fn ($q) => $q
                    ->where('accessor_type', GroupAccess::ACCESSOR_USER)
                    ->where('accessor_id', $this->id));
                if ($groupIds !== []) {
                    $q->orWhere(fn ($q) => $q
                        ->where('accessor_type', GroupAccess::ACCESSOR_USER_GROUP)
                        ->whereIn('accessor_id', $groupIds));
                }
            })
            ->pluck('target_id');
    }
}
