@props(['type', 'icon' => false])

@php
    // Session type badge. Colours match the Overview connections chart
    // (issue #143): remote control blue, file transfer orange, the rest
    // (port forward, camera, terminal) teal, the chart's "Other".
    $type = (int) $type;
    $class = match ($type) {
        0 => 'bg-info-subtle text-info',
        1 => 'bg-primary-subtle text-primary',
        default => 'rd-badge-teal',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge $class"]) }}>@if ($icon)<i class="{{ \App\Models\AuditConnection::typeIcon($type) }} me-1"></i>@endif{{ \App\Models\AuditConnection::typeLabel($type) }}</span>
