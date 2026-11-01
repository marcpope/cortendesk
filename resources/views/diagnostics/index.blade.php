@extends('layouts.app')

@section('title', __('diagnostics.fleet_diagnostics'))

@section('content')
    {{-- The page title comes from the layout; this row carries the description
         and the export button only. --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted mb-0">{{ __('diagnostics.bounded_probes_and_fleet_signals') }}</p>
        <a href="{{ route('diagnostics.export') }}" class="btn btn-sm btn-outline-light">
            <i class="ri-download-2-line me-1"></i>{{ __('diagnostics.sanitized_json') }}
        </a>
    </div>

    @php
        $checks = [
            ['label' => __('diagnostics.application'), 'ok' => $report['application']['ok'], 'detail' => __('diagnostics.app_version', ['version' => $report['application']['version']])],
            ['label' => __('diagnostics.database'), 'ok' => $report['database']['ok'], 'detail' => $report['database']['ok'] ? __('diagnostics.query_completed') : __('diagnostics.query_failed')],
            ['label' => __('diagnostics.id_server'), 'ok' => $report['services']['id_server']['ok'], 'detail' => $report['services']['id_server']['configured'] ? __('diagnostics.host_on_port', ['host' => \App\Support\Label::text($report['services']['id_server']['host_label']), 'port' => $report['services']['id_server']['port']]) : __('diagnostics.not_configured')],
            ['label' => __('diagnostics.relay_server'), 'ok' => $report['services']['relay_server']['ok'], 'detail' => $report['services']['relay_server']['configured'] ? __('diagnostics.host_on_port', ['host' => \App\Support\Label::text($report['services']['relay_server']['host_label']), 'port' => $report['services']['relay_server']['port']]) : __('diagnostics.not_configured')],
            ['label' => __('diagnostics.public_api'), 'ok' => $report['services']['api']['ok'], 'detail' => __('diagnostics.route_registered', ['route' => $report['services']['api']['version_route']])],
            ['label' => __('diagnostics.websocket_bridge'), 'ok' => $report['services']['websocket_bridge']['ok'], 'detail' => \App\Support\Label::text($report['services']['websocket_bridge']['note'])],
            ['label' => __('diagnostics.scheduler'), 'ok' => $report['scheduler']['ok'], 'detail' => $report['scheduler']['ok'] ? __('diagnostics.heartbeat_is_fresh') : __('diagnostics.no_fresh_scheduler_heartbeat')],
            ['label' => __('diagnostics.id_server_link'), 'ok' => $report['server_link']['ok'], 'detail' => \App\Services\ServerLink::translatedNote($report['server_link'])
                .($report['server_link']['agent'] ? ' ('.$report['server_link']['agent'].')' : '')
                .($report['server_link']['mode'] === 'approved' ? ' '.__('diagnostics.server_access_approved_only') : '')],
            ['label' => __('diagnostics.smtp'), 'ok' => $report['smtp']['configured'] ? $report['smtp']['healthy'] : null, 'detail' => \App\Support\Label::text($report['smtp']['note'])],
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
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}">{{ __('diagnostics.status_'.$status) }}</span>
                        </div>
                        <p class="text-muted fs-13 mb-0 mt-2">{{ $check['detail'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mt-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ __('diagnostics.fleet_summary') }}</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                @foreach (['total' => __('common.devices'), 'online' => __('common.online'), 'offline' => __('devices.offline'), 'silent_over_24h' => __('diagnostics.silent_24h'), 'pending' => __('common.pending'), 'possible_duplicates' => __('devices.possible_duplicates')] as $key => $label)
                    <div class="col-6 col-lg">
                        <div class="text-muted fs-13">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">
                            @if ($key === 'possible_duplicates' && $report['fleet'][$key] > 0)
                                <a href="{{ route('devices', ['status' => 'duplicates']) }}" title="{{ __('diagnostics.list_them_on_devices_page') }}">{{ $report['fleet'][$key] }}</a>
                            @else
                                {{ $report['fleet'][$key] }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="text-muted fs-13">
                {{ __('diagnostics.version_status_is_relative_to') }}
            </p>

            @if ($report['fleet']['versions'])
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>{{ __('common.client_version') }}</th><th>{{ __('common.devices') }}</th><th>{{ __('common.status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($report['fleet']['versions'] as $version)
                                <tr>
                                    <td class="rd-mono">{{ $version['version'] }}</td>
                                    <td>{{ $version['count'] }}</td>
                                    <td>{{ \App\Support\Label::text($version['status']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">{{ __('diagnostics.no_client_versions_have_been') }}</p>
            @endif
        </div>
    </div>
@endsection
