<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete audit/log rows older than the configured retention window.
 * Retention is a Setting ('log_retention_days'); 0 (or empty) = keep forever.
 * Scheduled daily; also runnable from Settings ("Prune now") or the CLI.
 */
class PruneLogs extends Command
{
    protected $signature = 'cortendesk:prune-logs {--days= : Override the configured retention window}';

    protected $description = 'Prune audit logs older than the configured retention window';

    /** Tables covered by retention; each has a created_at column. */
    public const TABLES = [
        'audit_connections',
        'relay_sessions',
        'audit_file_transfers',
        'login_logs',
        'alarm_logs',
        'console_audits',
        'notification_deliveries',
    ];

    private const CHUNK = 10_000;

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) (Setting::get('log_retention_days', (string) config('cortendesk.log_retention_days')) ?: 0);

        if ($days <= 0) {
            $this->info('Log retention is disabled (0 days) — nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        foreach (self::TABLES as $table) {
            $deleted = 0;
            // Chunked deletes keep lock times short on big tables.
            do {
                $batch = DB::table($table)
                    ->where('created_at', '<', $cutoff)
                    ->limit(self::CHUNK)
                    ->delete();
                $deleted += $batch;
            } while ($batch === self::CHUNK);

            if ($deleted > 0) {
                $this->line("{$table}: deleted {$deleted}");
            }
            $total += $deleted;
        }

        $this->info("Pruned {$total} log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
