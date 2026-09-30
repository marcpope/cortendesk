<?php

namespace App\Services;

use App\Models\ClientToken;
use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The console side of the link with CortenDesk Server (hbbs 1.1.0+), issues
 * #81, #82 and #76. hbbs pulls policy() every few seconds and enforces it;
 * it posts LAN addresses back. Wire contract: docs/server-link.md.
 */
class ServerLink
{
    public const MODE_OPEN = 'open';

    public const MODE_APPROVED = 'approved';

    /** Web client tickets live this long; the page mints a fresh one on load. */
    public const TICKET_TTL_SECONDS = 12 * 3600;

    /** A pull older than this means hbbs is not following the console. */
    public const STALE_SECONDS = 60;

    private const PULL_CACHE_KEY = 'cortendesk:server-link:last-pull';

    public static function secret(): string
    {
        return (string) config('cortendesk.server_secret');
    }

    public static function configured(): bool
    {
        return self::secret() !== '';
    }

    /**
     * Approved-only mode: match signed-out initiators to a device by the IP
     * they registered from. Off by default. A shared public IP or a proxy
     * that rewrites source addresses makes every client behind it look alike,
     * so with it off only signed-in clients and the web client start sessions.
     */
    public static function ipMatch(): bool
    {
        return Setting::get('server_ip_match', '0') === '1';
    }

    public static function mode(): string
    {
        return Setting::get('server_access_mode', self::MODE_OPEN) === self::MODE_APPROVED
            ? self::MODE_APPROVED
            : self::MODE_OPEN;
    }

    /**
     * What hbbs enforces. Approved means active and not in the recycle bin;
     * pending, recycled and unknown devices are simply absent. Access tokens
     * go out as sha256 hashes, keyed to the device they were issued to, so
     * hbbs can tell which device a signed-in client is.
     *
     * @return array{mode: string, allow: list<string>, incoming_only: list<string>, tokens: object, ip_match: bool}
     */
    public function policy(): array
    {
        $allow = [];
        $incomingOnly = [];

        Device::query()->approved()
            ->select(['id', 'rustdesk_id', 'can_initiate'])
            ->orderBy('id')
            ->chunk(2000, function ($devices) use (&$allow, &$incomingOnly) {
                foreach ($devices as $device) {
                    if ($device->can_initiate) {
                        $allow[] = $device->rustdesk_id;
                    } else {
                        $incomingOnly[] = $device->rustdesk_id;
                    }
                }
            });

        $tokens = [];
        ClientToken::query()
            ->whereNotNull('device_id')->where('device_id', '!=', '')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->select(['id', 'token', 'device_id'])
            ->chunk(2000, function ($rows) use (&$tokens) {
                foreach ($rows as $row) {
                    $tokens[hash('sha256', $row->token)] = (string) $row->device_id;
                }
            });
        ksort($tokens);

        return [
            'mode' => self::mode(),
            'allow' => $allow,
            'incoming_only' => $incomingOnly,
            'tokens' => (object) $tokens,
            'ip_match' => self::ipMatch(),
        ];
    }

    /**
     * A ticket for the console's own web client, sent as the punch hole
     * token. hbbs checks the HMAC with the shared secret and lets the session
     * start; the target is still checked. Empty when the link is off.
     */
    public static function webTicket(User $user): string
    {
        if (! self::configured()) {
            return '';
        }

        $signed = 'cdw1.'.(time() + self::TICKET_TTL_SECONDS).'.web-'.$user->id;

        return $signed.'.'.hash_hmac('sha256', $signed, self::secret());
    }

    public static function recordPull(string $agent): void
    {
        Cache::forever(self::PULL_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'agent' => mb_substr($agent, 0, 80),
        ]);
    }

    /**
     * Link state for Settings and diagnostics. `ok` is null when there is
     * nothing to enforce and no link, false when something needs the link and
     * it is missing or stale.
     *
     * @return array{configured: bool, last_pull_at: ?string, agent: ?string, fresh: bool, needed: bool, ok: ?bool, note: string}
     */
    public static function status(): array
    {
        $pull = Cache::get(self::PULL_CACHE_KEY);
        $at = is_array($pull) && is_string($pull['at'] ?? null) ? $pull['at'] : null;
        $fresh = $at !== null && Carbon::parse($at)->gt(now()->subSeconds(self::STALE_SECONDS));
        $needed = self::mode() === self::MODE_APPROVED
            || Device::query()->approved()->where('can_initiate', false)->exists();
        $configured = self::configured();

        $note = match (true) {
            ! $configured => 'Not configured. Approved-only mode and incoming-only devices are not enforced.',
            $at === null => 'Configured, but the ID server has never fetched the policy.',
            ! $fresh => 'The ID server last fetched the policy '.Carbon::parse($at)->diffForHumans().'.',
            default => 'The ID server fetched the policy '.Carbon::parse($at)->diffForHumans().'.',
        };

        return [
            'configured' => $configured,
            'last_pull_at' => $at,
            'agent' => is_array($pull) ? ($pull['agent'] ?? null) : null,
            'fresh' => $configured && $fresh,
            'needed' => $needed,
            'ok' => $configured && $fresh ? true : ($needed ? false : null),
            'note' => $note,
        ];
    }
}
