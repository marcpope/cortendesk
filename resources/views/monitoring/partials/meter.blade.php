{{-- A usage bar: green, amber from 75%, red from 90% (Monitoring::HIGH_PERCENT). --}}
@if ($percent === null)
    <span class="text-muted">—</span>
@else
    @php
        $tone = $percent >= \App\Support\Monitoring::HIGH_PERCENT ? 'rd-tone-red' : ($percent >= 75 ? 'rd-tone-amber' : 'rd-tone-green');
    @endphp
    <div class="rd-meter {{ $tone }}" @isset($title) title="{{ $title }}" @endisset>
        <div class="rd-meter-track"><div class="rd-meter-fill" style="width: {{ max(0, min(100, $percent)) }}%"></div></div>
        <span class="rd-meter-value">{{ number_format($percent, 0) }}%</span>
    </div>
@endif
