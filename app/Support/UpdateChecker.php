<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Compares the running version (the VERSION file, via config) against the
 * VERSION file on GitHub's default branch. Cached so a page load never waits
 * on the network more than once every few hours.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'cortendesk.latest_version';

    private const VERSION_URL = 'https://raw.githubusercontent.com/marcpope/cortendesk/main/VERSION';

    /** Release page for a version: what changed, plus the pull lines. */
    public static function releaseNotesUrl(string $version): string
    {
        return 'https://github.com/marcpope/cortendesk/releases/tag/v'.$version;
    }

    /** False when CORTENDESK_UPDATE_CHECK turns the check off. */
    public static function enabled(): bool
    {
        return (bool) config('cortendesk.update_check', true);
    }

    /** The newer version string if an upgrade is available, else null. */
    public static function upgradeAvailable(): ?string
    {
        $current = trim((string) config('cortendesk.api_version'));
        $latest = self::latestVersion();

        if (! $current || ! $latest) {
            return null;
        }

        return version_compare($latest, $current, '>') ? $latest : null;
    }

    /** Latest published version, cached ~6h. Empty string means "check failed". */
    public static function latestVersion(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        // Never reach out during the test suite.
        if (app()->environment('testing')) {
            return Cache::get(self::CACHE_KEY) ?: null;
        }

        $value = Cache::remember(self::CACHE_KEY, now()->addHours(6), function () {
            try {
                $resp = Http::timeout(4)->get(self::VERSION_URL);

                return $resp->ok() ? trim($resp->body()) : '';
            } catch (\Throwable) {
                return ''; // cache the failure briefly; avoids hammering GitHub
            }
        });

        return $value ?: null;
    }
}
