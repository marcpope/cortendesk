{{-- One end of a session on the device overview's connection map: the peer's
     platform icon when the console knows the device, else $fallback. --}}
@php $tag = $n['id'] ? 'a' : 'div'; @endphp
<{{ $tag }} class="rd-flow-node" @if ($n['id']) href="{{ route('devices.show', $n['id']) }}" @endif title="{{ $n['label'] }}">
    @if ($n['platform'] !== '' && $n['platform'] !== 'unknown')
        <x-platform-icon :platform="$n['platform']" size="fs-20"/>
    @else
        <i class="{{ $fallback }} fs-20"></i>
    @endif
    <span class="min-width-0">
        <span class="rd-cell-title text-truncate">{{ $n['label'] }}</span>
        <span class="rd-cell-sub">{{ trans_choice('devices.count_sessions', $n['count']) }}</span>
    </span>
</{{ $tag }}>
