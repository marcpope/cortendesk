<?php

namespace App\Support;

/**
 * Filename -> platform/architecture guessing for uploaded client builds.
 *
 * rdgen names its output after the build it just ran
 * (`rustdesk-1.4.0-x86_64.exe`, `rustdesk-1.4.0-aarch64.apk`, …), so the
 * uploader can fill the form in for the operator. Every guess is only a
 * default: ClientDownloadManager offers a dropdown that wins over whatever is
 * returned here, because a renamed file must not be able to mislabel itself.
 *
 * The extension allowlist is also the upload validator, so anything added here
 * becomes accepted input — keep it to installer formats.
 */
class ClientPlatform
{
    /** Platform slugs, in the order the public page lists them. */
    public const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'unknown'];

    /** Extension => platform. Lower-cased before lookup. */
    private const BY_EXTENSION = [
        'exe' => 'windows',
        'msi' => 'windows',
        'msix' => 'windows',
        'dmg' => 'macos',
        'pkg' => 'macos',
        'deb' => 'linux',
        'rpm' => 'linux',
        'appimage' => 'linux',
        'flatpak' => 'linux',
        'apk' => 'android',
        'aab' => 'android',
        'ipa' => 'ios',
        // Ambiguous containers: allowed (rdgen ships portable Windows and
        // Linux builds this way) but never platform evidence on their own —
        // the keyword pass below or the operator decides.
        'zip' => 'unknown',
        'gz' => 'unknown',
        'xz' => 'unknown',
        'tgz' => 'unknown',
    ];

    /**
     * Substrings that identify a platform when the extension cannot. Ordered:
     * 'macos' before 'mac', and 'windows'/'win' before anything shorter, so a
     * longer name never matches on a fragment of another.
     */
    private const BY_KEYWORD = [
        'windows' => 'windows',
        'win64' => 'windows',
        'win32' => 'windows',
        'macos' => 'macos',
        'darwin' => 'macos',
        'osx' => 'macos',
        'android' => 'android',
        'linux' => 'linux',
        'ubuntu' => 'linux',
        'debian' => 'linux',
        'fedora' => 'linux',
        'ios' => 'ios',
        'iphone' => 'ios',
    ];

    /** Architecture tokens, longest/most specific first. */
    private const BY_ARCH = [
        'aarch64' => 'arm64',
        'arm64' => 'arm64',
        'armv7' => 'armv7',
        'armeabi' => 'armv7',
        'x86_64' => 'x86_64',
        'amd64' => 'x86_64',
        'x64' => 'x86_64',
        'i686' => 'x86',
        'i386' => 'x86',
        'x86' => 'x86',
        'universal' => 'universal',
    ];

    /** @return array<int,string> extensions accepted by the uploader */
    public static function allowedExtensions(): array
    {
        return array_keys(self::BY_EXTENSION);
    }

    /** Lower-cased extension of a filename, '' when it has none. */
    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function extensionAllowed(string $filename): bool
    {
        return array_key_exists(self::extension($filename), self::BY_EXTENSION);
    }

    /**
     * Best guess at the platform of an uploaded build. Extension first (it is
     * the only part of a filename that has to be true for the file to install
     * at all), then a keyword pass for the ambiguous containers.
     */
    public static function fromFilename(string $filename): string
    {
        $name = strtolower(basename($filename));

        $platform = self::BY_EXTENSION[self::extension($name)] ?? 'unknown';

        if ($platform !== 'unknown') {
            return $platform;
        }

        foreach (self::BY_KEYWORD as $needle => $guess) {
            if (str_contains($name, $needle)) {
                return $guess;
            }
        }

        return 'unknown';
    }

    /** Best guess at the architecture, or null when the name says nothing. */
    public static function archFromFilename(string $filename): ?string
    {
        $name = strtolower(basename($filename));

        foreach (self::BY_ARCH as $needle => $arch) {
            if (str_contains($name, $needle)) {
                return $arch;
            }
        }

        return null;
    }

    /**
     * A human label for the file, used as the default "Label" in the uploader:
     * "Windows (64-bit)", "Android (ARM64)", …
     */
    public static function labelFromFilename(string $filename): string
    {
        $platform = self::label(self::fromFilename($filename));
        $arch = self::archLabel(self::archFromFilename($filename));

        return $arch === '' ? $platform : $platform.' ('.$arch.')';
    }

    public static function label(string $platform): string
    {
        return match ($platform) {
            'windows' => 'Windows',
            'macos' => 'macOS',
            'linux' => 'Linux',
            'android' => 'Android',
            'ios' => 'iOS',
            default => 'Other',
        };
    }

    public static function archLabel(?string $arch): string
    {
        return match ($arch) {
            'arm64' => 'ARM64',
            'armv7' => 'ARMv7',
            'x86_64' => '64-bit',
            'x86' => '32-bit',
            'universal' => 'Universal',
            default => '',
        };
    }

    /** @return array<string,string> slug => label, for a <select> */
    public static function options(): array
    {
        $options = [];

        foreach (self::PLATFORMS as $platform) {
            $options[$platform] = self::label($platform);
        }

        return $options;
    }
}
