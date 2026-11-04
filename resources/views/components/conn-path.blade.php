@props(['conn', 'hideUnknown' => false])

@php
    // How the connection got through (issue #130). See AuditConnection::transport().
    $transport = $conn->transport();
    $relay = $conn->relaySession;
    [$label, $class, $icon, $hint] = match ($transport) {
        'relay' => [__('logs.path_relay'), 'bg-warning-subtle text-warning', 'ri-server-line',
            $relay ? __('logs.path_relay_hint', ['size' => \Illuminate\Support\Number::fileSize($relay->bytes, maxPrecision: 1)]) : __('logs.path_relay_requested_hint')],
        'direct' => [__('logs.path_direct'), 'bg-success-subtle text-success', 'ri-arrow-left-right-line', __('logs.path_direct_hint')],
        'direct-webrtc' => [__('logs.path_direct_webrtc'), 'bg-success-subtle text-success', 'ri-arrow-left-right-line', __('logs.path_direct_webrtc_hint')],
        'lan' => [__('logs.path_lan'), 'bg-info-subtle text-info', 'ri-router-line', __('logs.path_lan_hint')],
        default => [null, null, null, __('logs.path_unknown_hint')],
    };
@endphp

@if ($label)
    <span {{ $attributes->merge(['class' => "badge $class"]) }} title="{{ $hint }}"><i class="{{ $icon }} me-1"></i>{{ $label }}</span>
@elseif (! $hideUnknown)
    <span {{ $attributes->merge(['class' => 'text-muted']) }} title="{{ $hint }}">—</span>
@endif
