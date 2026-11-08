/**
 * Monitoring charts for one agent: tile sparklines and the History charts.
 * Plain script, no build step.
 *
 * The first data comes from data-series / data-sparks on .rd-mon-charts.
 * After that the Livewire component dispatches `agent-series` on every render
 * (range change or poll) and the charts redraw. On the device page the
 * Monitoring tab starts hidden, so charts wait until they have a width.
 */
(function () {
    'use strict';

    var charts = [];
    var data = null;

    function theme() {
        var css = getComputedStyle(document.documentElement);
        var token = function (name) { return css.getPropertyValue(name).trim(); };

        return {
            token: token,
            blue: token('--rd-blue'),
            purple: token('--rd-purple'),
            amber: token('--rd-amber'),
            teal: token('--rd-teal'),
            muted: token('--rd-ink-muted'),
            grid: token('--rd-border'),
            mode: document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark'
        };
    }

    function rate(value) {
        if (value === null || value === undefined) {
            return '';
        }
        var units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        var i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }

        return value.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function base(t, height) {
        return {
            chart: { type: 'line', height: height, fontFamily: 'inherit', foreColor: t.muted, toolbar: { show: false }, background: 'transparent', animations: { enabled: false }, zoom: { enabled: false } },
            grid: { borderColor: t.grid, strokeDashArray: 3 },
            tooltip: { theme: t.mode, x: { format: 'MMM d, HH:mm' } },
            legend: { labels: { colors: t.muted }, markers: { size: 5 }, position: 'top', horizontalAlign: 'left' },
            dataLabels: { enabled: false },
            stroke: { width: 2, curve: 'straight' },
            xaxis: { type: 'datetime', labels: { datetimeUTC: false }, axisBorder: { color: t.grid }, axisTicks: { show: false } },
            noData: { text: '' }
        };
    }

    function sparkline(el, values, color) {
        return new ApexCharts(el, {
            chart: { type: 'line', height: 40, width: '100%', sparkline: { enabled: true }, animations: { enabled: false } },
            series: [{ data: values }],
            colors: [color],
            stroke: { width: 2, curve: 'smooth' },
            tooltip: { enabled: false }
        });
    }

    function root() {
        return document.querySelector('.rd-mon-charts');
    }

    function render() {
        var el = root();
        if (!el || !data || typeof ApexCharts === 'undefined') {
            return;
        }

        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        // Hidden (a device page tab not yet opened): draw once it is shown.
        if (el.offsetWidth === 0) {
            return;
        }

        var t = theme();
        var labels = JSON.parse(el.dataset.labels || '{}');
        var series = data.series || {};
        var sparks = data.sparks || {};

        document.querySelectorAll('.rd-mon [data-spark]').forEach(function (box) {
            var values = sparks[box.dataset.spark] || [];
            if (values.length > 1) {
                charts.push(sparkline(box, values, t.token(box.dataset.tone) || t.blue));
            }
        });

        charts.push(new ApexCharts(el.querySelector('[data-chart="usage"]'), Object.assign(base(t, 260), {
            series: [
                { name: labels.cpu, data: series.cpu || [] },
                { name: labels.memory, data: series.memory || [] },
                { name: labels.disk, data: series.disk || [] }
            ],
            colors: [t.blue, t.purple, t.amber],
            yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: function (v) { return v === null ? '' : Math.round(v) + '%'; } } }
        })));

        charts.push(new ApexCharts(el.querySelector('[data-chart="network"]'), Object.assign(base(t, 260), {
            series: [
                { name: labels.rx, data: series.rx || [] },
                { name: labels.tx, data: series.tx || [] }
            ],
            colors: [t.teal, t.blue],
            yaxis: { min: 0, tickAmount: 4, labels: { formatter: rate } }
        })));

        charts.forEach(function (c) { c.render(); });
    }

    var watched = null;

    function start() {
        var el = root();
        if (!el) {
            return;
        }
        data = { series: JSON.parse(el.dataset.series || '{}'), sparks: JSON.parse(el.dataset.sparks || '{}') };
        render();

        if (watched !== el && typeof ResizeObserver !== 'undefined') {
            watched = el;
            var wasHidden = el.offsetWidth === 0;
            new ResizeObserver(function () {
                var hidden = el.offsetWidth === 0;
                if (wasHidden && !hidden) {
                    render();
                }
                wasHidden = hidden;
            }).observe(el);
        }
    }

    document.addEventListener('livewire:init', function () {
        window.Livewire.on('agent-series', function (payload) {
            data = { series: payload.series, sparks: payload.sparks };
            render();
        });
    });

    new MutationObserver(render).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
