{{-- Remote actions menu on the agent page and the device Monitoring tab. --}}
@if ($actions || $agentUpdate)
    @php
        $actionIcons = ['rustdesk.upgrade' => 'ri-download-cloud-2-line', 'os.update' => 'ri-shield-check-line', 'reboot' => 'ri-restart-line'];
        $actionLabels = ['rustdesk.upgrade' => __('monitoring.action_rustdesk'), 'os.update' => __('monitoring.action_os_update'), 'reboot' => __('monitoring.action_reboot')];
    @endphp
    <div class="dropdown">
        <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-terminal-box-line me-1"></i>{{ __('monitoring.remote_actions') }}</button>
        <ul class="dropdown-menu dropdown-menu-end">
            @foreach ($actions as $type)
                <li><button type="button" class="dropdown-item" wire:click="openAction('{{ $type }}')"><i class="{{ $actionIcons[$type] }} me-1"></i>{{ $actionLabels[$type] }}…</button></li>
            @endforeach
            @if ($agentUpdate)
                @if ($actions)<li><hr class="dropdown-divider"></li>@endif
                <li><button type="button" class="dropdown-item" wire:click="updateAgent" wire:confirm="{{ __('monitoring.action_update_agent_confirm', ['version' => $agentUpdate, 'name' => $agent->displayName()]) }}"><i class="ri-arrow-up-circle-line me-1"></i>{{ __('monitoring.action_update_agent', ['version' => $agentUpdate]) }}</button></li>
            @endif
        </ul>
    </div>
@endif
