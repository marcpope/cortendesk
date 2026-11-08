<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AgentSample;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Support\Monitoring;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the agent API does with enrollments, reports and inventories
 * (docs/monitoring/SPEC.md). Input arrives validated by the controller; this
 * keeps only the fields the console uses, so `latest` never stores anything
 * an agent made up.
 */
class AgentReports
{
    /** Most software entries kept from one inventory. */
    public const MAX_SOFTWARE = 5000;

    /**
     * Enroll a machine with a token. A machine already known by machine_uuid
     * keeps its row and history and gets a new secret.
     *
     * @return array{0: Agent, 1: string}|null the agent and its plain secret, or null for a bad token
     */
    public function enroll(string $plainToken, array $data, ?string $ip): ?array
    {
        return DB::transaction(function () use ($plainToken, $data, $ip) {
            $token = AgentEnrollmentToken::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $token || ! str_starts_with($plainToken, AgentEnrollmentToken::PREFIX) || ! $token->isUsable()) {
                return null;
            }

            $agent = Agent::query()->where('machine_uuid', $data['machine_uuid'])->first()
                ?? new Agent(['public_id' => (string) Str::ulid(), 'machine_uuid' => $data['machine_uuid'], 'enabled' => true]);
            $isNew = ! $agent->exists;

            $secret = $agent->rotateSecret();
            $agent->fill($this->identity($data) + [
                'os' => $data['os'] ?? $agent->os,
                'os_version' => $data['os_version'] ?? $agent->os_version,
                'arch' => $data['arch'] ?? $agent->arch,
                'enrollment_token_id' => $token->id,
                'last_ip' => $ip,
            ]);
            if ($token->device_group_id !== null) {
                $agent->device_group_id = $token->device_group_id;
            }
            $this->link($agent);
            $agent->save();

            $token->forceFill(['uses' => $token->uses + 1, 'last_used_at' => now()])->save();

            ConsoleAudit::system(
                $isNew ? 'agent.enroll' : 'agent.re-enroll',
                ($isNew ? 'Agent enrolled: ' : 'Agent re-enrolled: ').($agent->hostname ?: $agent->machine_uuid).' (token '.$token->name.')',
                'agent',
                (string) $agent->id,
            );

            return [$agent, $secret];
        });
    }

    /** Store a report. Returns whether the console wants an inventory now. */
    public function report(Agent $agent, array $data, ?string $ip): bool
    {
        $now = now();
        $previous = $agent->latest;
        $previousAt = $agent->last_seen_at;
        $latest = $this->latest($data);

        $agent->fill($this->identity($data) + [
            'latest' => $latest,
            'last_seen_at' => $now,
            'last_ip' => $ip,
        ]);
        $agent->capabilities = array_values(array_unique(array_filter(
            (array) ($data['capabilities'] ?? []),
            fn ($c) => is_string($c) && preg_match('/^[a-z][a-z0-9.]{0,31}$/', $c),
        )));
        if ($agent->device_id === null) {
            $this->link($agent);
        }
        $agent->save();

        [$rx, $tx] = $this->rates($previous, $latest, $previousAt, $now);
        $disk = $agent->fullestDisk();

        AgentSample::create([
            'agent_id' => $agent->id,
            'taken_at' => $now,
            'cpu_percent' => $latest['cpu']['percent'],
            'mem_used' => $latest['memory']['used'],
            'mem_total' => $latest['memory']['total'],
            'swap_used' => $latest['memory']['swap_used'],
            'disk_percent' => $disk['percent'] ?? null,
            'rx_bps' => $rx,
            'tx_bps' => $tx,
            'load1' => $latest['cpu']['load'][0] ?? null,
        ]);

        return $agent->inventory_hash === null
            || ($data['inventory_hash'] ?? null) !== $agent->inventory_hash;
    }

    public function inventory(Agent $agent, array $data): void
    {
        $software = array_slice((array) ($data['software'] ?? []), 0, self::MAX_SOFTWARE);

        $agent->forceFill([
            'inventory' => [
                'os' => (array) ($data['os'] ?? []),
                'hardware' => (array) ($data['hardware'] ?? []),
                'network' => array_values((array) ($data['network'] ?? [])),
                'software' => array_values($software),
                'local_admins' => array_values(array_map(fn ($u) => Str::limit((string) $u, 128, ''), array_slice((array) ($data['local_admins'] ?? []), 0, 64))),
                'battery' => is_array($data['battery'] ?? null) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : null, array_intersect_key($data['battery'], array_flip(['design_capacity', 'full_capacity', 'cycle_count']))) : null,
            ],
            'inventory_hash' => $data['hash'],
            'inventory_at' => now(),
        ])->save();
    }

    /**
     * Link an unlinked agent to its device. The RustDesk ID names exactly one
     * device, so it wins. Without it, RustDesk's sysinfo uuid is the same
     * machine id the agent sends; cloned machines share that id, so the
     * device seen most recently is taken.
     */
    public function link(Agent $agent): void
    {
        if ($agent->device_id !== null) {
            return;
        }

        $device = $agent->rustdesk_id
            ? Device::query()->where('rustdesk_id', $agent->rustdesk_id)->first()
            : null;
        $device ??= Device::query()->where('uuid', $agent->machine_uuid)->orderByDesc('last_online_at')->first();

        // One agent per device: a second machine claiming the same device
        // (a clone with a copied machine id) stays unlinked.
        if ($device && ! Agent::query()->where('device_id', $device->id)->whereKeyNot($agent->id)->exists()) {
            $agent->device_id = $device->id;
        }
    }

    /** Fields every request may refresh. */
    private function identity(array $data): array
    {
        return array_filter([
            'hostname' => $data['hostname'] ?? null,
            'rustdesk_id' => $data['rustdesk_id'] ?? null,
            'agent_version' => $data['agent_version'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** The report body reduced to the fields the console reads. */
    private function latest(array $data): array
    {
        $num = fn ($v) => is_numeric($v) ? $v + 0 : null;
        $int = fn ($v) => is_numeric($v) ? max(0, (int) $v) : null;

        $disks = [];
        foreach (array_slice((array) ($data['disks'] ?? []), 0, 64) as $disk) {
            $disks[] = [
                'mount' => Str::limit((string) ($disk['mount'] ?? ''), 255, ''),
                'fs' => Str::limit((string) ($disk['fs'] ?? ''), 32, ''),
                'total' => $int($disk['total'] ?? null) ?? 0,
                'used' => $int($disk['used'] ?? null) ?? 0,
            ];
        }

        $load = array_values(array_filter(array_map($num, array_slice((array) ($data['cpu']['load'] ?? []), 0, 3)), fn ($v) => $v !== null));
        $cpu = $num($data['cpu']['percent'] ?? null);

        return [
            'uptime' => $int($data['uptime'] ?? null),
            'cpu' => [
                'percent' => $cpu === null ? null : max(0, min(100, $cpu)),
                'cores' => $int($data['cpu']['cores'] ?? null),
                'load' => $load,
            ],
            'memory' => [
                'total' => $int($data['memory']['total'] ?? null),
                'used' => $int($data['memory']['used'] ?? null),
                'swap_total' => $int($data['memory']['swap_total'] ?? null),
                'swap_used' => $int($data['memory']['swap_used'] ?? null),
            ],
            'disks' => $disks,
            'network' => [
                'rx_bytes' => $int($data['network']['rx_bytes'] ?? null),
                'tx_bytes' => $int($data['network']['tx_bytes'] ?? null),
                'rx_packets' => $int($data['network']['rx_packets'] ?? null),
                'tx_packets' => $int($data['network']['tx_packets'] ?? null),
            ],
            'rustdesk' => [
                'installed' => (bool) ($data['rustdesk']['installed'] ?? false),
                'running' => (bool) ($data['rustdesk']['running'] ?? false),
                'version' => Str::limit((string) ($data['rustdesk']['version'] ?? ''), 32, ''),
            ],
            'users' => array_values(array_map(fn ($u) => Str::limit((string) $u, 64, ''), array_slice((array) ($data['users'] ?? []), 0, 32))),
            'reboot_pending' => (bool) ($data['reboot_pending'] ?? false),
            'health' => $this->health((array) ($data['health'] ?? [])),
        ];
    }

    /**
     * The slow checks. A key the agent left out stays out: "not reported"
     * and "off" must not look the same on screen.
     */
    private function health(array $h): array
    {
        $str = fn ($v, $max = 255) => Str::limit((string) $v, $max, '');
        $bool = fn ($v) => is_bool($v) ? $v : null;
        $out = [];

        if (is_array($h['antivirus'] ?? null)) {
            $out['antivirus'] = array_values(array_map(fn ($a) => array_filter([
                'name' => $str($a['name'] ?? ''),
                'enabled' => $bool($a['enabled'] ?? null),
                'up_to_date' => $bool($a['up_to_date'] ?? null),
            ], fn ($v) => $v !== null), array_slice(array_filter($h['antivirus'], 'is_array'), 0, 8)));
        }
        if (is_bool($h['firewall'] ?? null)) {
            $out['firewall'] = $h['firewall'];
        }
        if (is_array($h['encryption'] ?? null)) {
            $out['encryption'] = array_values(array_map(fn ($e) => array_filter([
                'volume' => $str($e['volume'] ?? '', 64),
                'encrypted' => $bool($e['encrypted'] ?? null),
                'method' => $str($e['method'] ?? '', 64),
            ], fn ($v) => $v !== null), array_slice(array_filter($h['encryption'], 'is_array'), 0, 32)));
        }
        if (is_array($h['updates'] ?? null)) {
            $out['updates'] = array_filter([
                'missing' => is_numeric($h['updates']['missing'] ?? null) ? max(0, (int) $h['updates']['missing']) : null,
                'failed' => is_numeric($h['updates']['failed'] ?? null) ? max(0, (int) $h['updates']['failed']) : null,
                'last_installed' => isset($h['updates']['last_installed']) ? $str($h['updates']['last_installed'], 32) : null,
            ], fn ($v) => $v !== null);
        }
        if (is_array($h['smart'] ?? null)) {
            $out['smart'] = array_values(array_map(fn ($d) => array_filter([
                'device' => $str($d['device'] ?? '', 128),
                'model' => $str($d['model'] ?? '', 128),
                'healthy' => $bool($d['healthy'] ?? null),
                'status' => $str($d['status'] ?? '', 64),
            ], fn ($v) => $v !== null), array_slice(array_filter($h['smart'], 'is_array'), 0, 32)));
        }
        if (is_array($h['services'] ?? null)) {
            $out['services'] = array_values(array_map(fn ($s) => [
                'name' => $str($s['name'] ?? '', 128),
                'display' => $str($s['display'] ?? '', 255),
                'state' => in_array($s['state'] ?? null, ['running', 'stopped', 'missing'], true) ? $s['state'] : 'other',
            ], array_slice(array_filter($h['services'], 'is_array'), 0, 64)));
        }
        if (is_numeric($h['event_errors_24h'] ?? null)) {
            $out['event_errors_24h'] = max(0, (int) $h['event_errors_24h']);
        }
        if (isset($h['wifi_ssid']) && is_string($h['wifi_ssid'])) {
            $out['wifi_ssid'] = $str($h['wifi_ssid'], 64);
        }
        if (is_numeric($h['collected_at'] ?? null)) {
            $out['collected_at'] = (int) $h['collected_at'];
        }

        return $out;
    }

    /**
     * Bytes per second from two cumulative counters. Null on the first report
     * and after a reboot, when the counters start again from zero.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function rates(?array $previous, array $latest, ?CarbonInterface $previousAt, CarbonInterface $now): array
    {
        if (! $previous || ! $previousAt) {
            return [null, null];
        }

        $seconds = $now->getTimestamp() - $previousAt->getTimestamp();
        if ($seconds <= 0 || $seconds > 3 * Monitoring::MAX_INTERVAL) {
            return [null, null];
        }

        if (Agent::networkBytesUnreported($latest['network'] ?? null)) {
            return [null, null];
        }

        $rate = function (string $key) use ($previous, $latest, $seconds): ?int {
            $before = $previous['network'][$key] ?? null;
            $after = $latest['network'][$key] ?? null;

            return is_int($before) && is_int($after) && $after >= $before
                ? intdiv($after - $before, $seconds)
                : null;
        };

        return [$rate('rx_bytes'), $rate('tx_bytes')];
    }
}
