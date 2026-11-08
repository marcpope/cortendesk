{{-- Bulk commands on the Machines tab. --}}
<div class="rd-toolbar d-flex flex-wrap gap-2 align-items-center">
    @if ($selected !== [])
        <span class="fs-13 fw-semibold">{{ __('common.count_selected', ['count' => count($selected)]) }}</span>
        @if ($bulkAction === '')
            <button type="button" class="btn btn-sm btn-light" wire:click="openBulk('rustdesk.upgrade')"><i class="ri-download-cloud-2-line me-1"></i>{{ __('monitoring.action_rustdesk') }}</button>
            <button type="button" class="btn btn-sm btn-light" wire:click="openBulk('os.update')"><i class="ri-shield-check-line me-1"></i>{{ __('monitoring.action_os_update') }}</button>
            <button type="button" class="btn btn-sm btn-light" wire:click="openBulk('reboot')"><i class="ri-restart-line me-1"></i>{{ __('monitoring.action_reboot') }}</button>
        @else
            <form class="d-flex flex-wrap gap-2 align-items-center" wire:submit="bulkQueue">
                @if ($bulkAction === 'rustdesk.upgrade')
                    @if ($releaseVersions)
                        <label class="fs-13" for="bulkVersion">{{ __('monitoring.action_rustdesk') }}</label>
                        <select id="bulkVersion" class="form-select form-select-sm w-auto" wire:model="bulkVersion">
                            @foreach ($releaseVersions as $i => $v)
                                <option value="{{ $v }}">{{ $i === 0 ? __('monitoring.version_latest', ['version' => $v]) : $v }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="fs-13 text-warning">{{ __('monitoring.rustdesk_releases_unavailable') }}</span>
                    @endif
                @elseif ($bulkAction === 'os.update')
                    <span class="fs-13">{{ __('monitoring.action_os_update') }}</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="bulkReboot" wire:model="bulkReboot">
                        <label class="form-check-label fs-13" for="bulkReboot">{{ __('monitoring.os_update_reboot') }}</label>
                    </div>
                @else
                    <label class="fs-13" for="bulkDelay">{{ __('monitoring.action_reboot') }}</label>
                    <select id="bulkDelay" class="form-select form-select-sm w-auto" wire:model.number="bulkDelay">
                        <option value="0">{{ __('monitoring.reboot_now') }}</option>
                        <option value="300">{{ __('monitoring.reboot_in_minutes', ['minutes' => 5]) }}</option>
                        <option value="900">{{ __('monitoring.reboot_in_minutes', ['minutes' => 15]) }}</option>
                        <option value="3600">{{ __('monitoring.reboot_in_hour') }}</option>
                    </select>
                @endif
                @if ($bulkAction !== 'rustdesk.upgrade' || $releaseVersions)
                    <button type="submit" class="btn btn-sm btn-primary" wire:confirm="{{ __('monitoring.bulk_confirm', ['count' => count($selected)]) }}"><i class="ri-send-plane-line me-1"></i>{{ __('monitoring.queue_command') }}</button>
                @endif
                <button type="button" class="btn btn-sm btn-light" wire:click="$set('bulkAction', '')">{{ __('common.cancel') }}</button>
            </form>
        @endif
        <a href="javascript:void(0);" class="fs-13 text-muted" wire:click="clearSelection">{{ __('devices.clear') }}</a>
        @error('bulk') <span class="fs-13 text-danger">{{ $message }}</span> @enderror
    @endif
    @if ($bulkResult !== '')
        <span class="fs-13 text-success"><i class="ri-check-line me-1"></i>{{ $bulkResult }}</span>
    @endif
</div>
