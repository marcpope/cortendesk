<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\ConsoleAudit;
use App\Models\User;
use App\Support\AgentInstall;
use App\Support\Monitoring;
use App\Support\RustDeskReleases;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Queues commands, hands them to agents in report responses and stores
 * their results (docs/monitoring/SPEC.md "Commands").
 */
class AgentCommands
{
    /** Most commands in one report response. */
    public const PER_REPORT = 5;

    /** A delivered command with no word back is sent again after this. */
    public const RESEND_AFTER = 15 * 60;

    /** A failed self-update is not retried automatically for this long. */
    public const UPDATE_RETRY_AFTER = 24 * 3600;

    /**
     * Queue an admin's command. Throws InvalidArgumentException with a
     * user-facing message when it can't be queued. An open command of the
     * same type is returned instead of queueing a second one.
     */
    public function queue(Agent $agent, string $type, array $input, User $by, ?string $batch = null): AgentCommand
    {
        if (! in_array($type, AgentCommand::ACTIONS, true)) {
            throw new InvalidArgumentException(__('monitoring.cmd_error_unknown'));
        }
        if (! Monitoring::commandsEnabled()) {
            throw new InvalidArgumentException(__('monitoring.cmd_error_disabled'));
        }
        if (! $agent->enabled) {
            throw new InvalidArgumentException(__('monitoring.cmd_error_agent_disabled'));
        }
        if (! $agent->supports($type)) {
            throw new InvalidArgumentException(__('monitoring.cmd_error_unsupported'));
        }

        $params = $this->params($agent, $type, $input);

        $open = $agent->commands()->open()->where('type', $type)->first();
        if ($open) {
            return $open;
        }

        return $this->create($agent, $type, $params, $by->id, $by->username, $batch);
    }

    /** @return array<string, mixed> the params the agent gets */
    private function params(Agent $agent, string $type, array $input): array
    {
        switch ($type) {
            case AgentCommand::RUSTDESK_UPGRADE:
                $version = (string) ($input['version'] ?? '');
                $assets = RustDeskReleases::assetsFor($version, $agent->os, $agent->arch);
                if (! $assets) {
                    throw new InvalidArgumentException(__('monitoring.cmd_error_no_installer', ['version' => $version]));
                }

                return ['version' => $version, 'assets' => $assets];
            case AgentCommand::OS_UPDATE:
                return ['reboot' => (bool) ($input['reboot'] ?? false)];
            case AgentCommand::REBOOT:
                $delay = (int) ($input['delay'] ?? 0);
                if ($delay < 0 || $delay > 86400) {
                    throw new InvalidArgumentException(__('monitoring.cmd_error_delay'));
                }

                return ['delay' => $delay, 'message' => Str::limit(trim((string) ($input['message'] ?? '')), 200, '')];
        }

        return [];
    }

    private function create(Agent $agent, string $type, array $params, ?int $userId, string $name, ?string $batch): AgentCommand
    {
        return $agent->commands()->create([
            'public_id' => (string) Str::ulid(),
            'type' => $type,
            'params' => $params,
            'status' => AgentCommand::QUEUED,
            'created_by' => $userId,
            'created_by_name' => $name,
            'batch' => $batch,
            'expires_at' => now()->addHours(AgentCommand::EXPIRES_HOURS[$type]),
        ]);
    }

    /** Cancel a command that hasn't been delivered yet. */
    public function cancel(AgentCommand $command): bool
    {
        if ($command->status !== AgentCommand::QUEUED) {
            return false;
        }
        $command->forceFill(['status' => AgentCommand::CANCELLED, 'finished_at' => now()])->save();

        return true;
    }

    /**
     * Commands for a report response. Expires and times out stale ones
     * first, queues a self-update when one is due, then marks what it hands
     * out as sent.
     *
     * @return list<array{id: string, type: string, params: object}>
     */
    public function deliver(Agent $agent): array
    {
        $this->sweep($agent);
        $this->queueSelfUpdate($agent);

        $query = $agent->commands()
            ->where(fn ($q) => $q->where('status', AgentCommand::QUEUED)
                ->orWhere(fn ($q) => $q->where('status', AgentCommand::SENT)->where('sent_at', '<', now()->subSeconds(self::RESEND_AFTER))))
            ->orderBy('id')
            ->limit(self::PER_REPORT);
        // Remote commands off: only the agent's own update goes out.
        if (! Monitoring::commandsEnabled()) {
            $query->where('type', AgentCommand::AGENT_UPDATE);
        }

        $out = [];
        foreach ($query->get() as $command) {
            $command->forceFill(['status' => AgentCommand::SENT, 'sent_at' => now()])->save();
            $out[] = ['id' => $command->public_id, 'type' => $command->type, 'params' => (object) ($command->params ?? [])];
        }

        return $out;
    }

    /** Expire queued commands past their time; fail delivered ones that never finished. */
    public function sweep(Agent $agent): void
    {
        $agent->commands()->where('status', AgentCommand::QUEUED)->where('expires_at', '<', now())
            ->update(['status' => AgentCommand::EXPIRED, 'finished_at' => now(), 'updated_at' => now()]);

        foreach ($agent->commands()->whereIn('status', [AgentCommand::SENT, AgentCommand::RUNNING])->get() as $command) {
            $since = $command->started_at ?? $command->sent_at;
            if ($since && $since->lt(now()->subSeconds(AgentCommand::TIMEOUTS[$command->type] ?? 3600))) {
                $command->forceFill([
                    'status' => AgentCommand::FAILED,
                    'finished_at' => now(),
                    'error' => __('monitoring.cmd_error_no_result'),
                ])->save();
            }
        }
    }

    /**
     * With automatic updates on, queue agent.update for an agent older than
     * the manifest, unless one is open or failed recently for that version.
     */
    public function queueSelfUpdate(Agent $agent): ?AgentCommand
    {
        if (! Monitoring::autoUpdate() || ! $agent->supports(AgentCommand::AGENT_UPDATE)) {
            return null;
        }

        return $this->selfUpdate($agent, null);
    }

    /** "Update agent" from the console, regardless of the automatic setting. */
    public function requestSelfUpdate(Agent $agent, User $by): ?AgentCommand
    {
        if (! $agent->supports(AgentCommand::AGENT_UPDATE)) {
            return null;
        }

        return $this->selfUpdate($agent, $by);
    }

    private function selfUpdate(Agent $agent, ?User $by): ?AgentCommand
    {
        $update = AgentInstall::updateFor($agent->platformKey());
        if (! $update || ! $agent->agent_version || version_compare($update['version'], $agent->agent_version, '<=')) {
            return null;
        }
        if ($agent->commands()->open()->where('type', AgentCommand::AGENT_UPDATE)->exists()) {
            return null;
        }
        $recentFailure = $agent->commands()->where('type', AgentCommand::AGENT_UPDATE)
            ->where('status', AgentCommand::FAILED)
            ->where('finished_at', '>', now()->subSeconds(self::UPDATE_RETRY_AFTER))
            ->get()->contains(fn (AgentCommand $c) => ($c->params['version'] ?? null) === $update['version']);
        if ($recentFailure && ! $by) {
            return null;
        }

        return $this->create($agent, AgentCommand::AGENT_UPDATE, $update, $by?->id, $by?->username ?? 'system', null);
    }

    /**
     * A result posted by the agent. Returns false when the command isn't
     * this agent's (404 to the agent, which then drops the result).
     */
    public function result(Agent $agent, string $publicId, array $data): bool
    {
        $command = $agent->commands()->where('public_id', $publicId)->first();
        if (! $command) {
            return false;
        }
        // Finished commands don't change: a late or repeated result is
        // accepted and ignored.
        if (! $command->isOpen()) {
            return true;
        }

        $status = $data['status'];
        $fill = ['status' => $status];
        if ($status === AgentCommand::RUNNING) {
            $fill['started_at'] = now();
            $fill['sent_at'] = $command->sent_at ?? now();
        } else {
            $fill += [
                'finished_at' => now(),
                'exit_code' => $data['exit_code'] ?? null,
                'output' => isset($data['output']) ? mb_strcut((string) $data['output'], -AgentCommand::MAX_OUTPUT) : null,
                'error' => isset($data['error']) ? Str::limit((string) $data['error'], 990) : null,
            ];
        }
        $command->forceFill($fill)->save();

        if ($status !== AgentCommand::RUNNING) {
            ConsoleAudit::system(
                'agent.command-result',
                $command->summary().' on '.$agent->displayName().': '.$status.($command->error ? ' ('.Str::limit($command->error, 200).')' : ''),
                'agent',
                (string) $agent->id,
            );
        }

        return true;
    }
}
