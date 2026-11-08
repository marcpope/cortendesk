{{-- Recent commands for one agent. --}}
@php
    $cmdTones = [
        'queued' => 'bg-secondary-subtle text-secondary', 'sent' => 'bg-primary-subtle text-primary', 'running' => 'bg-primary-subtle text-primary',
        'succeeded' => 'bg-success-subtle text-success', 'failed' => 'bg-danger-subtle text-danger',
        'expired' => 'bg-warning-subtle text-warning', 'cancelled' => 'bg-secondary-subtle text-secondary',
    ];
    $cmdStatus = [
        'queued' => __('monitoring.cmd_status_queued'), 'sent' => __('monitoring.cmd_status_sent'), 'running' => __('monitoring.cmd_status_running'),
        'succeeded' => __('monitoring.cmd_status_succeeded'), 'failed' => __('monitoring.cmd_status_failed'),
        'expired' => __('monitoring.cmd_status_expired'), 'cancelled' => __('monitoring.cmd_status_cancelled'),
    ];
@endphp
<div class="card">
    <div class="card-header"><h5 class="card-title mb-0">{{ __('monitoring.commands') }}</h5></div>
    <div class="card-body py-2">
        @foreach ($commands as $cmd)
            <div class="rd-mon-cmd" wire:key="cmd-{{ $cmd->public_id }}">
                <div class="rd-mon-cmd-row">
                    <span class="badge flex-shrink-0 {{ $cmdTones[$cmd->status] ?? 'bg-secondary-subtle text-secondary' }}">
                        @if (in_array($cmd->status, ['sent', 'running'], true))<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>@endif{{ $cmdStatus[$cmd->status] ?? $cmd->status }}
                    </span>
                    <span class="min-width-0 flex-grow-1">
                        <span class="fw-medium">{{ $cmd->summary() }}</span>
                        <span class="text-muted fs-12 d-block d-sm-inline ms-sm-2" title="{{ $cmd->created_at }}">
                            {{ $cmd->created_by_name === 'system' ? __('monitoring.cmd_by_system') : __('monitoring.cmd_by', ['user' => $cmd->created_by_name ?? '?']) }}, {{ $cmd->created_at?->diffForHumans() }}
                        </span>
                    </span>
                    @if ($cmd->output || $cmd->error)
                        <button type="button" class="btn btn-link btn-sm p-0" wire:click="toggleCommand('{{ $cmd->public_id }}')" aria-expanded="{{ $openCommand === $cmd->public_id ? 'true' : 'false' }}">{{ __('monitoring.cmd_output') }}</button>
                    @endif
                    @if ($cmd->status === 'queued' && $canCommand)
                        <button type="button" class="btn btn-link btn-sm p-0 text-danger" wire:click="cancelCommand('{{ $cmd->public_id }}')">{{ __('common.cancel') }}</button>
                    @endif
                </div>
                @if ($cmd->error && $openCommand !== $cmd->public_id)
                    <div class="fs-12 text-danger text-truncate">{{ $cmd->error }}</div>
                @endif
                @if ($openCommand === $cmd->public_id)
                    @if ($cmd->error)<div class="fs-12 text-danger mb-1">{{ $cmd->error }}</div>@endif
                    @if ($cmd->output)<pre class="rd-mon-cmd-output">{{ $cmd->output }}</pre>@endif
                @endif
            </div>
        @endforeach
    </div>
</div>
