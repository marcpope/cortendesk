/**
 * Monitoring > Overview charts: the fleet load trend and the health donut.
 * Plain script, no build step.
 *
 * First data from data-trend / data-states on .rd-fleet-charts; then the
 * Livewire component dispatches `fleet-charts` on every render. Switching
 * tabs puts fresh chart elements on the page, so each event redraws.
 */
(function () {
    'use strict';

    var charts = [];
    var data = null;

    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function render() {
        var root = document.querySelector('.rd-fleet-charts');
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        if (!root || !data || typeof ApexCharts === 'undefined') {
            return;
        }

        var labels = JSON.parse(root.dataset.labels || '{}');
        var mode = document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark';
        var muted = token('--rd-ink-muted');
        var grid = token('--rd-border');
        var trend = data.trend || {};

        var trendEl = root.querySelector('[data-chart="trend"]');
        if (trendEl) {
            charts.push(new ApexCharts(trendEl, {
                chart: { type: 'area', height: 280, fontFamily: 'inherit', foreColor: muted, toolbar: { show: false }, background: 'transparent', animations: { enabled: false }, zoom: { enabled: false } },
                series: [
                    { name: labels.cpu, data: trend.cpu || [] },
                    { name: labels.memory, data: trend.memory || [] }
                ],
                colors: [token('--rd-blue'), token('--rd-purple')],
                fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
                stroke: { width: 2, curve: 'smooth' },
                dataLabels: { enabled: false },
                grid: { borderColor: grid, strokeDashArray: 3 },
                legend: { labels: { colors: muted }, position: 'top', horizontalAlign: 'left' },
                tooltip: { theme: mode, x: { format: 'MMM d, HH:mm' }, y: { formatter: function (v) { return v === null ? '' : Math.round(v) + '%'; } } },
                xaxis: { type: 'datetime', labels: { datetimeUTC: false }, axisBorder: { color: grid }, axisTicks: { show: false } },
                yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: function (v) { return Math.round(v) + '%'; } } },
                noData: { text: '' }
            }));
        }

        var healthEl = document.querySelector('.rd-mon-donut [data-chart="health"]');
        var states = data.states || {};
        var keys = ['healthy', 'attention', 'offline', 'disabled'];
        if (healthEl) {
            charts.push(new ApexCharts(healthEl, {
                chart: { type: 'donut', height: 220, fontFamily: 'inherit', background: 'transparent', animations: { enabled: false } },
                series: keys.map(function (k) { return states[k] || 0; }),
                labels: keys.map(function (k) { return labels[k]; }),
                colors: [token('--rd-green'), token('--rd-red'), token('--rd-ink-muted'), token('--rd-amber')],
                legend: { show: false },
                dataLabels: { enabled: false },
                stroke: { width: 2, colors: [token('--rd-surface')] },
                tooltip: { theme: mode },
                plotOptions: { pie: { donut: { size: '72%', labels: { show: true, name: { show: true, color: muted, fontSize: '13px', offsetY: -6 }, value: { show: true, color: token('--rd-ink'), fontSize: '26px', fontWeight: 600, offsetY: 6 },
                    total: { show: true, showAlways: true, label: labels.healthy, color: muted, fontSize: '13px', fontWeight: 400, formatter: function () {
                        var all = keys.reduce(function (s, k) { return s + (states[k] || 0); }, 0);
                        return all ? Math.round((states.healthy || 0) / all * 100) + '%' : '0%';
                    } } } } } }
            }));
        }

        charts.forEach(function (c) { c.render(); });
    }

    function start() {
        var root = document.querySelector('.rd-fleet-charts');
        if (!root) {
            return;
        }
        data = { trend: JSON.parse(root.dataset.trend || '{}'), states: JSON.parse(root.dataset.states || '{}') };
        render();
    }

    document.addEventListener('livewire:init', function () {
        window.Livewire.on('fleet-charts', function (payload) {
            data = { trend: payload.trend, states: payload.states };
            // After Livewire has put the tab's elements in place.
            window.setTimeout(render, 0);
        });
    });

    new MutationObserver(render).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
