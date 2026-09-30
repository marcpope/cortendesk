<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['action', 'conn_id', 'rustdesk_id', 'from_peer', 'from_name', 'ip', 'session_id', 'conn_type', 'uuid', 'closed_at', 'guid', 'note', 'note_user_id', 'noted_at'])]
class AuditConnection extends Model
{
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
    public static function typeLabel(int $type): string
    {
        return self::TYPE_LABELS[$type] ?? 'Type '.$type;
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
        return static::query()
            ->where('rustdesk_id', $rustdeskId)
            ->whereNull('closed_at')
            ->where('created_at', '<', now()->subSeconds(self::RECONCILE_GRACE_SECONDS))
            ->when($liveConnIds !== [], fn ($q) => $q->whereNotIn('conn_id', $liveConnIds))
            ->update(['action' => 'close', 'closed_at' => now()]);
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

        return static::query()
            ->whereNull('closed_at')
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('rustdesk_id', $heartbeating)
            ->update(['action' => 'close', 'closed_at' => now()]);
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
