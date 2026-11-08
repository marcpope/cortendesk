<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Compares the running version (the VERSION file, via config) against the
 * VERSION file on GitHub's default branch. A pre-release install (a beta)
 * also reads the beta branch, so testers hear about the next beta; stable
 * installs never see betas, which live only on that branch. Cached so a page
 * load never waits on the network more than once every few hours.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'cortendesk.latest_version';

    private const VERSION_URL = 'https://raw.githubusercontent.com/marcpope/cortendesk/main/VERSION';

    private const BETA_VERSION_URL = 'https://raw.githubusercontent.com/marcpope/cortendesk/beta/VERSION';

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

        $value = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => self::fetch());

        return $value ?: null;
    }

    /**
     * The highest VERSION published on main, plus the beta branch when this
     * install runs a pre-release. "" when nothing could be read.
     */
    public static function fetch(): string
    {
        $urls = [self::VERSION_URL];
        if (self::isPrerelease((string) config('cortendesk.api_version'))) {
            $urls[] = self::BETA_VERSION_URL;
        }

        $found = '';
        foreach ($urls as $url) {
            try {
                $resp = Http::timeout(4)->get($url);
                $version = $resp->ok() ? trim($resp->body()) : '';
            } catch (\Throwable) {
                $version = ''; // cache the failure briefly; avoids hammering GitHub
            }
            if ($version !== '' && ($found === '' || version_compare($version, $found, '>'))) {
                $found = $version;
            }
        }

        return $found;
    }

    /** "2.0.0-beta.1" is a pre-release; "1.23.0" is not. */
    public static function isPrerelease(string $version): bool
    {
        return str_contains(trim($version), '-');
    }
}
