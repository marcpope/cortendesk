<?php

namespace App\Support;

/**
 * Install commands for the CortenDesk Agent, filled in with this console's
 * address. The token is an argument, never part of a URL, so it stays out of
 * web server access logs.
 */
final class AgentInstall
{
    /** @return array{version: string, base_url: string, files: array} */
    public static function manifest(): array
    {
        static $manifests = [];

        $path = config('cortendesk.agent_manifest') ?: resource_path('agent/manifest.json');

        return $manifests[$path] ??= (array) json_decode((string) @file_get_contents($path), true);
    }

    /** The agent version this console installs. */
    public static function version(): string
    {
        return (string) (self::manifest()['version'] ?? '');
    }

    /** @return array<string, array{url: string, sha256: string, signature: string}> "<os>-<arch>" => download */
    public static function files(): array
    {
        $manifest = self::manifest();
        $base = rtrim((string) ($manifest['base_url'] ?? ''), '/');
        $out = [];
        foreach ((array) ($manifest['files'] ?? []) as $key => $file) {
            $out[$key] = [
                'url' => $base.'/'.($file['name'] ?? ''),
                'sha256' => strtolower((string) ($file['sha256'] ?? '')),
                'signature' => (string) ($file['signature'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The self-update for a platform key ("linux-amd64"), when the manifest
     * has a hashed and signed build for it.
     *
     * @return array{version: string, url: string, sha256: string, signature: string}|null
     */
    public static function updateFor(?string $platform): ?array
    {
        $file = self::files()[$platform] ?? null;
        if (! $file || strlen($file['sha256']) !== 64 || $file['signature'] === '' || self::version() === '') {
            return null;
        }

        return ['version' => self::version()] + $file;
    }

    /** What agents connect to: the Monitoring setting, else this console. */
    public static function serverUrl(): string
    {
        return Monitoring::agentUrl() ?: rtrim(url('/'), '/');
    }

    /** @return array<string, string> os => command */
    public static function commands(string $token): array
    {
        $server = self::serverUrl();

        return [
            'windows' => "& ([scriptblock]::Create((irm {$server}/agent/v1/install.ps1))) -Token '{$token}'",
            'macos' => "curl -fsSL {$server}/agent/v1/install.sh | sudo sh -s -- --token '{$token}'",
            'linux' => "curl -fsSL {$server}/agent/v1/install.sh | sudo sh -s -- --token '{$token}'",
        ];
    }

    /**
     * Downloads per OS for a manual install, from the manifest.
     *
     * @return array<string, list<array{arch: string, name: string, url: string, sha256: string}>>
     */
    public static function downloads(): array
    {
        $names = (array) (self::manifest()['files'] ?? []);
        $out = ['windows' => [], 'macos' => [], 'linux' => []];
        foreach (self::files() as $key => $file) {
            [$os, $arch] = array_pad(explode('-', $key, 2), 2, '');
            $os = $os === 'darwin' ? 'macos' : $os;
            if (isset($out[$os]) && strlen($file['sha256']) === 64) {
                $out[$os][] = ['arch' => $arch, 'name' => (string) ($names[$key]['name'] ?? ''), 'url' => $file['url'], 'sha256' => $file['sha256']];
            }
        }

        return $out;
    }

    /** @return array<string, string> os => install command after a manual download */
    public static function manualCommands(string $token): array
    {
        $server = self::serverUrl();
        $names = (array) (self::manifest()['files'] ?? []);
        $mac = (string) ($names['darwin-universal']['name'] ?? 'cortendesk-agent');
        // amd64 names; the guide says to swap in the arm64 file on ARM.
        $linux = 'cortendesk-agent_linux_amd64';

        return [
            'windows' => ".\\cortendesk-agent_windows_amd64.exe install --server {$server} --token '{$token}'",
            'macos' => "chmod +x {$mac} && sudo ./{$mac} install --server {$server} --token '{$token}'",
            'linux' => "chmod +x {$linux} && sudo ./{$linux} install --server {$server} --token '{$token}'",
        ];
    }
}
