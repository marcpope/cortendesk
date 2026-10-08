@extends('layouts.app')

@section('title', 'Fleet diagnostics')

@section('content')
    {{-- The page title comes from the layout; this row carries the description
         and the export button only. --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted mb-0">Bounded probes and fleet signals. No test email is sent automatically.</p>
        <a href="{{ route('diagnostics.export') }}" class="btn btn-sm btn-outline-light">
            <i class="ri-download-2-line me-1"></i>Sanitized JSON
        </a>
    </div>

    @php
        $checks = [
            ['label' => 'Application', 'ok' => $report['application']['ok'], 'detail' => 'CortenDesk v'.$report['application']['version']],
            ['label' => 'Database', 'ok' => $report['database']['ok'], 'detail' => $report['database']['ok'] ? 'Query completed.' : 'Query failed.'],
            ['label' => 'ID server', 'ok' => $report['services']['id_server']['ok'], 'detail' => $report['services']['id_server']['configured'] ? $report['services']['id_server']['host_label'].' on port '.$report['services']['id_server']['port'] : 'Not configured.'],
            ['label' => 'Relay server', 'ok' => $report['services']['relay_server']['ok'], 'detail' => $report['services']['relay_server']['configured'] ? $report['services']['relay_server']['host_label'].' on port '.$report['services']['relay_server']['port'] : 'Not configured.'],
            ['label' => 'Public API', 'ok' => $report['services']['api']['ok'], 'detail' => 'Local version route '.$report['services']['api']['version_route'].' is registered.'],
            ['label' => 'WebSocket bridge', 'ok' => $report['services']['websocket_bridge']['ok'], 'detail' => $report['services']['websocket_bridge']['note']],
            ['label' => 'Scheduler', 'ok' => $report['scheduler']['ok'], 'detail' => $report['scheduler']['ok'] ? 'Heartbeat is fresh.' : 'No fresh scheduler heartbeat.'],
            ['label' => 'ID server link', 'ok' => $report['server_link']['ok'], 'detail' => $report['server_link']['note']
                .($report['server_link']['agent'] ? ' ('.$report['server_link']['agent'].')' : '')
                .($report['server_link']['mode'] === 'approved' ? ' Server access: approved devices only.' : '')],
            ['label' => 'SMTP', 'ok' => $report['smtp']['configured'] ? $report['smtp']['healthy'] : null, 'detail' => $report['smtp']['note']],
        ];
    @endphp

    <div class="row g-3">
        @foreach ($checks as $check)
            @php
                $status = $check['ok'] === null ? 'unknown' : ($check['ok'] ? 'ok' : 'failed');
                $tone = $status === 'ok' ? 'success' : ($status === 'failed' ? 'danger' : 'secondary');
            @endphp
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2">
                            <strong>{{ $check['label'] }}</strong>
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}">{{ ucfirst($status) }}</span>
                        </div>
                        <p class="text-muted fs-13 mb-0 mt-2">{{ $check['detail'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mt-3">
        <div class="card-header"><h5 class="card-title mb-0">Fleet summary</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                @foreach (['total' => 'Devices', 'online' => 'Online', 'offline' => 'Offline', 'silent_over_24h' => 'Silent 24h+', 'pending' => 'Pending', 'possible_duplicates' => 'Possible duplicates'] as $key => $label)
                    <div class="col-6 col-lg">
                        <div class="text-muted fs-13">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">
                            @if ($key === 'possible_duplicates' && $report['fleet'][$key] > 0)
                                <a href="{{ route('devices', ['status' => 'duplicates']) }}" title="List them on the Devices page">{{ $report['fleet'][$key] }}</a>
                            @else
                                {{ $report['fleet'][$key] }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="text-muted fs-13">
                Version status is relative to the newest client currently reporting in this fleet, not a vendor release feed.
            </p>

            @if ($report['fleet']['versions'])
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Client version</th><th>Devices</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($report['fleet']['versions'] as $version)
                                <tr>
                                    <td class="rd-mono">{{ $version['version'] }}</td>
                                    <td>{{ $version['count'] }}</td>
                                    <td>{{ $version['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">No client versions have been reported.</p>
            @endif
        </div>
    </div>
@endsection
