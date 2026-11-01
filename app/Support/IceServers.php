<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The `ice-servers` client option: the STUN and TURN servers a RustDesk 1.5
 * client uses for WebRTC connections.
 *
 * The value is a comma-separated list. Each entry is one of
 *
 *   stun:host[:port]            stuns:host[:port]
 *   stun://host[:port]          stuns://host[:port]
 *   turn://user:pass@host[:port]   turns://user:pass@host[:port]
 *
 * The port defaults to 3478. An IPv6 address goes in brackets. A TURN entry
 * needs a user name and a password, percent-encoded where they hold anything
 * but letters, digits and -._~ (a comma would split the list).
 *
 * The client skips an entry it cannot use without saying so, which leaves an
 * operator believing a server is configured when it is not. So the console
 * accepts the list only when every entry is one the client will use.
 */
final class IceServers
{
    public const MAX_ENTRIES = 16;

    public const MAX_LENGTH = 4096;

    /** Settings for the web client, which is a WebRTC controller of its own. */
    public const SETTING_WEB_CLIENT = 'webclient_webrtc';

    public const SETTING_WEB_CLIENT_SERVERS = 'webclient_ice_servers';

    /**
     * What the web client page gets: null when WebRTC is off for it, else the
     * ICE servers it should use ("" for the browser's built-in defaults).
     *
     * @return array{iceServers:string}|null
     */
    public static function forWebClient(): ?array
    {
        if (Setting::get(self::SETTING_WEB_CLIENT, '1') !== '1') {
            return null;
        }

        return ['iceServers' => self::normalize((string) Setting::get(self::SETTING_WEB_CLIENT_SERVERS, '')) ?? ''];
    }

    /**
     * The list in canonical form ("" for an empty one), or null when it is too
     * long or any entry is unusable.
     */
    public static function normalize(string $value): ?string
    {
        $entries = self::entries($value);
        if ($entries === []) {
            return '';
        }
        if (count($entries) > self::MAX_ENTRIES || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $clean = [];
        foreach ($entries as $entry) {
            $parsed = self::parse($entry);
            if ($parsed === null) {
                return null;
            }
            $clean[] = $parsed;
        }

        return implode(',', $clean);
    }

    /** The first entry the client would skip, or null when all are usable. */
    public static function firstInvalid(string $value): ?string
    {
        foreach (self::entries($value) as $entry) {
            if (self::parse($entry) === null) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int,string> */
    public static function entries(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $entry) => $entry !== '',
        ));
    }

    /** One entry with its scheme lower-cased, or null when unusable. */
    private static function parse(string $entry): ?string
    {
        if (! preg_match('~^(stuns?|turns?):(//)?(.+)$~i', $entry, $m)) {
            return null;
        }

        $scheme = strtolower($m[1]);
        $slashes = $m[2] !== '';
        $rest = $m[3];
        $userinfo = null;

        if ($slashes && str_contains($rest, '@')) {
            [$userinfo, $rest] = explode('@', $rest, 2) + [1 => ''];
            if (! preg_match('~^[A-Za-z0-9\-._\~%!$&\'()*+;=]+:[A-Za-z0-9\-._\~%!$&\'()*+;=]+$~', $userinfo)
                || preg_match('~%(?![0-9A-Fa-f]{2})~', $userinfo)) {
                return null;
            }
        }

        // TURN authenticates every allocation, and only the // form carries
        // credentials. The client drops a TURN entry without them.
        if (str_starts_with($scheme, 'turn') && $userinfo === null) {
            return null;
        }
        if (str_starts_with($scheme, 'stun') && $userinfo !== null) {
            return null;
        }

        if (! self::validHostPort($rest)) {
            return null;
        }

        return $scheme.':'.($slashes ? '//' : '').($userinfo !== null ? $userinfo.'@' : '').$rest;
    }

    private static function validHostPort(string $hostPort): bool
    {
        if (str_starts_with($hostPort, '[')) {
            if (! preg_match('~^\[([^\]]+)\](?::(\d+))?$~', $hostPort, $m)) {
                return false;
            }

            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && self::validPort($m[2] ?? null);
        }

        // An IPv6 address without brackets has no unambiguous port.
        if (substr_count($hostPort, ':') > 1) {
            return false;
        }

        [$host, $port] = explode(':', $hostPort, 2) + [1 => null];

        return self::validHost($host) && self::validPort($port);
    }

    private static function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        return strlen($host) <= 253
            && preg_match('~^(?=.*[A-Za-z])([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$~', $host) === 1;
    }

    private static function validPort(?string $port): bool
    {
        if ($port === null) {
            return true;
        }

        return ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }
}
