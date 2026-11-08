@if (! $agent->enabled)
    <span class="badge bg-warning-subtle text-warning flex-shrink-0">{{ __('monitoring.disabled') }}</span>
@elseif ($agent->isOnline())
    <span class="badge bg-success-subtle text-success flex-shrink-0"><i class="rd-dot"></i>{{ __('common.online') }}</span>
@else
    <span class="badge bg-secondary-subtle text-secondary flex-shrink-0"><i class="rd-dot"></i>{{ __('devices.offline') }}</span>
@endif
