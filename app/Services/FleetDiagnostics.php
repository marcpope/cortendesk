<?php

namespace App\Services;

use App\Contracts\TcpProbe;
use App\Models\Device;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class FleetDiagnostics
{
    public function __construct(private TcpProbe $tcp) {}

    public function readiness(): array
    {
        $database = $this->databaseIsAvailable();
        $idServer = $database && $this->configuredServerIsAvailable('id_server', 21116);
        $relayServer = $database && $this->relayPoolIsAvailable();

        return [
            'ready' => $database && $idServer && $relayServer,
            'database' => $database,
            'id_server' => $idServer,
            'relay_server' => $relayServer,
        ];
    }

    public function report(): array
    {
        $database = $this->databaseIsAvailable();

        $versions = Device::query()->approved()
            ->selectRaw("COALESCE(NULLIF(version, ''), 'unknown') as version, COUNT(*) as count")
            ->groupBy('version')->orderByDesc('count')->get()
            ->map(fn ($row) => ['version' => (string) $row->version, 'count' => (int) $row->count])->all();
        $known = array_values(array_filter(array_column($versions, 'version'), fn ($version) => $version !== 'unknown'));
        usort($known, 'version_compare');
        $newest = $known === [] ? null : end($known);

        $schedulerAt = Cache::get('cortendesk:diagnostics:scheduler-heartbeat');
        try {
            $schedulerFresh = is_string($schedulerAt) && now()->diffInMinutes($schedulerAt, true) <= 3;
        } catch (Throwable) {
            $schedulerFresh = false;
            $schedulerAt = null;
        }

        $appHostConfigured = filter_var((string) config('app.url'), FILTER_VALIDATE_URL) !== false
            && parse_url((string) config('app.url'), PHP_URL_HOST) !== null;
        $websocketIdConfigured = trim((string) config('cortendesk.ws_id_url')) !== '' || $appHostConfigured;
        $websocketRelayConfigured = trim((string) config('cortendesk.ws_relay_url')) !== '' || $appHostConfigured;
        $mail = app(MailSettings::class);
        $smtpConfigured = $mail->isConfigured();
        $smtpObserved = trim((string) Setting::get('smtp_ok_at', '')) !== ''
            || trim((string) Setting::get('smtp_failed_at', '')) !== '';

        return [
            'generated_at' => now()->toIso8601String(),
            'application' => ['ok' => true, 'version' => (string) config('cortendesk.api_version')],
            'database' => ['ok' => $database],
            'services' => [
                'id_server' => $this->serverProbe('id_server', 21116),
                'relay_server' => $this->serverProbe('relay_server', 21117),
                'api' => ['ok' => true, 'mode' => 'local route', 'version_route' => '/api/version'],
                'websocket_bridge' => [
                    'ok' => (bool) config('cortendesk.native_webclient') && $websocketIdConfigured && $websocketRelayConfigured,
                    'id_configured' => $websocketIdConfigured,
                    'relay_configured' => $websocketRelayConfigured,
                    'note' => 'Readiness only; explicit endpoints or the APP_URL same-origin fallback are accepted. Remote endpoints are not contacted.',
                ],
            ],
            'scheduler' => ['ok' => $schedulerFresh, 'last_seen_at' => $schedulerAt],
            // Whether hbbs is following this console's device policy (#81, #82).
            'server_link' => ServerLink::status() + ['mode' => ServerLink::mode()],
            'fleet' => [
                'total' => Device::query()->approved()->count(),
                'online' => Device::query()->approved()->online()->count(),
                'offline' => Device::query()->approved()->offline()->count(),
                'pending' => Device::query()->pending()->count(),
                'possible_duplicates' => DuplicateDevices::in(Device::query()->approved())->count(),
                'silent_over_24h' => Device::query()->approved()
                    ->where(fn ($query) => $query->whereNull('last_online_at')->orWhere('last_online_at', '<', now()->subDay()))
                    ->count(),
                'latest_heartbeat_at' => Device::query()->approved()->max('last_online_at'),
                'versions' => array_map(fn ($row) => $row + [
                    'status' => $newest !== null && $row['version'] !== 'unknown' && version_compare($row['version'], $newest, '<')
                        ? 'Behind newest fleet version' : 'Newest or unknown',
                ], $versions),
                'newest_reported_version' => $newest,
            ],
            'smtp' => [
                'configured' => $smtpConfigured,
                'enabled' => $mail->isEnabled(),
                'healthy' => $smtpConfigured && $smtpObserved ? $mail->isHealthy() : null,
                'note' => match (true) {
                    ! $smtpConfigured => 'SMTP is not configured. No message is sent automatically.',
                    ! $smtpObserved => 'Configured, but no send result has been observed. No message is sent automatically.',
                    default => 'Health reflects the last observed send. No message is sent automatically.',
                },
            ],
        ];
    }

    public function sanitized(): array
    {
        $report = $this->report();

        foreach (['id_server', 'relay_server'] as $name) {
            $report['services'][$name] = [
                'configured' => $report['services'][$name]['configured'],
                'port' => $report['services'][$name]['port'],
                'ok' => $report['services'][$name]['ok'],
                'latency_ms' => $report['services'][$name]['latency_ms'],
            ];
        }

        return $report;
    }

    private function databaseIsAvailable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function configuredServerIsAvailable(string $key, int $defaultPort): bool
    {
        $server = $this->serverProbe($key, $defaultPort);

        return ! $server['configured'] || $server['ok'];
    }

    /**
     * Every configured relay in the active pool is required; an empty pool is
     * intentionally not a readiness dependency.
     */
    private function relayPoolIsAvailable(): bool
    {
        foreach (Setting::relayServers() as $relay) {
            [$host, $port] = $this->parseServer($relay['address'], 21117);

            if ($host !== '' && ! $this->tcp->check($host, $port, 1.0)['ok']) {
                return false;
            }
        }

        return true;
    }

    private function serverProbe(string $key, int $defaultPort): array
    {
        [$host, $port] = $this->parseServer((string) Setting::get($key, config('cortendesk.'.$key)), $defaultPort);
        if ($host === '') {
            return ['configured' => false, 'port' => $defaultPort, 'ok' => false, 'latency_ms' => null, 'error' => 'Not configured.'];
        }

        return ['configured' => true, 'port' => $port, 'host_label' => $this->hostLabel($host)]
            + $this->tcp->check($host, $port, 1.0);
    }

    private function parseServer(string $value, int $defaultPort): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', $defaultPort];
        }
        $parts = parse_url(str_contains($value, '://') ? $value : 'tcp://'.$value);

        return [(string) ($parts['host'] ?? ''), (int) ($parts['port'] ?? $defaultPort)];
    }

    private function hostLabel(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? 'configured IP address' : 'configured hostname';
    }
}
