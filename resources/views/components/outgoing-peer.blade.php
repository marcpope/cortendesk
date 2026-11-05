@props(['peer'])
{{-- A session this device started: badge, then the device it reached ([label, device id or null]). --}}
<span class="badge rd-badge-purple me-1" title="{{ __('devices.outgoing_help') }}">{{ __('devices.outgoing') }}</span>@if ($peer[1])<a href="{{ route('devices.show', $peer[1]) }}">{{ $peer[0] }}</a>@else{{ $peer[0] }}@endif
