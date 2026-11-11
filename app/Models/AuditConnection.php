<?php

namespace App\Models;

use App\Support\AuditLogStream;
use App\Support\Label;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['action', 'conn_id', 'rustdesk_id', 'from_peer', 'from_name', 'ip', 'session_id', 'conn_type', 'path', 'uuid', 'closed_at', 'guid', 'note', 'note_user_id', 'noted_at'])]
class AuditConnection extends Model
{
    /** Current target identity; missing or deleted devices leave the audit ID intact. */
    public function controlledDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'rustdesk_id', 'rustdesk_id');
    }

    /** Longest end-of-session note kept. The Flutter client caps its field at 256. */
    public const NOTE_MAX_LENGTH = 2000;

    /**
     * Every session gets the guid the controlling client later asks for
     * (GET /api/audit/conn/active) and sends its note against (PUT /api/audit).
     */
    protected static function booted(): void
    {
        static::creating(function (AuditConnection $row) {
            $row->guid ??= (string) Str::uuid();
        });
    }

    /** The relay session that carried this connection, if one did (issue #130). */
    public function relaySession(): BelongsTo
    {
        return $this->belongsTo(RelaySession::class);
    }

    /**
     * How the connection got through: relay, lan, direct, direct-webrtc
     * (direct or WebRTC; hbbs offered both and only the controller knows
     * which won), or null when nothing says. Relay is proven by our relay's
     * own report; the rest comes from hbbs's tag, which 1.5+ devices echo.
     */
    public function transport(): ?string
    {
        if ($this->relay_session_id !== null) {
            return 'relay';
        }

        return match ($this->path) {
            'relay' => 'relay',
            'lan' => 'lan',
            'punch' => 'direct',
            'punch-webrtc' => 'direct-webrtc',
            default => null,
        };
    }

    /**
     * The same decision as transport(), in SQL, folded to the four the
     * screens filter and count by: relay, direct, lan, unknown.
     */
    public const TRANSPORT_SQL = "CASE WHEN relay_session_id IS NOT NULL OR path = 'relay' THEN 'relay'"
        ." WHEN path = 'lan' THEN 'lan'"
        ." WHEN path IN ('punch', 'punch-webrtc') THEN 'direct'"
        ." ELSE 'unknown' END";

    /** Narrow a query to one of relay, direct, lan, unknown. */
    public static function whereTransport(Builder $query, string $transport): Builder
    {
        return match ($transport) {
            'relay' => $query->where(fn (Builder $q) => $q->whereNotNull('relay_session_id')->orWhere('path', 'relay')),
            'lan' => $query->whereNull('relay_session_id')->where('path', 'lan'),
            'direct' => $query->whereNull('relay_session_id')->whereIn('path', ['punch', 'punch-webrtc']),
            'unknown' => $query->whereNull('relay_session_id')->whereNull('path'),
            default => $query,
        };
    }

    /** Who wrote the note; null for the tokenless in-session note. */
    public function noteUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'note_user_id');
    }

    /**
     * Session type as reported by the client's audit/conn call
     * (docs/client-api.md): the label every screen and the CSV export use.
     */
    public const TYPE_LABELS = [
        0 => 'Remote Control',
        1 => 'File Transfer',
        2 => 'Port Forward',
        3 => 'View Camera',
        4 => 'Terminal',
    ];

    private const TYPE_ICONS = [
        0 => 'ri-remote-control-line',
        1 => 'ri-file-transfer-line',
        2 => 'ri-swap-line',
        3 => 'ri-camera-line',
        4 => 'ri-terminal-box-line',
    ];

    /** Human label for a session type; "Type N" when unknown, so nothing is lost. */
    /** The English name, for CSV exports, which stay English in every language. */
    public static function englishTypeLabel(int $type): string
    {
        return self::TYPE_LABELS[$type] ?? 'Type '.$type;
    }

    public static function typeLabel(int $type): string
    {
        return Label::get('conn_type', $type, self::TYPE_LABELS[$type] ?? __('labels.misc_type_n', ['n' => $type]));
    }

    /** Remix icon class for a session type. */
    public static function typeIcon(int $type): string
    {
        return self::TYPE_ICONS[$type] ?? 'ri-question-line';
    }

    /**
     * How long to wait before re-broadcasting a disconnect the client has not
     * acted on. A heartbeat can be lost; a live session must not be left
     * un-cancellable because of it. Long enough that a client acting normally
     * closes the session first.
     */
    public const DISCONNECT_RETRY_SECONDS = 60;

    /**
     * Connection ids this device should close, for the heartbeat response.
     *
     * Marks them sent in the same breath so a 15s heartbeat does not re-issue
     * the same instruction repeatedly while the client is acting on it.
     *
     * @return array<int, int>
     */
    public static function pendingDisconnectsFor(string $rustdeskId): array
    {
        $rows = static::query()
            ->where('rustdesk_id', $rustdeskId)
            ->whereNull('closed_at')
            ->whereNotNull('disconnect_requested_at')
            ->whereNotNull('conn_id')
            ->where(fn ($q) => $q->whereNull('disconnect_sent_at')
                ->orWhere('disconnect_sent_at', '<', now()->subSeconds(self::DISCONNECT_RETRY_SECONDS)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        static::query()->whereIn('id', $rows->pluck('id'))
            ->update(['disconnect_sent_at' => now()]);

        return $rows->pluck('conn_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * How long a session is protected from heartbeat reconciliation.
     *
     * The audit POST that opens a session and the heartbeat that lists live
     * connections are separate requests on separate paths, so a session can
     * legitimately exist for a moment before the device reports it in `conns`.
     * Closing it in that window would end a session that had only just begun.
     */
    public const RECONCILE_GRACE_SECONDS = 90;

    /**
     * A device with no heartbeat for this long has its sessions closed.
     *
     * Reconciliation below can only act on devices that are still talking. A
     * machine that was rebooted, unplugged or had its service stopped sends
     * nothing at all, so its sessions would stay Active forever without a
     * time-based sweep. Devices heartbeat every 15s, so this is many missed
     * beats, not a marginal call.
     */
    public const STALE_AFTER_MINUTES = 5;

    /**
     * Close sessions the device is no longer reporting as live.
     *
     * `conns` on the heartbeat is the device's own list of live incoming
     * connections and is authoritative. Crucially it is OMITTED ENTIRELY when
     * empty (docs/client-api.md §8), so an absent key means "nothing is live",
     * not "no information" — treating it as the latter is what would leave a
     * rebooted machine's session Active forever.
     *
     * @param  array<int, int>  $liveConnIds
     * @return int rows closed
     */
    public static function reconcileFor(string $rustdeskId, array $liveConnIds): int
    {
        return self::closeInBulk(static::query()
            ->where('rustdesk_id', $rustdeskId)
            ->whereNull('closed_at')
            ->where('created_at', '<', now()->subSeconds(self::RECONCILE_GRACE_SECONDS))
            ->when($liveConnIds !== [], fn ($q) => $q->whereNotIn('conn_id', $liveConnIds)), 'reconciled');
    }

    /**
     * Close sessions belonging to devices that have stopped heartbeating.
     *
     * The other half of the problem: reconciliation needs a heartbeat to act
     * on, and a powered-off machine sends none.
     *
     * Written as "not currently heartbeating" rather than "device row says it
     * is silent" on purpose. The inverted form is the only one that also
     * catches a session whose device row is gone — hard-deleted, or recycled
     * into the soft-deleted state where we deliberately stop recording
     * presence. Selecting silent devices instead would leave those rows with
     * nothing to match against and they would stay Active permanently, which
     * is the exact bug this is fixing.
     *
     * An empty active set is not a special case: Laravel compiles an empty
     * whereNotIn to `1 = 1`, so "no device is heartbeating" correctly means
     * every stale session closes.
     *
     * @return int rows closed
     */
    public static function closeStaleSessions(): int
    {
        $cutoff = now()->subMinutes(self::STALE_AFTER_MINUTES);

        $heartbeating = Device::query()
            ->where('last_online_at', '>=', $cutoff)
            ->pluck('rustdesk_id');

        return self::closeInBulk(static::query()
            ->whereNull('closed_at')
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('rustdesk_id', $heartbeating), 'stale');
    }

    /**
     * Close every session $query selects. A bulk update fires no model
     * events, so with the audit log stream on the rows are read first and
     * their closes written from here; with it off this stays one UPDATE.
     */
    private static function closeInBulk(Builder $query, string $reason): int
    {
        $close = ['action' => 'close', 'closed_at' => now()];

        if (! AuditLogStream::enabled()) {
            return $query->update($close);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $closed = static::query()->whereKey($rows->modelKeys())->whereNull('closed_at')->update($close);

        foreach ($rows as $row) {
            AuditLogStream::connection($row->forceFill($close), $reason);
        }

        return $closed;
    }

    /** Is an operator waiting for this session to close? */
    public function isDisconnecting(): bool
    {
        return $this->closed_at === null && $this->disconnect_requested_at !== null;
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'disconnect_requested_at' => 'datetime',
            'disconnect_sent_at' => 'datetime',
            'note_user_id' => 'integer',
            'noted_at' => 'datetime',
        ];
    }
}
