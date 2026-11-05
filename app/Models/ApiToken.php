<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Scoped bearer token for the admin automation REST API (routes/api.php,
 * "admin-api" group). The plaintext is shown to the operator exactly once at
 * creation; only its sha256 is stored. Per-resource permissions gate every
 * route via the `api-token-can:<resource>,<level>` middleware.
 */
#[Fillable(['name', 'token_hash', 'token_prefix', 'user_id', 'permissions', 'address_book_add', 'last_used_at', 'expires_at', 'is_active'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /**
     * Resources the permission matrix covers. Deliberately narrower than
     * App\Support\Permissions::CONSOLE_RESOURCES — the automation API has no
     * settings or token endpoints, so granting those here would be inert.
     */
    public const RESOURCES = ['device', 'user', 'group', 'strategy', 'address_book', 'audit'];

    /** Permission levels, ordered by increasing capability. */
    public const LEVELS = Permissions::LEVELS;

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'address_book_add' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a token and return [model, plaintext]. The plaintext is never
     * persisted — surface it to the operator once, then discard.
     *
     * @param  array<string,string>  $permissions
     * @param  list<int>  $addBookIds  shared address books it may only add entries to (#151)
     * @return array{0: self, 1: string}
     */
    public static function issue(User $creator, string $name, array $permissions, ?\DateTimeInterface $expiresAt = null, array $addBookIds = []): array
    {
        $plain = 'cdk_'.Str::random(40);

        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
            'user_id' => $creator->id,
            'permissions' => static::clampToCreator($creator, $permissions),
            'address_book_add' => static::clampAddBooks($creator, $addBookIds) ?: null,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return [$token, $plain];
    }

    /** Resolve a plaintext bearer to a valid, active token (or null). */
    public static function findValid(string $plaintext): ?self
    {
        $token = static::query()->where('token_hash', hash('sha256', $plaintext))->first();

        return $token && $token->isValid() ? $token : null;
    }

    /** Fill in any missing resources as "none" and drop unknown keys. */
    public static function normalizePermissions(array $permissions): array
    {
        return Permissions::normalize($permissions, self::RESOURCES);
    }

    /**
     * Clamp a requested matrix to what the creator actually holds (PLAN D4).
     *
     * An admin-API token carries the power of its owner, so without this a
     * delegated admin with `token: rw` could mint a token granting themselves
     * areas their own role denies. For a super-admin every level clamps to
     * itself, so this is a no-op on today's installs.
     *
     * @param  array<string,mixed>  $requested
     * @return array<string,string>
     */
    public static function clampToCreator(User $creator, array $requested): array
    {
        $out = static::normalizePermissions($requested);

        foreach ($out as $resource => $level) {
            $ceiling = $creator->consoleLevel($resource);
            if (Permissions::rank($level) > Permissions::rank($ceiling)) {
                $out[$resource] = $ceiling;
            }
        }

        return $out;
    }

    /**
     * The add-only grant (#151), kept to shared books that exist, and only
     * for a creator who may manage address books.
     *
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    public static function clampAddBooks(User $creator, array $ids): array
    {
        if ($ids === [] || ! $creator->consoleAllows('address_book', 'rw')) {
            return [];
        }

        return AddressBook::query()->where('is_personal', false)
            ->whereIn('id', array_map('intval', $ids))->orderBy('id')->pluck('id')->all();
    }

    /** May this token add entries to $bookId through its add-only grant? */
    public function mayAddTo(int $bookId): bool
    {
        return in_array($bookId, array_map('intval', $this->address_book_add ?? []), true);
    }

    public function isValid(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Configured level for a resource (none|r|rw). */
    public function levelFor(string $resource): string
    {
        return $this->permissions[$resource] ?? 'none';
    }

    /**
     * Does this token satisfy the required level for a resource?
     * rw satisfies both r and rw; r satisfies only r.
     */
    public function allows(string $resource, string $required): bool
    {
        return Permissions::satisfies($this->levelFor($resource), $required);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
