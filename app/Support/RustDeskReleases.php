<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Stable RustDesk client releases from GitHub, with each installer's sha256
 * (GitHub's asset digest). The agent downloads from GitHub itself and checks
 * the hash; this only tells it which release and which hash.
 */
final class RustDeskReleases
{
    public const CACHE_KEY = 'cortendesk.rustdesk_releases';

    private const URL = 'https://api.github.com/repos/rustdesk/rustdesk/releases?per_page=20';

    /** Installer names the agent accepts, per OS (see the agent's asset choice). */
    private const PATTERNS = [
        'windows' => '/^rustdesk-%s-%s\.(exe|msi)$/',
        'darwin' => '/^rustdesk-%s-%s\.dmg$/',
        'linux' => '/^rustdesk-%s-(?:0\.)?%s(?:-suse)?\.(deb|rpm)$/',
    ];

    /**
     * Newest first. Empty when GitHub can't be reached.
     *
     * @return array<string, array<string, string>> version => [asset name => sha256]
     */
    public static function all(): array
    {
        if (app()->environment('testing')) {
            return (array) Cache::get(self::CACHE_KEY, []);
        }

        return (array) Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            try {
                $resp = Http::timeout(8)->accept('application/vnd.github+json')->get(self::URL);

                return $resp->ok() ? self::parse((array) $resp->json()) : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** @return array<string, array<string, string>> */
    public static function parse(array $releases): array
    {
        $out = [];
        foreach ($releases as $release) {
            $tag = (string) ($release['tag_name'] ?? '');
            if (($release['prerelease'] ?? true) || ($release['draft'] ?? true) || ! preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $tag)) {
                continue;
            }
            $assets = [];
            foreach ((array) ($release['assets'] ?? []) as $asset) {
                $digest = (string) ($asset['digest'] ?? '');
                if (preg_match('/^sha256:([0-9a-f]{64})$/', $digest, $m)) {
                    $assets[(string) $asset['name']] = $m[1];
                }
            }
            if ($assets) {
                $out[$tag] = $assets;
            }
        }
        uksort($out, fn ($a, $b) => version_compare($b, $a));

        return $out;
    }

    public static function latest(): ?string
    {
        return array_key_first(self::all());
    }

    /** @return list<string> */
    public static function versions(): array
    {
        return array_keys(self::all());
    }

    /**
     * The installers in a release that fit an agent's OS and CPU, for the
     * rustdesk.upgrade params.
     *
     * @return list<array{name: string, sha256: string}>
     */
    public static function assetsFor(string $version, ?string $os, ?string $arch): array
    {
        $rdArch = ['amd64' => 'x86_64', 'arm64' => 'aarch64'][$arch] ?? null;
        $pattern = self::PATTERNS[$os] ?? null;
        if (! $rdArch || ! $pattern) {
            return [];
        }
        $regex = sprintf($pattern, preg_quote($version, '/'), $rdArch);

        $out = [];
        foreach (self::all()[$version] ?? [] as $name => $sha) {
            if (preg_match($regex, $name)) {
                $out[] = ['name' => $name, 'sha256' => $sha];
            }
        }

        return $out;
    }
}
