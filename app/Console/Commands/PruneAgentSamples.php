<?php

namespace App\Console\Commands;

use App\Models\AgentSample;
use App\Support\Monitoring;
use Illuminate\Console\Command;

/**
 * Drop agent report history older than monitoring_retention_days. Runs while
 * Monitoring is off too, so history left behind still ages out.
 *
 * Deletes in chunks: a big fleet writes a lot of rows per day, and one
 * DELETE over all of them holds locks the report endpoint is waiting on.
 */
class PruneAgentSamples extends Command
{
    protected $signature = 'cortendesk:prune-agent-samples';

    protected $description = 'Delete Monitoring report history older than the retention setting';

    public function handle(): int
    {
        $cutoff = now()->subDays(Monitoring::retentionDays());
        $total = 0;

        do {
            $ids = AgentSample::query()->where('taken_at', '<', $cutoff)->limit(5000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : AgentSample::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        $this->info("Deleted {$total} agent samples older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
