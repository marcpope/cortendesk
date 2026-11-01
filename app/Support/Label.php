<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Translated names for things the code identifies by a code rather than a
 * sentence: alarm types, audit actions, strategy options and the like.
 *
 * Those names live in constants and models that also feed the API and CSV
 * exports, so the constants stay English; the screens look the name up here
 * and fall back to the English one when a language has no entry. Keys are
 * lang/<locale>/labels.php: "<group>_<code>" with the code reduced to a-z0-9_.
 */
final class Label
{
    /** The labels.php key for a group and code. */
    public static function key(string $group, string|int $code): string
    {
        return 'labels.'.$group.'_'.self::slug((string) $code);
    }

    /** The translated name for a code, or $fallback when there is none. */
    public static function get(string $group, string|int $code, string $fallback, array $replace = []): string
    {
        $key = self::key($group, $code);

        return Lang::has($key) ? __($key, $replace) : $fallback;
    }

    /**
     * Translate a fixed English sentence that a service produces, keyed by the
     * sentence itself (labels.t_<slug>). Used for the handful of status notes
     * shown on the diagnostics and settings screens, so the service's own
     * output (also exported as JSON) stays English.
     */
    public static function text(string $english): string
    {
        $key = 'labels.t_'.substr(self::slug($english), 0, 60);

        return Lang::has($key) ? __($key) : $english;
    }

    private static function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }
}
