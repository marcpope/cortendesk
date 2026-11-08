<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The one switch for Monitoring and the CortenDesk Agent (docs/monitoring).
 *
 * Off by default. Off hides the menu, the device tab and every /monitoring
 * screen, and the agent API answers 404. Stored agents and history stay.
 */
final class Monitoring
{
    public const SETTING_ENABLED = 'monitoring_enabled';

    public const SETTING_INTERVAL = 'monitoring_interval';

    public const SETTING_RETENTION = 'monitoring_retention_days';

    /** Lets users with Remote commands: Manage queue commands. Off by default. */
    public const SETTING_COMMANDS = 'monitoring_commands_enabled';

    /** The URL agents connect to, when it differs from the console's own. */
    public const SETTING_AGENT_URL = 'monitoring_agent_url';

    /** Queue agent.update for agents older than the manifest. On by default. */
    public const SETTING_AUTO_UPDATE = 'monitoring_agent_auto_update';

    public const DEFAULT_INTERVAL = 60;

    public const MIN_INTERVAL = 30;

    public const MAX_INTERVAL = 900;

    /** Interval handed to a disabled agent, so it checks back rarely. */
    public const DISABLED_INTERVAL = 900;

    public const DEFAULT_RETENTION = 14;

    public const MAX_RETENTION = 365;

    /** An agent counts as online for this many report intervals. */
    public const ONLINE_INTERVALS = 3;

    /** Usage at or above this percent is flagged on the dashboard. */
    public const HIGH_PERCENT = 90;

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING_ENABLED, '0') === '1';
    }

    /** The agent connection URL setting, or "" for this console's address. */
    public static function agentUrl(): string
    {
        return rtrim(trim((string) Setting::get(self::SETTING_AGENT_URL, '')), '/');
    }

    /**
     * Networks agents accept plain http to: loopback, RFC 1918, carrier-grade
     * NAT (Tailscale uses it) and IPv6 unique local. Matches the agent's list.
     */
    public const PRIVATE_NETWORKS = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    /**
     * Agents take https anywhere, and plain http only when the console's
     * address is on a private network. A name must resolve, from here, to
     * private addresses only; the agent checks again when it connects.
     */
    public static function agentUrlWorks(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        return $scheme === 'https' || ($scheme === 'http' && $host !== '' && self::isPrivateHost($host));
    }

    /** The URL is plain http (allowed or not). */
    public static function agentUrlIsPlain(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http';
    }

    public static function isPrivateHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return IpUtils::checkIp($host, self::PRIVATE_NETWORKS);
        }

        $ips = Cache::remember('monitoring:host-ips:'.$host, 300, fn () => gethostbynamel($host) ?: []);

        return $ips !== [] && collect($ips)->every(fn ($ip) => IpUtils::checkIp($ip, self::PRIVATE_NETWORKS));
    }

    public static function commandsEnabled(): bool
    {
        return Setting::get(self::SETTING_COMMANDS, '0') === '1';
    }

    public static function autoUpdate(): bool
    {
        return Setting::get(self::SETTING_AUTO_UPDATE, '1') === '1';
    }

    public static function interval(): int
    {
        $value = (int) Setting::get(self::SETTING_INTERVAL, (string) self::DEFAULT_INTERVAL);

        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $value ?: self::DEFAULT_INTERVAL));
    }

    public static function retentionDays(): int
    {
        $value = (int) Setting::get(self::SETTING_RETENTION, (string) self::DEFAULT_RETENTION);

        return max(1, min(self::MAX_RETENTION, $value ?: self::DEFAULT_RETENTION));
    }

    /** "12d 4h" style, in the current locale. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = max(60, $seconds);

        return CarbonInterval::days(intdiv($seconds, 86400))
            ->hours(intdiv($seconds % 86400, 3600))
            ->minutes(intdiv($seconds % 3600, 60))
            ->locale(app()->getLocale())
            ->forHumans(['short' => true, 'parts' => 2, 'skip' => ['weeks']]);
    }

    /** Seconds without a report before an agent counts as offline. */
    public static function onlineWindow(): int
    {
        return self::interval() * self::ONLINE_INTERVALS;
    }
}
