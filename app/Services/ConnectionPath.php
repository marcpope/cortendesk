<?php

namespace App\Services;

use App\Models\AuditConnection;
use App\Models\RelaySession;
use Illuminate\Support\Carbon;

/**
 * How a connection got through: relayed, direct, or on the LAN (issue #130).
 *
 * Two sources, neither enough alone:
 *
 * - hbbs tags the request it sends a device (`cd1/lan`, `cd1/punch`,
 *   `cd1/punch-webrtc`, `cd1/relay`) and RustDesk 1.5+ devices echo the tag
 *   in their connection audit. That says how the attempt started, not where
 *   it ended: one punch hole starts TCP, UDP, IPv6, WebRTC and sometimes
 *   relay attempts at once, all carrying the same tag.
 * - hbbr reports every session it carries (RelaySession). That is the only
 *   proof a session was relayed, and it works for any client version.
 *
 * Every attempt the device opens posts its own audit row, and the losing
 * attempts close within seconds without logging in. A relay session is
 * therefore linked to one row only, the one it carried: the row that logged
 * in if there is one, and only while the relay lived as long as that row.
 */
class ConnectionPath
{
    public const PATHS = ['lan', 'punch', 'punch-webrtc', 'relay'];

    private const TAG_PREFIX = 'cd1/';

    /** A relay session and the device's record of it start this close together. */
    public const MATCH_WINDOW_SECONDS = 30;

    /** A carried session's relay closes within this of the device's close. */
    public const END_SLACK_SECONDS = 15;

    /** More than a losing attempt ever carries before it is dropped. */
    public const CARRIED_BYTES = 1_048_576;

    /** hbbr's ConnType => the console's session type (AuditConnection::TYPE_LABELS). */
    private const RELAY_TYPES = [0 => 0, 1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 4];

    /** The path out of the device's conn_audit_ref, or null when it is not ours. */
    public static function fromTag(mixed $tag): ?string
    {
        if (! is_string($tag) || ! str_starts_with($tag, self::TAG_PREFIX)) {
            return null;
        }
        $path = substr($tag, strlen(self::TAG_PREFIX));

        return in_array($path, self::PATHS, true) ? $path : null;
    }

    public static function sessionType(int $relayConnType): int
    {
        return self::RELAY_TYPES[$relayConnType] ?? 0;
    }

    /** Re-decide which connection, if any, this relay session carried. */
    public static function linkRelay(RelaySession $relay): void
    {
        $best = null;
        foreach (self::candidates($relay) as $row) {
            if (self::carried($relay, $row)) {
                $best = $row;
                break;
            }
        }

        AuditConnection::query()
            ->where('relay_session_id', $relay->id)
            ->when($best, fn ($q) => $q->whereKeyNot($best->id))
            ->update(['relay_session_id' => null]);

        if ($best && $best->relay_session_id !== $relay->id) {
            $best->forceFill(['relay_session_id' => $relay->id])->saveQuietly();
        }
    }

    /** A connection logged in or closed: re-decide the relay sessions around it. */
    public static function refresh(AuditConnection $row): void
    {
        $at = $row->created_at ?? now();

        RelaySession::query()
            ->where('rustdesk_id', $row->rustdesk_id)
            ->whereBetween('started_at', [
                $at->copy()->subSeconds(self::MATCH_WINDOW_SECONDS),
                $at->copy()->addSeconds(self::MATCH_WINDOW_SECONDS),
            ])
            ->get()
            ->each(fn (RelaySession $relay) => self::linkRelay($relay));
    }

    /**
     * Rows that may be this session's, best first: logged in before not, then
     * closest in time. A logged-in row must also be the same kind of session.
     *
     * @return iterable<AuditConnection>
     */
    private static function candidates(RelaySession $relay): iterable
    {
        $start = $relay->started_at ?? $relay->created_at ?? now();

        return AuditConnection::query()
            ->where('rustdesk_id', $relay->rustdesk_id)
            ->whereBetween('created_at', [
                $start->copy()->subSeconds(self::MATCH_WINDOW_SECONDS),
                $start->copy()->addSeconds(self::MATCH_WINDOW_SECONDS),
            ])
            ->get()
            ->filter(fn (AuditConnection $row) => $row->from_peer === null || $row->from_peer === ''
                || (int) $row->conn_type === $relay->conn_type)
            ->sortBy(fn (AuditConnection $row) => [
                $row->from_peer ? 0 : 1,
                abs($row->created_at->diffInSeconds($start)),
            ])
            ->values();
    }

    /**
     * Did this relay session carry this connection, rather than lose the
     * race to another attempt?
     */
    private static function carried(RelaySession $relay, AuditConnection $row): bool
    {
        if ($relay->bytes >= self::CARRIED_BYTES) {
            return true;
        }
        // Still relaying: the connection's own close may simply not be in yet.
        if ($relay->ended_at === null) {
            return true;
        }
        // The relay closed and the connection goes on: another attempt won.
        if ($row->closed_at === null) {
            return false;
        }

        return $relay->ended_at->gte($row->closed_at->copy()->subSeconds(self::END_SLACK_SECONDS));
    }

    /** Unix seconds from a report, clamped to now: a skewed clock must not date the future. */
    public static function reportedTime(mixed $seconds): ?Carbon
    {
        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return null;
        }
        $at = Carbon::createFromTimestamp((int) $seconds);

        return $at->gt(now()) ? now() : $at;
    }
}
