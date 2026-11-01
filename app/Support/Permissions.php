<?php

namespace App\Support;

/**
 * The single permission vocabulary shared by API tokens (PLAN B1) and
 * delegated console roles (PLAN D4).
 *
 * Levels are stored as `none|r|rw` — the same three strings the api_tokens
 * matrix has always used — and are ordered by increasing capability, so a
 * required level is satisfied by anything ranked at least as high. The console
 * UI *labels* them None / View / Manage; the strings 'view' and 'manage' are
 * never stored.
 *
 * Console roles cover two areas the automation API has no endpoints for
 * (`setting`, `token`), which is why CONSOLE_RESOURCES is a superset of
 * ApiToken::RESOURCES rather than the same list. Widening the API matrix would
 * invent permissions no route reads.
 */
final class Permissions
{
    /** Permission levels, ordered by increasing capability. */
    public const LEVELS = ['none', 'r', 'rw'];

    /** How the console renders each level. */
    public const LEVEL_LABELS = ['none' => 'None', 'r' => 'View', 'rw' => 'Manage'];

    /** Console areas a role can grant. Superset of ApiToken::RESOURCES. */
    public const CONSOLE_RESOURCES = [
        'device', 'user', 'group', 'address_book', 'audit', 'strategy', 'setting', 'token',
    ];

    /** @return array<string,string> level => translated label */
    public static function levelLabels(): array
    {
        return self::translated('level', self::LEVEL_LABELS);
    }

    /** @return array<string,string> */
    public static function resourceLabels(): array
    {
        return self::translated('resource', self::RESOURCE_LABELS);
    }

    /** @return array<string,string> */
    public static function resourceHints(): array
    {
        return self::translated('resource_hint', self::RESOURCE_HINTS);
    }

    /** @param array<string,string> $labels */
    private static function translated(string $group, array $labels): array
    {
        $out = [];
        foreach ($labels as $code => $text) {
            $out[$code] = Label::get($group, $code, $text);
        }

        return $out;
    }

    public const RESOURCE_LABELS = [
        'device' => 'Devices',
        'user' => 'Users',
        'group' => 'Groups',
        'address_book' => 'Address books',
        'audit' => 'Logs',
        'strategy' => 'Strategies',
        'setting' => 'Settings',
        'token' => 'API tokens',
    ];

    /** One-line explanation of what each area actually unlocks, for the editor. */
    public const RESOURCE_HINTS = [
        'device' => 'View grants the Devices screen; Manage adds edit, approve, delete and restore. Which devices are listed is still decided by device-group access, never by the role.',
        'user' => 'Manage covers creating, editing, disabling and deleting users, and inviting new ones. Only a full administrator can grant administrator or assign roles.',
        'group' => 'Covers both device groups and user groups, matching the API token matrix.',
        'address_book' => 'View grants the Address Books screen; Manage adds books, tags, entries and sharing rules. Per-book sharing rules still apply on top.',
        'audit' => 'View grants Connections, File Transfers and Alarms. Manage also grants Login history and the Console audit trail.',
        'strategy' => 'Client strategies pushed to devices over the heartbeat.',
        'setting' => 'Server settings, SSO, SMTP, retention and the sidebar version banner.',
        'token' => 'Tokens for the automation REST API. A new token can never be granted more than its creator already has.',
    ];

    /**
     * The capability a user with no role has always had, and still has: full
     * self-service over the devices and address books already visible to them,
     * read access to the operational logs, and nothing else. `users.role_id IS
     * NULL` resolves here, which is what makes D4 a no-op for existing installs.
     */
    public const LEGACY_USER = [
        'device' => 'rw',
        'user' => 'none',
        'group' => 'none',
        'address_book' => 'rw',
        'audit' => 'r',
        'strategy' => 'none',
        'setting' => 'none',
        'token' => 'none',
    ];

    public static function rank(string $level): int
    {
        return match ($level) {
            'rw' => 2,
            'r' => 1,
            default => 0,
        };
    }

    /** Does `$have` cover `$required`? rw covers r and rw; r covers only r. */
    public static function satisfies(string $have, string $required): bool
    {
        return self::rank($have) >= self::rank($required);
    }

    /**
     * Fill in every known resource (missing => 'none') and drop unknown keys,
     * so a matrix written before a resource existed fails closed rather than
     * inheriting whatever the caller happened to post.
     *
     * @param  array<string,mixed>  $matrix
     * @param  array<int,string>  $resources
     * @return array<string,string>
     */
    public static function normalize(array $matrix, array $resources): array
    {
        $out = [];
        foreach ($resources as $resource) {
            $level = $matrix[$resource] ?? 'none';
            $out[$resource] = in_array($level, self::LEVELS, true) ? $level : 'none';
        }

        return $out;
    }
}
