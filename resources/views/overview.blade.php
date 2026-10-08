@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    @if (\App\Livewire\SetupWizard::shouldPrompt(auth()->user()))
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
            <i class="ri-guide-line fs-20"></i>
            <div class="flex-grow-1"><strong>Connect your first device.</strong> The setup guide provides the exact RustDesk network values and confirms the first heartbeat.</div>
            <a href="{{ route('setup') }}" class="btn btn-sm btn-primary">Open setup guide</a>
        </div>
    @endif
    {{-- Only shown to someone who can actually fix it. Everything listed here
         is a feature that exists and silently cannot run without a relay. --}}
    @if (auth()->user()?->consoleAllows('setting', 'rw') && ! app(\App\Services\MailSettings::class)->isEnabled())
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="ri-mail-close-line fs-20"></i>
            <div class="flex-grow-1">
                <strong>Email is not configured.</strong>
                User invitations cannot be sent, and emailed sign-in verification codes cannot
                be delivered, so anyone locked out has to be recovered by an administrator by hand.
                <a href="{{ route('settings', ['tab' => 'email']) }}" class="alert-link">Set up SMTP</a>.
            </div>
        </div>
    @endif

    @livewire(App\Livewire\LiveStats::class)

    <div class="row rd-row-equal">
        @if ($connectionSeries !== null)
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <div class="min-width-0">
                        <h5 class="card-title">Connections</h5>
                        <div class="rd-card-sub">Sessions per day by type · last {{ $range }} days</div>
                    </div>
                    <div class="rd-card-actions">
                        <div class="rd-seg" role="group" aria-label="Chart range">
                            @foreach ($ranges as $r)
                                <a href="{{ route('overview', ['range' => $r]) }}"
                                   class="rd-seg-item {{ $r === $range ? 'active' : '' }}"
                                   @if ($r === $range) aria-current="true" @endif>{{ $r }}D</a>
                            @endforeach
                        </div>
                        <a href="{{ route('logs.connections') }}" class="fs-13">View log</a>
                    </div>
                </div>
                <div class="card-body">
                    <div id="chart-connections" style="min-height: 300px;"></div>
                </div>
            </div>
        </div>
        @endif

        <div class="{{ $connectionSeries !== null ? 'col-xl-4' : 'col-12' }}">
            @php
                $platformTotal = array_sum($platformMix['values']);
                // Same order as the chart series palette in the script below.
                $platformTones = ['rd-tone-blue', 'rd-tone-accent', 'rd-tone-teal', 'rd-tone-purple', 'rd-tone-amber'];
            @endphp
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Device Platforms</h5>
                    <div class="rd-card-sub">Share of {{ $platformTotal }} {{ Str::plural('device', $platformTotal) }}</div>
                </div>
                <div class="card-body">
                    @if ($platformTotal > 0)
                        <div class="rd-donut">
                            <div id="chart-platforms" class="rd-donut-chart"></div>
                            <ul class="rd-legend rd-donut-legend">
                                @foreach ($platformMix['labels'] as $i => $label)
                                    <li class="rd-legend-item {{ $platformTones[$i % count($platformTones)] }}">
                                        <span class="rd-legend-swatch"></span>
                                        <span class="rd-legend-label">{{ $label }}</span>
                                        <span class="rd-legend-count">{{ $platformMix['values'][$i] }}</span>
                                        <span class="rd-legend-pct">{{ round($platformMix['values'][$i] / $platformTotal * 100) }}%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        {{-- The chart container still ships so the script has a stable
                             hook; it is simply not rendered into while empty. --}}
                        <div id="chart-platforms" class="d-none"></div>
                        <div class="rd-empty">
                            <div class="rd-empty-icon"><i class="ri-computer-line"></i></div>
                            <p class="rd-empty-title">No devices yet</p>
                            <p class="rd-empty-text">The platform mix appears once a RustDesk client checks in.</p>
                            <a href="{{ route('devices') }}" class="btn btn-sm btn-outline-light">Go to devices</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @php
        // Every audit-derived panel is permission-gated. The remaining fleet
        // card widens when Logs access is absent.
        $hasAudit = $connectionSeries !== null;
        $hasAlerts = $recentAlarms !== null;
    @endphp
    <div class="row rd-row-equal">
        @if ($hasAudit)
        <div class="{{ $hasAlerts ? 'col-xl-5' : 'col-xl-7' }}">
            @livewire(App\Livewire\ActiveSessions::class)
        </div>
        @endif

        <div class="{{ ! $hasAudit ? 'col-12' : ($hasAlerts ? 'col-xl-4' : 'col-xl-5') }}">
            @php
                $versionTotal = array_sum($versionCounts['values']);
                $versionMax = $versionCounts['values'] ? max($versionCounts['values']) : 0;
            @endphp
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <div class="min-width-0">
                        <h5 class="card-title">Client Versions</h5>
                        <div class="rd-card-sub">{{ $versionTotal > 0 ? 'Top '.count($versionCounts['labels']).' in use' : 'Reported on each heartbeat' }}</div>
                    </div>
                    <div class="rd-card-actions">
                        <a href="{{ route('devices') }}" class="fs-13">All devices</a>
                    </div>
                </div>
                <div class="card-body">
                    <div id="chart-versions">
                        @if ($versionTotal > 0)
                            <ul class="rd-barlist">
                                @foreach ($versionCounts['labels'] as $i => $label)
                                    @php $count = $versionCounts['values'][$i]; @endphp
                                    <li class="rd-barlist-item rd-tone-blue">
                                        <div class="rd-barlist-head">
                                            <span class="rd-barlist-label">{{ $label }}</span>
                                            <span class="rd-barlist-count">{{ $count }}</span>
                                            <span class="rd-barlist-pct">{{ round($count / $versionTotal * 100) }}%</span>
                                        </div>
                                        <div class="rd-barlist-track">
                                            <div class="rd-barlist-fill" style="width: {{ $versionMax > 0 ? round($count / $versionMax * 100, 1) : 0 }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-download-2-line"></i></div>
                                <p class="rd-empty-title">No client versions reported</p>
                                <p class="rd-empty-text">Clients report their version on their first heartbeat.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>

        @if ($hasAlerts)
            <div class="col-xl-3">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <div class="min-width-0">
                            <h5 class="card-title">Alerts</h5>
                            <div class="rd-card-sub">Last 24 hours</div>
                        </div>
                        <div class="rd-card-actions">
                            <a href="{{ route('logs.alarms') }}" class="fs-13">Alarm log</a>
                        </div>
                    </div>
                    <div class="card-body">
                        @if (count($recentAlarms) > 0)
                            <ul class="rd-feed">
                                @foreach ($recentAlarms as $alarm)
                                    @php
                                        $tone = match ($alarm->typeSeverity()) {
                                            'danger' => 'rd-tone-red',
                                            'warning' => 'rd-tone-amber',
                                            default => 'rd-tone-muted',
                                        };
                                    @endphp
                                    <li class="rd-feed-item">
                                        <span class="rd-avatar {{ $tone }}"><i class="ri-alarm-warning-line"></i></span>
                                        <div class="min-width-0">
                                            <span class="rd-cell-title text-truncate">{{ $alarm->typeLabel() }}</span>
                                            <span class="rd-cell-sub">
                                                {{ $alarm->rustdesk_id === \App\Models\AlarmLog::CONSOLE_SOURCE ? 'Console' : $alarm->rustdesk_id }}
                                                · <span title="{{ $alarm->created_at }}">{{ $alarm->created_at?->diffForHumans(short: true) }}</span>
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-shield-check-line"></i></div>
                                <p class="rd-empty-title">Nothing to report</p>
                                <p class="rd-empty-text">No alarms have been raised in the last 24 hours.</p>
                                <a href="{{ route('logs.alarms') }}" class="btn btn-sm btn-outline-light">Open the alarm log</a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
<script>
(function () {
    "use strict";

    var connectionData = @json($connectionSeries);
    var platformData = @json($platformMix);
    var hasPlatformData = platformData.values.length > 0;

    var charts = [];

    // The palette lives in cortendesk.css as custom properties; ApexCharts
    // cannot read those itself, so they are resolved off the root element on
    // every render — which is also how the light/dark flip stays in sync.
    // Series order matches the design system: blue, accent, teal, purple, amber.
    function theme() {
        var css = getComputedStyle(document.documentElement);
        var token = function (name) { return css.getPropertyValue(name).trim(); };

        return {
            series: ["--rd-blue", "--rd-accent-ink", "--rd-teal", "--rd-purple", "--rd-amber"].map(token),
            ink: token("--rd-ink"),
            muted: token("--rd-ink-muted"),
            grid: token("--rd-border"),
            surface: token("--rd-surface"),
            mode: document.documentElement.getAttribute("data-bs-theme") === "light" ? "light" : "dark"
        };
    }

    function baseOptions(t) {
        return {
            chart: { fontFamily: "inherit", foreColor: t.muted, toolbar: { show: false }, background: "transparent" },
            grid: { borderColor: t.grid, strokeDashArray: 3 },
            tooltip: { theme: t.mode },
            legend: { labels: { colors: t.muted }, markers: { size: 5 } },
            dataLabels: { enabled: false }
        };
    }

    function render() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        var t = theme();

        if (connectionData) {
            // Daily connections, stacked by type. Bars thin out as the range
            // grows so 90 days stays readable; a hairline gap separates them.
            var days = connectionData.labels.length;
            var connEl = document.querySelector("#chart-connections");
            // Date labels need ~62px each. Ask for only as many ticks as the
            // card is actually wide enough for.
            var ticks = Math.max(3, Math.floor((connEl.clientWidth || 600) / 62));

            var conn = new ApexCharts(connEl, Object.assign(baseOptions(t), {
                chart: Object.assign(baseOptions(t).chart, { type: "bar", height: 300, stacked: true }),
                series: connectionData.series,
                colors: t.series.slice(0, 3),
                plotOptions: { bar: {
                    columnWidth: days > 60 ? "80%" : (days > 20 ? "62%" : "45%"),
                    borderRadius: 3,
                    borderRadiusApplication: "end",
                    borderRadiusWhenStacked: "last"
                } },
                stroke: { show: true, width: 2, colors: [t.surface] },
                xaxis: {
                    categories: connectionData.labels,
                    tickAmount: Math.min(days, ticks),
                    axisBorder: { color: t.grid },
                    axisTicks: { show: false },
                    labels: { rotate: 0, hideOverlappingLabels: true }
                },
                yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
                legend: Object.assign(baseOptions(t).legend, { position: "bottom", horizontalAlign: "left", offsetY: 4 })
            }));
            charts.push(conn);
        }

        // Platform donut: wide hole carrying the total; identity is carried by
        // the .rd-legend list beside it, so Apex's own legend stays off.
        if (hasPlatformData) {
            var plat = new ApexCharts(document.querySelector("#chart-platforms"), Object.assign(baseOptions(t), {
                chart: Object.assign(baseOptions(t).chart, { type: "donut", height: 240 }),
                series: platformData.values,
                labels: platformData.labels,
                colors: t.series.slice(0, platformData.labels.length),
                stroke: { width: 2, colors: [t.surface] },
                plotOptions: { pie: { donut: { size: "78%", labels: { show: true,
                    name: { fontSize: "12px", color: t.muted, offsetY: 20 },
                    value: { fontSize: "26px", fontWeight: 600, color: t.ink, offsetY: -14 },
                    total: { show: true, label: "Devices", color: t.muted, fontSize: "12px", formatter: function (w) {
                        return w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                    } }
                } } } },
                legend: { show: false }
            }));
            charts.push(plat);
        }

        charts.forEach(function (c) { c.render(); });
    }

    render();

    // The tick budget above is width-derived, so a resize (or a phone turning
    // sideways) has to recompute it. Debounced — Apex resizes the SVG itself.
    var resizeTimer;
    window.addEventListener("resize", function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 250);
    });

    // Re-render with the right mode colors when the topbar theme toggle flips.
    new MutationObserver(function (muts) {
        muts.forEach(function (m) { if (m.attributeName === "data-bs-theme") render(); });
    }).observe(document.documentElement, { attributes: true });
})();
</script>
@endpush
