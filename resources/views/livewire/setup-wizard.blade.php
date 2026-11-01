<div wire:poll.10s="refreshDeviceStatus">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1">{{ __('setup.set_up_your_first_rustdesk') }}</h4>
            <p class="text-muted mb-0">{{ __('setup.two_steps_configure_client_then') }}</p>
        </div>
        @if (auth()->user()?->consoleAllows('setting', 'rw'))
            <button type="button" class="btn btn-sm btn-outline-light" wire:click="dismiss">{{ __('setup.do_this_later') }}</button>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('setup.1_point_client_at_this') }}</h5></div>
                <div class="card-body">
                    {{-- Settings → Client Setup already lists these four values
                         with copy buttons. Repeating them here would mean two
                         screens to keep in step. --}}
                    <p>{{ __('setup.in_rustdesk_open') }} <strong>{{ __('common.rustdesk_network_path') }}</strong> {{ __('setup.and_enter_your_id_server') }}</p>
                    @if ($idServer === '' || $relayServer === '' || $apiUrl === '' || $publicKey === '')
                        <div class="alert alert-warning">
                            {{ __('setup.one_or_more_of_those') }}
                            <a class="alert-link" href="{{ route('settings', ['tab' => 'server']) }}">{{ __('setup.complete_server_settings') }}</a> {{ __('setup.first') }}
                        </div>
                    @endif
                    <a class="btn btn-outline-light" href="{{ route('settings', ['tab' => 'client']) }}">
                        <i class="ri-file-copy-line me-1"></i>{{ __('setup.copy_them_from_client_setup') }}
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('setup.2_confirm_first_heartbeat') }}</h5></div>
                <div class="card-body">
                    @if ($deviceConnected)
                        <div class="alert alert-success mb-3"><i class="ri-check-line me-1"></i>{{ __('setup.device_has_checked_in') }}</div>
                    @else
                        <div class="alert alert-info mb-3"><i class="ri-loader-4-line me-1"></i>{{ __('setup.waiting_for_device_to_check') }}</div>
                    @endif
                    @error('device')<div class="alert alert-danger">{{ $message }}</div>@enderror
                    @if (auth()->user()?->consoleAllows('setting', 'rw'))
                        <button type="button" class="btn btn-primary" wire:click="complete" @disabled(! $deviceConnected)>
                            {{ __('setup.finish_setup') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">{{ __('setup.3_recommended_security') }}</h5></div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3"><i class="{{ $approvalEnabled ? 'ri-checkbox-circle-line text-success' : 'ri-error-warning-line text-warning' }} me-1"></i><strong>{{ __('setup.device_approval') }}</strong><br><span class="text-muted fs-13">{{ __('setup.hold_new_devices_for_review') }}</span></li>
                        <li><i class="{{ $twoFactorRequired ? 'ri-checkbox-circle-line text-success' : 'ri-error-warning-line text-warning' }} me-1"></i><strong>{{ __('setup.administrator_2fa') }}</strong><br><span class="text-muted fs-13">{{ __('setup.protect_console_access_with_authenticato') }}</span></li>
                    </ul>
                    @if (! $approvalEnabled || ! $twoFactorRequired)
                        <a href="{{ route('settings', ['tab' => 'security']) }}" class="btn btn-sm btn-outline-light mt-3">{{ __('setup.review_security_settings') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>