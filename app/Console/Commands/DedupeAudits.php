<?php

namespace App\Console\Commands;

use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Remove the copies RustDesk 1.5.0 clients left behind (issue #88).
 *
 * 1.5.0 retries an audit post unless the answer is a 2xx with an empty body.
 * We answered `{}`, so every record was posted three times and stored three
 * times: the first attempt, a retry 10 seconds after it returned, and another
 * 30 seconds after that.
 *
 * - File and alarm rows: a row identical to an earlier one from the same
 *   device is a copy when it arrives on that schedule (RETRY_GAPS). The
 *   earliest is kept. The schedule, not a plain time window, is what keeps
 *   a person repeating the same transfer a minute later, and what makes a
 *   second run a no-op.
 * - Connection rows: each "new" was stored three times under the same
 *   conn_id on the same schedule; "authorized" and "close" then updated only
 *   the newest open one. Copies are the rows of such a group that were never
 *   authorized. The authorized row is kept (or the earliest when none was)
 *   and takes the group's earliest start time.
 *
 * Only devices that now report client 1.5.0 or later are looked at, unless
 * --any-version. Reports only unless --force is given.
 */
class DedupeAudits extends Command
{
    protected $signature = 'cortendesk:dedupe-audits
        {--dry-run : Report what would be removed (the default)}
        {--force : Remove the copies}
        {--all : Look at all rows, not only those since '.self::SINCE.'}
        {--any-version : Include devices that report a client older than 1.5.0, or none}';

    protected $description = 'Remove audit rows stored twice or three times by RustDesk 1.5.0 retries (issue #88)';

    /** RustDesk 1.5.0, the first client that retries audit posts, shipped this day. */
    public const SINCE = '2026-10-01';

    /**
     * Seconds between a row and its next copy, per retry: the client's
     * backoff (10s, then 30s) plus the time the previous attempt took.
     */
    public const RETRY_GAPS = [[8, 20], [28, 45]];

    /** Longest a group can span; older anchors are dropped. */
    private const WINDOW = 125;

    /** @var array<string, true>|null rustdesk ids to look at; null = all */
    private ?array $devices = null;

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $since = $this->option('all') ? null : Carbon::parse(self::SINCE)->startOfDay();

        $this->line($since === null
            ? 'Looking at all audit rows.'
            : 'Looking at audit rows since '.$since->toDateString().' (use --all for older ones).');

        $this->devices = null;
        if (! $this->option('any-version')) {
            $this->devices = Device::withTrashed()
                ->whereNotNull('version')
                ->pluck('version', 'rustdesk_id')
                ->filter(fn ($v) => version_compare((string) $v, '1.5.0', '>='))
                ->map(fn () => true)
                ->all();
            $this->line(count($this->devices).' device(s) report client 1.5.0 or later (use --any-version for all).');
        }

        $files = $this->copies(
            AuditFileTransfer::query(),
            $since,
            fn (AuditFileTransfer $r) => [$r->rustdesk_id, $r->from_peer, $r->direction, $r->path, $r->info, $r->is_file, $r->file_count, $r->ip, $r->uuid],
        );
        $alarms = $this->copies(
            AlarmLog::query(),
            $since,
            fn (AlarmLog $r) => [$r->rustdesk_id, $r->uuid, $r->typ, $r->info, $r->conn_id],
        );
        [$connDelete, $connRestart] = $this->connectionCopies($since);

        $this->table(['Table', 'Copies'], [
            ['audit_file_transfers', count($files)],
            ['alarm_logs', count($alarms)],
            ['audit_connections', count($connDelete)],
        ]);

        $total = count($files) + count($alarms) + count($connDelete);
        if ($total === 0) {
            $this->info('No retry copies found.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->info("Would remove {$total} row(s) and fix the start time of ".count($connRestart).' session(s). Run with --force to apply.');

            return self::SUCCESS;
        }

        foreach (array_chunk($files, 1000) as $ids) {
            AuditFileTransfer::query()->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($alarms, 1000) as $ids) {
            AlarmLog::query()->whereIn('id', $ids)->delete();
        }
        foreach (array_chunk($connDelete, 1000) as $ids) {
            AuditConnection::query()->whereIn('id', $ids)->delete();
        }
        foreach ($connRestart as $id => $createdAt) {
            // Bypass Eloquent so updated_at keeps its real value.
            AuditConnection::query()->whereKey($id)->toBase()->update(['created_at' => $createdAt]);
        }

        $this->info("Removed {$total} row(s); fixed the start time of ".count($connRestart).' session(s).');

        return self::SUCCESS;
    }

    /**
     * Ids of rows that repeat an earlier identical row within the window.
     *
     * @param  callable(mixed): array<int, mixed>  $fingerprint
     * @return array<int, int>
     */
    private function copies(Builder $query, ?Carbon $since, callable $fingerprint): array
    {
        $copies = [];
        /** @var array<string, array{at: int, last: int, copies: int}> $anchors */
        $anchors = [];

        $query->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->lazyById(1000)
            ->each(function ($row) use ($fingerprint, &$copies, &$anchors) {
                if (! $this->looksAt($row->rustdesk_id)) {
                    return;
                }

                $at = $row->created_at?->getTimestamp() ?? 0;
                $key = hash('sha256', serialize($fingerprint($row)));
                $anchor = $anchors[$key] ?? null;

                if ($anchor !== null && $this->isRetry($anchor['copies'], $at - $anchor['last'])) {
                    $copies[] = $row->id;
                    $anchors[$key]['copies']++;
                    $anchors[$key]['last'] = $at;

                    return;
                }

                $anchors[$key] = ['at' => $at, 'last' => $at, 'copies' => 0];

                // Drop anchors no later row can match, so --all stays small.
                if (count($anchors) > 5000) {
                    $anchors = array_filter($anchors, fn ($a) => $at - $a['at'] <= self::WINDOW);
                }
            });

        return $copies;
    }

    /**
     * Connection rows to delete, and the kept rows whose created_at moves to
     * their group's first copy.
     *
     * @return array{0: array<int, int>, 1: array<int, Carbon>}
     */
    private function connectionCopies(?Carbon $since): array
    {
        $delete = [];
        $restart = [];
        /** @var array<string, Collection<int, AuditConnection>> $groups */
        $groups = [];

        $flush = function (Collection $group) use (&$delete, &$restart) {
            if ($group->count() < 2) {
                return;
            }

            $first = $group->first();
            $authorized = $group->filter(fn (AuditConnection $r) => (string) $r->from_peer !== '');
            // More than one authorized row is not a retry pattern: leave it.
            if ($authorized->count() > 1) {
                return;
            }
            $keep = $authorized->first() ?? $first;

            foreach ($group as $row) {
                if ($row->id === $keep->id) {
                    continue;
                }
                // Only plain copies: same address, no note, nobody asked to disconnect it.
                if ($row->ip !== $keep->ip || $row->note !== null || $row->disconnect_requested_at !== null) {
                    continue;
                }
                $delete[] = $row->id;
            }

            if ($keep->id !== $first->id && in_array($first->id, $delete, true)) {
                $restart[$keep->id] = $first->created_at;
            }
        };

        AuditConnection::query()
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('conn_id', '>', 0)
            ->lazyById(1000)
            ->each(function (AuditConnection $row) use (&$groups, $flush) {
                if (! $this->looksAt($row->rustdesk_id)) {
                    return;
                }

                $key = $row->rustdesk_id."\n".$row->conn_id;
                $group = $groups[$key] ?? null;
                $at = $row->created_at?->getTimestamp() ?? 0;

                if ($group !== null
                    && $this->isRetry($group->count() - 1, $at - ($group->last()->created_at?->getTimestamp() ?? 0))) {
                    $group->push($row);

                    return;
                }

                if ($group !== null) {
                    $flush($group);
                }
                $groups[$key] = collect([$row]);

                // Settle groups no later row can join, so --all stays small.
                if (count($groups) > 5000) {
                    foreach ($groups as $k => $g) {
                        if ($at - ($g->first()->created_at?->getTimestamp() ?? 0) > self::WINDOW) {
                            $flush($g);
                            unset($groups[$k]);
                        }
                    }
                }
            });

        foreach ($groups as $group) {
            $flush($group);
        }

        return [$delete, $restart];
    }

    /** Does a row $gap seconds after the previous one fit retry number $copies + 1? */
    private function isRetry(int $copies, int $gap): bool
    {
        $range = self::RETRY_GAPS[$copies] ?? null;

        return $range !== null && $gap >= $range[0] && $gap <= $range[1];
    }

    private function looksAt(string $rustdeskId): bool
    {
        return $this->devices === null || isset($this->devices[$rustdeskId]);
    }
}
