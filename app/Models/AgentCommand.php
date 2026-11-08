<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One command queued for an agent (docs/monitoring/SPEC.md "Commands").
 * The agent sees `public_id`, never the row id.
 */
#[Fillable([
    'public_id', 'agent_id', 'type', 'params', 'status', 'created_by', 'created_by_name', 'batch',
    'expires_at', 'sent_at', 'started_at', 'finished_at', 'exit_code', 'output', 'error',
])]
class AgentCommand extends Model
{
    public const RUSTDESK_UPGRADE = 'rustdesk.upgrade';

    public const OS_UPDATE = 'os.update';

    public const REBOOT = 'reboot';

    public const AGENT_UPDATE = 'agent.update';

    /** Types an admin picks; agent.update is queued by the console itself. */
    public const ACTIONS = [self::RUSTDESK_UPGRADE, self::OS_UPDATE, self::REBOOT];

    /**
     * How long after delivery a command may go without a final result
     * before it is marked failed. Longer than the agent's own timeout.
     */
    public const TIMEOUTS = [
        self::RUSTDESK_UPGRADE => 90 * 60,
        self::OS_UPDATE => 5 * 3600,
        self::REBOOT => 15 * 60,
        self::AGENT_UPDATE => 30 * 60,
    ];

    /** Default hours a queued command waits for the agent. */
    public const EXPIRES_HOURS = [
        self::RUSTDESK_UPGRADE => 24,
        self::OS_UPDATE => 24,
        self::REBOOT => 1,
        self::AGENT_UPDATE => 24 * 7,
    ];

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Not finished yet. */
    public const OPEN = [self::QUEUED, self::SENT, self::RUNNING];

    /** Most output kept per command, matching the agent's 16 KB tail. */
    public const MAX_OUTPUT = 16384;

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'exit_code' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** "Upgrade RustDesk to 1.5.1", for lists and the audit log. */
    public function summary(): string
    {
        $p = (array) $this->params;

        return match ($this->type) {
            self::RUSTDESK_UPGRADE => __('monitoring.cmd_summary_rustdesk', ['version' => $p['version'] ?? '?']),
            self::OS_UPDATE => ($p['reboot'] ?? false) ? __('monitoring.cmd_summary_os_update_reboot') : __('monitoring.cmd_summary_os_update'),
            self::REBOOT => ($p['delay'] ?? 0) > 60
                ? __('monitoring.cmd_summary_reboot_in', ['minutes' => intdiv((int) $p['delay'], 60)])
                : __('monitoring.cmd_summary_reboot'),
            self::AGENT_UPDATE => __('monitoring.cmd_summary_agent_update', ['version' => $p['version'] ?? '?']),
            default => $this->type,
        };
    }
}
