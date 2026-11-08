@switch ($state)
    @case('active')
        <span class="badge bg-success-subtle text-success">{{ __('monitoring.state_active') }}</span>
        @break
    @case('revoked')
        <span class="badge bg-danger-subtle text-danger">{{ __('monitoring.state_revoked') }}</span>
        @break
    @case('expired')
        <span class="badge bg-secondary-subtle text-secondary">{{ __('monitoring.state_expired') }}</span>
        @break
    @default
        <span class="badge bg-secondary-subtle text-secondary">{{ __('monitoring.state_used') }}</span>
@endswitch
