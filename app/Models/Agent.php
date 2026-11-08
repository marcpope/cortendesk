<?php

namespace App\Models;

use App\Support\Monitoring;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A machine running the CortenDesk Agent (docs/monitoring/SPEC.md).
 *
 * The agent authenticates with "<public_id>.<secret>"; only sha256(secret)
 * is stored. `latest` holds the last report as sent, so list screens read one
 * row per agent and never touch agent_samples.
 */
#[Fillable([
    'public_id', 'secret_hash', 'device_id', 'device_group_id', 'enrollment_token_id',
    'machine_uuid', 'rustdesk_id', 'hostname', 'os', 'os_version', 'arch', 'agent_version',
    'enabled', 'last_seen_at', 'last_ip', 'latest', 'watch_services', 'inventory', 'inventory_hash', 'inventory_at',
])]
#[Hidden(['secret_hash'])]
class Agent extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'inventory_at' => 'datetime',
            'latest' => 'array',
            'watch_services' => 'array',
            'acknowledged_issues' => 'array',
            'capabilities' => 'array',
            'inventory' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'device_group_id');
    }

    public function enrollmentToken(): BelongsTo
    {
        return $this->belongsTo(AgentEnrollmentToken::class, 'enrollment_token_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(AgentSample::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /** Whether this agent's build runs a command type (from its last report). */
    public function supports(string $type): bool
    {
        return in_array($type, (array) ($this->capabilities ?? []), true);
    }

    /**
     * The network adapter counts packets but not bytes (QEMU's emulated
     * e1000 on Windows does this), so traffic can't be shown. Older agents
     * send no packet counts, so this stays false for them.
     */
    public static function networkBytesUnreported(?array $network): bool
    {
        $network ??= [];

        return ($network['rx_bytes'] ?? null) === 0 && ($network['tx_bytes'] ?? null) === 0
            && (($network['rx_packets'] ?? 0) > 0 || ($network['tx_packets'] ?? 0) > 0);
    }

    /** The manifest key for this agent's build: "windows-amd64", "darwin-universal". */
    public function platformKey(): ?string
    {
        if (! $this->os) {
            return null;
        }

        return $this->os === 'darwin' ? 'darwin-universal' : $this->os.'-'.($this->arch ?: 'amd64');
    }

    /** A new secret; returns the plain value, stores only its hash. */
    public function rotateSecret(): string
    {
        $secret = Str::random(48);
        $this->secret_hash = hash('sha256', $secret);

        return $secret;
    }

    /** The agent a bearer value "<public_id>.<secret>" belongs to, or null. */
    public static function fromBearer(?string $bearer): ?self
    {
        if (! $bearer || ! str_contains($bearer, '.')) {
            return null;
        }

        [$publicId, $secret] = explode('.', $bearer, 2);
        $agent = static::query()->where('public_id', $publicId)->first();

        return $agent && hash_equals($agent->secret_hash, hash('sha256', $secret)) ? $agent : null;
    }

    /**
     * Device-group access, the same rule as Devices: a linked agent follows
     * its device (owner or group), an unlinked one the group its enrollment
     * token assigned.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesAllDevices()) {
            return $query;
        }

        $groupIds = $user->accessibleDeviceGroupIds();

        return $query->where(function (Builder $q) use ($groupIds, $user) {
            $q->whereHas('device', fn (Builder $d) => $d->ownershipVisibleTo($user));
            if ($groupIds !== []) {
                $q->orWhere(fn (Builder $u) => $u->whereNull('device_id')->whereIn('device_group_id', $groupIds));
            }
        });
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subSeconds(Monitoring::onlineWindow()));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(Monitoring::onlineWindow()));
    }

    /**
     * Where the agent's page lives: the device page's Monitoring tab when
     * linked and the user can open devices, else the standalone agent page.
     */
    public function url(?User $user = null): string
    {
        $user ??= auth()->user();

        return $this->device_id && $user?->consoleAllows('device')
            ? route('devices.show', ['device' => $this->device_id, 'tab' => 'monitoring'])
            : route('monitoring.agent', $this->id);
    }

    /** The console's platform slug, for <x-platform-icon>. */
    public function platform(): string
    {
        return match ($this->os) {
            'windows' => 'windows',
            'darwin' => 'macos',
            'linux' => 'linux',
            default => 'unknown',
        };
    }

    public function displayName(): string
    {
        return $this->device?->alias ?: ($this->hostname ?: $this->public_id);
    }

    public function cpuPercent(): ?float
    {
        $value = $this->latest['cpu']['percent'] ?? null;

        return is_numeric($value) ? round((float) $value, 1) : null;
    }

    public function memoryPercent(): ?float
    {
        $total = (float) ($this->latest['memory']['total'] ?? 0);
        $used = (float) ($this->latest['memory']['used'] ?? 0);

        return $total > 0 ? round($used / $total * 100, 1) : null;
    }

    /** The fullest volume as ['mount', 'total', 'used', 'percent'], or null. */
    public function fullestDisk(): ?array
    {
        $fullest = null;
        foreach ($this->disks() as $disk) {
            if ($fullest === null || $disk['percent'] > $fullest['percent']) {
                $fullest = $disk;
            }
        }

        return $fullest;
    }

    /** @return array<int, array{mount: string, fs: string, total: int, used: int, percent: float}> */
    public function disks(): array
    {
        $out = [];
        foreach ((array) ($this->latest['disks'] ?? []) as $disk) {
            $total = (int) ($disk['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $used = (int) ($disk['used'] ?? 0);
            $out[] = [
                'mount' => (string) ($disk['mount'] ?? ''),
                'fs' => (string) ($disk['fs'] ?? ''),
                'total' => $total,
                'used' => $used,
                'percent' => round($used / $total * 100, 1),
            ];
        }

        return $out;
    }

    /** Volumes under this percent free are flagged. */
    public const LOW_FREE_PERCENT = 10;

    /**
     * Problems for the banner and the dashboard's "needs attention", minus
     * the ones an admin acknowledged. Danger first.
     *
     * @return list<array{key: string, level: string, text: string}>
     */
    public function issues(): array
    {
        return array_values(array_filter($this->allIssues(), fn ($i) => ! $i['acknowledged']));
    }

    /** Current issues an admin acknowledged, for the "acknowledged" list. */
    public function acknowledgedIssues(): array
    {
        return array_values(array_filter($this->allIssues(), fn ($i) => $i['acknowledged']));
    }

    /**
     * Every current issue with a stable key, so an acknowledgement sticks to
     * the same problem across reports. An acknowledgement lasts until it is
     * restored, including through reports that leave the check out.
     *
     * @return list<array{key: string, level: string, text: string, acknowledged: bool}>
     */
    public function allIssues(): array
    {
        $health = $this->latest['health'] ?? [];
        $issues = [];
        $add = function (string $key, string $level, string $text) use (&$issues) {
            $issues[] = ['key' => Str::limit($key, 191, ''), 'level' => $level, 'text' => $text];
        };

        foreach ((array) ($health['services'] ?? []) as $service) {
            if (($service['state'] ?? '') === 'stopped') {
                $add('service_stopped:'.$service['name'], 'danger', __('monitoring.issue_service_stopped', ['name' => $service['display'] ?: $service['name']]));
            }
        }
        foreach ((array) ($health['smart'] ?? []) as $drive) {
            if (($drive['healthy'] ?? true) === false) {
                $add('smart:'.$drive['device'], 'danger', __('monitoring.issue_smart', ['drive' => $drive['model'] ?: $drive['device']]));
            }
        }
        foreach ((array) ($health['antivirus'] ?? []) as $av) {
            if (($av['enabled'] ?? true) === false) {
                $add('av_off:'.$av['name'], 'danger', __('monitoring.issue_av_off', ['name' => $av['name']]));
            } elseif (($av['up_to_date'] ?? true) === false) {
                $add('av_stale:'.$av['name'], 'warning', __('monitoring.issue_av_stale', ['name' => $av['name']]));
            }
        }
        if (($health['firewall'] ?? true) === false) {
            $add('firewall_off', 'warning', __('monitoring.issue_firewall_off'));
        }
        foreach ($this->disks() as $disk) {
            if (100 - $disk['percent'] < self::LOW_FREE_PERCENT) {
                $add('low_disk:'.$disk['mount'], 'warning', __('monitoring.issue_low_disk', ['mount' => $disk['mount'], 'percent' => self::LOW_FREE_PERCENT]));
            }
        }
        if (($health['updates']['failed'] ?? 0) > 0) {
            $add('updates_failed', 'warning', trans_choice('monitoring.issue_updates_failed', $health['updates']['failed'], ['count' => $health['updates']['failed']]));
        }
        if (($this->latest['rustdesk']['installed'] ?? false) && ! ($this->latest['rustdesk']['running'] ?? false)) {
            $add('rustdesk_stopped', 'warning', __('monitoring.issue_rustdesk_stopped'));
        }

        $acked = (array) ($this->acknowledged_issues ?? []);
        foreach ($issues as &$issue) {
            $issue['acknowledged'] = isset($acked[$issue['key']]);
        }
        unset($issue);

        usort($issues, fn ($a, $b) => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

        return $issues;
    }

    /** The first usable IPv4 address and its interface, from the inventory. */
    public function primaryInterface(): ?array
    {
        foreach ((array) ($this->inventory['network'] ?? []) as $nic) {
            foreach ((array) ($nic['ips'] ?? []) as $ip) {
                $addr = strtok((string) $ip, '/');
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! str_starts_with($addr, '127.') && ! str_starts_with($addr, '169.254.')) {
                    return ['name' => (string) ($nic['name'] ?? ''), 'ip' => $addr, 'mac' => (string) ($nic['mac'] ?? '')];
                }
            }
        }

        return null;
    }

    public function uptimeSeconds(): ?int
    {
        $value = $this->latest['uptime'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
