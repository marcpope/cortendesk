{{-- The form for the remote action picked from the menu. --}}
@if ($notice)
    <div class="alert alert-{{ $notice['tone'] }} py-2 fs-13 d-flex align-items-center gap-2" role="status">
        <span class="flex-grow-1">{{ $notice['text'] }}</span>
        <button type="button" class="btn-close btn-sm" wire:click="$set('notice', null)" aria-label="{{ __('common.close') }}"></button>
    </div>
@endif
@if ($action)
    <div class="card rd-mon-action mb-3">
        <div class="card-header d-flex align-items-center">
            <h5 class="card-title mb-0 flex-grow-1">
                @switch($action)
                    @case('rustdesk.upgrade') {{ __('monitoring.action_rustdesk') }} @break
                    @case('os.update') {{ __('monitoring.action_os_update') }} @break
                    @case('reboot') {{ __('monitoring.action_reboot') }} @break
                @endswitch
            </h5>
            <button type="button" class="btn-close" wire:click="closeAction" aria-label="{{ __('common.close') }}"></button>
        </div>
        <form class="card-body" wire:submit="queueAction">
            @if ($action === 'rustdesk.upgrade')
                @if ($rustdeskVersions)
                    <label class="form-label" for="rustdeskVersion">{{ __('monitoring.rustdesk_version') }}</label>
                    <select id="rustdeskVersion" class="form-select mb-2" style="max-width: 240px;" wire:model="rustdeskVersion">
                        @foreach ($rustdeskVersions as $i => $v)
                            <option value="{{ $v }}">{{ $i === 0 ? __('monitoring.version_latest', ['version' => $v]) : $v }}</option>
                        @endforeach
                    </select>
                    <div class="form-text mb-3">
                        @if ($agent->latest['rustdesk']['version'] ?? null){{ __('monitoring.rustdesk_installed_now', ['version' => $agent->latest['rustdesk']['version']]) }} @endif{{ __('monitoring.rustdesk_upgrade_note') }}
                    </div>
                @else
                    <div class="alert alert-warning py-2 fs-13">{{ __('monitoring.rustdesk_releases_unavailable') }}</div>
                @endif
            @elseif ($action === 'os.update')
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="updateReboot" wire:model="updateReboot">
                    <label class="form-check-label" for="updateReboot">{{ __('monitoring.os_update_reboot') }}</label>
                </div>
                <div class="form-text mb-3">{{ __('monitoring.os_update_note') }}</div>
            @elseif ($action === 'reboot')
                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <label class="form-label" for="rebootDelay">{{ __('monitoring.reboot_when') }}</label>
                        <select id="rebootDelay" class="form-select" wire:model.number="rebootDelay">
                            <option value="0">{{ __('monitoring.reboot_now') }}</option>
                            <option value="300">{{ __('monitoring.reboot_in_minutes', ['minutes' => 5]) }}</option>
                            <option value="900">{{ __('monitoring.reboot_in_minutes', ['minutes' => 15]) }}</option>
                            <option value="3600">{{ __('monitoring.reboot_in_hour') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label" for="rebootMessage">{{ __('monitoring.reboot_message') }}</label>
                        <input type="text" id="rebootMessage" class="form-control" maxlength="200" wire:model="rebootMessage"
                               placeholder="{{ __('monitoring.reboot_message_placeholder') }}" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
                    </div>
                </div>
            @endif
            @error('action') <div class="alert alert-danger py-2 fs-13">{{ $message }}</div> @enderror
            @if ($action !== 'rustdesk.upgrade' || $rustdeskVersions)
                <button type="submit" class="btn btn-primary btn-sm" wire:confirm="{{ __('monitoring.queue_confirm', ['name' => $agent->displayName()]) }}">
                    <i class="ri-send-plane-line me-1"></i>{{ __('monitoring.queue_command') }}
                </button>
            @endif
            <button type="button" class="btn btn-light btn-sm" wire:click="closeAction">{{ __('common.cancel') }}</button>
        </form>
    </div>
@endif
