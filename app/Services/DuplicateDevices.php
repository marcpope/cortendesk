<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceDuplicateDismissal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Likely duplicate device rows (issue #91).
 *
 * RustDesk gives a machine a new ID after a reinstall, a cloned image or a
 * UUID mismatch at the ID server. The fleet then holds two rows for one
 * machine: the old one goes quiet, the new one reports in. Clients send no
 * serial number, so matching uses what sysinfo carries, strongest first:
 *
 *  - uuid: the machine UUID the client reports. Two IDs, one UUID.
 *  - hostname_user: same hostname, OS user and platform.
 *
 * Hostname alone is not enough: two branch offices can both have a
 * "RECEPTION". An empty OS user (a service with nobody signed in) is no
 * evidence either. Hostnames installs share by default (localhost, ubuntu,
 * MacBook-Pro) never match, and phones and tablets match on UUID only: their
 * hostname is a model name many people share. Windows' random DESKTOP-XXXXXXX
 * names do match: a reinstall draws a new one, so a shared one means the same
 * install or a clone. Pairs an operator dismissed are dropped.
 *
 * Only rows in the given scope take part, so a scoped user never learns about
 * a device outside their fleet. Recycle-bin and pending devices are outside
 * every scope callers pass, which is also how a flag clears: delete one twin.
 *
 * Cost is fixed per call: two grouped queries that return only values shared
 * by more than one device, then one fetch of those rows and one of their
 * dismissals. Nothing runs per device.
 */
final class DuplicateDevices
{
    public const REASONS = [
        'uuid' => 'same device UUID',
        'hostname_user' => 'same hostname and user',
    ];

    private const STRENGTH = ['uuid' => 2, 'hostname_user' => 1];

    /** Lower-cased hostname and OS user, the same keys as matchKey(). */
    private const HOST_SQL = 'LOWER(TRIM(devices.hostname))';

    private const USER_SQL = 'LOWER(TRIM(devices.username))';

    private const COLUMNS = [
        'devices.id', 'devices.rustdesk_id', 'devices.uuid', 'devices.hostname', 'devices.username',
        'devices.os', 'devices.alias', 'devices.last_online_at', 'devices.created_at',
    ];

    /** Default and placeholder hostnames many unrelated machines carry. */
    private const GENERIC_HOSTNAMES = [
        'localhost', 'localhost.localdomain', 'localdomain', 'ubuntu', 'debian', 'raspberrypi',
        'kali', 'fedora', 'centos', 'archlinux', 'linux', 'android', 'windows', 'pc', 'desktop',
        'laptop', 'computer', 'workstation', 'server', 'host', 'user-pc', 'admin-pc', 'vm',
        'unknown', 'default', 'none', '(none)',
    ];

    /** Apple's default names, optionally with the "-2" macOS adds on a clash. */
    private const GENERIC_PATTERN = '/^(macbook(-?pro|-?air)?|imac(-?pro)?|mac-?(mini|studio|pro)|iphone|ipad)(-\d+)?(\.local)?$/';

    /** @var array<int, array<int, string>> device id => [twin id => reason] */
    private array $pairs = [];

    /** @var array<int, Device> */
    private array $devices = [];

    /** Duplicates among every device in $scope. */
    public static function in(Builder $scope): self
    {
        $uuids = (clone $scope)->toBase()
            ->select('devices.uuid')
            ->where('devices.uuid', '!=', '')
            ->groupBy('devices.uuid')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('uuid')
            ->all();

        $hosts = (clone $scope)->toBase()
            ->selectRaw(self::HOST_SQL.' as host_key')
            ->whereNotNull('devices.hostname')
            ->whereNotNull('devices.username')
            ->whereRaw(self::USER_SQL." != ''")
            ->groupByRaw(self::HOST_SQL.', '.self::USER_SQL)
            ->havingRaw('COUNT(*) > 1')
            ->pluck('host_key')
            ->map(fn ($host) => (string) $host)
            ->reject(fn (string $host) => self::isGenericHostname($host))
            ->unique()
            ->values()
            ->all();

        if ($uuids === [] && $hosts === []) {
            return new self(collect());
        }

        $rows = (clone $scope)->setEagerLoads([])
            ->where(function (Builder $q) use ($uuids, $hosts) {
                if ($uuids !== []) {
                    $q->whereIn('devices.uuid', $uuids);
                }
                if ($hosts !== []) {
                    $q->orWhereIn(DB::raw(self::HOST_SQL), $hosts);
                }
            })
            ->get(self::COLUMNS);

        return new self($rows);
    }

    /** Duplicates of one device among the devices in $scope. */
    public static function of(Device $device, Builder $scope): self
    {
        if ($device->trashed()) {
            return new self(collect());
        }

        $uuid = (string) $device->uuid;
        $host = self::matchKey($device);

        if ($uuid === '' && $host === null) {
            return new self(collect());
        }

        $rows = (clone $scope)->setEagerLoads([])
            ->where(function (Builder $q) use ($uuid, $host, $device) {
                if ($uuid !== '') {
                    $q->where('devices.uuid', $uuid);
                }
                if ($host !== null) {
                    $q->orWhere(fn (Builder $h) => $h
                        ->whereRaw(self::HOST_SQL.' = ?', [self::normalize((string) $device->hostname)])
                        ->whereRaw(self::USER_SQL.' = ?', [self::normalize((string) $device->username)]));
                }
            })
            ->get(self::COLUMNS);

        // Outside the scope (pending, or not this viewer's): nothing to say.
        if (! $rows->contains('id', $device->id)) {
            return new self(collect());
        }

        return new self($rows);
    }

    /** @param  Collection<int, Device>  $rows */
    private function __construct(Collection $rows)
    {
        $this->devices = $rows->keyBy('id')->all();

        foreach ($rows->filter(fn (Device $d) => (string) $d->uuid !== '')->groupBy('uuid') as $group) {
            $this->pairUp($group, fn () => 'uuid');
        }

        $byHost = $rows->filter(fn (Device $d) => self::matchKey($d) !== null)->groupBy(fn (Device $d) => self::matchKey($d));
        foreach ($byHost as $group) {
            $this->pairUp($group, fn () => 'hostname_user');
        }

        $this->dropDismissed();
    }

    /** @param  Collection<int, Device>  $group */
    private function pairUp(Collection $group, callable $reason): void
    {
        $group = $group->values();
        for ($i = 0; $i < $group->count(); $i++) {
            for ($j = $i + 1; $j < $group->count(); $j++) {
                $a = $group[$i];
                $b = $group[$j];
                $why = $reason($a, $b);
                $current = $this->pairs[$a->id][$b->id] ?? null;
                if ($current === null || self::STRENGTH[$why] > self::STRENGTH[$current]) {
                    $this->pairs[$a->id][$b->id] = $why;
                    $this->pairs[$b->id][$a->id] = $why;
                }
            }
        }
    }

    private function dropDismissed(): void
    {
        if ($this->pairs === []) {
            return;
        }

        $ids = array_keys($this->pairs);
        $dismissed = DeviceDuplicateDismissal::query()
            ->whereIn('device_id', $ids)
            ->whereIn('other_device_id', $ids)
            ->get(['device_id', 'other_device_id']);

        foreach ($dismissed as $row) {
            unset($this->pairs[$row->device_id][$row->other_device_id], $this->pairs[$row->other_device_id][$row->device_id]);
        }

        $this->pairs = array_filter($this->pairs);
    }

    /**
     * Grouping key for the hostname signal: hostname, OS user and platform,
     * or null when the hostname is empty or generic, the user is empty, or
     * the device is a phone or tablet.
     */
    private static function matchKey(Device $device): ?string
    {
        $host = self::normalize((string) $device->hostname);
        $user = self::normalize((string) $device->username);
        $platform = $device->platform();

        if ($host === '' || $user === '' || self::isGenericHostname($host) || in_array($platform, ['android', 'ios'], true)) {
            return null;
        }

        return $host."\0".$user."\0".$platform;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public static function isGenericHostname(string $hostname): bool
    {
        $host = self::normalize($hostname);

        return $host === ''
            || in_array($host, self::GENERIC_HOSTNAMES, true)
            || preg_match(self::GENERIC_PATTERN, $host) === 1;
    }

    public function has(int $id): bool
    {
        return isset($this->pairs[$id]);
    }

    /** @return list<int> every flagged device id */
    public function ids(): array
    {
        return array_keys($this->pairs);
    }

    public function count(): int
    {
        return count($this->pairs);
    }

    /**
     * The devices $id looks like a duplicate of, strongest reason first, then
     * most recently seen.
     *
     * @return list<array{device: Device, reason: string, label: string}>
     */
    public function twinsOf(int $id): array
    {
        $twins = [];
        foreach ($this->pairs[$id] ?? [] as $otherId => $reason) {
            $twins[] = ['device' => $this->devices[$otherId], 'reason' => $reason, 'label' => self::REASONS[$reason]];
        }

        usort($twins, fn ($x, $y) => [self::STRENGTH[$y['reason']], $y['device']->last_online_at?->getTimestamp() ?? 0]
            <=> [self::STRENGTH[$x['reason']], $x['device']->last_online_at?->getTimestamp() ?? 0]);

        return $twins;
    }

    /** @return list<string> RustDesk IDs of the twins, for API payloads */
    public function rustdeskIdsOf(int $id): array
    {
        return array_map(fn ($t) => (string) $t['device']->rustdesk_id, $this->twinsOf($id));
    }

    /** One line for a tooltip: "Possible duplicate of 123 (same hostname)". */
    public function summary(int $id): string
    {
        $parts = array_map(fn ($t) => $t['device']->rustdesk_id.' ('.$t['label'].')', $this->twinsOf($id));

        return $parts === [] ? '' : 'Possible duplicate of '.implode(', ', $parts);
    }

    /**
     * Flagged ids mapped to a cluster number, so a list can sort twins next to
     * each other. Clusters are connected pairs; the one seen most recently
     * comes first.
     *
     * @return array<int, int> device id => cluster position
     */
    public function clusterOrder(): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        foreach ($this->pairs as $a => $twins) {
            foreach (array_keys($twins) as $b) {
                $rootA = $find($a);
                $rootB = $find($b);
                if ($rootA !== $rootB) {
                    $parent[$rootA] = $rootB;
                }
            }
        }

        $clusters = [];
        foreach (array_keys($this->pairs) as $id) {
            $clusters[$find($id)][] = $id;
        }

        $latest = fn (array $ids) => max(array_map(fn ($id) => $this->devices[$id]->last_online_at?->getTimestamp() ?? 0, $ids));
        usort($clusters, fn ($x, $y) => $latest($y) <=> $latest($x) ?: min($x) <=> min($y));

        $order = [];
        foreach ($clusters as $position => $ids) {
            foreach ($ids as $id) {
                $order[$id] = $position;
            }
        }

        return $order;
    }
}
